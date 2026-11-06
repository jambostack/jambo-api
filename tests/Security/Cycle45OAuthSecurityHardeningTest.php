<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Controller\Auth\SocialLoginController;
use App\Controller\ProjectOAuthController;
use App\Dto\SocialUser;
use App\Entity\EndUser;
use App\Entity\Project;
use App\Entity\ProjectAuthClient;
use App\Entity\ProjectAuthRefreshToken;
use App\Entity\User;
use App\EventSubscriber\SecurityHeadersSubscriber;
use App\Repository\EndUserRepository;
use App\Repository\ProjectAuthRefreshTokenRepository;
use App\Repository\ProjectRepository;
use App\Security\SocialLoginAuthenticator;
use App\Service\EndUserJwtService;
use App\Service\ProjectAuthLockoutService;
use App\Service\ProjectOAuthService;
use App\Service\SocialLoginService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Contracts\Translation\TranslatorInterface;

class Cycle45OAuthSecurityHardeningTest extends TestCase
{
    private function makeResponseEvent(Request $request, Response $response): ResponseEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        return new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);
    }

    public function testSocialLoginAuthenticatorRejectsMissingSession(): void
    {
        $socialLogin = $this->createMock(SocialLoginService::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $authenticator = new SocialLoginAuthenticator($socialLogin, $urlGenerator);

        $request = new Request(
            query: ['code' => 'valid-code-123', 'state' => 'random-state'],
            attributes: ['_route' => 'connect_check', 'provider' => 'google']
        );

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('OAuth authentication requires an active session.');

        $authenticator->authenticate($request);
    }

    public function testSocialLoginAuthenticatorRejectsMissingStateInSession(): void
    {
        $socialLogin = $this->createMock(SocialLoginService::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $authenticator = new SocialLoginAuthenticator($socialLogin, $urlGenerator);

        $session = new Session(new MockArraySessionStorage());
        $request = new Request(
            query: ['code' => 'valid-code-123', 'state' => 'random-state'],
            attributes: ['_route' => 'connect_check', 'provider' => 'google']
        );
        $request->setSession($session);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Invalid or expired OAuth CSRF state parameter.');

        $authenticator->authenticate($request);
    }

    public function testSocialLoginAuthenticatorRejectsMismatchedState(): void
    {
        $socialLogin = $this->createMock(SocialLoginService::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $authenticator = new SocialLoginAuthenticator($socialLogin, $urlGenerator);

        $session = new Session(new MockArraySessionStorage());
        $session->set('oauth2state_google', 'legit-csrf-state');
        $request = new Request(
            query: ['code' => 'valid-code-123', 'state' => 'attacker-csrf-state'],
            attributes: ['_route' => 'connect_check', 'provider' => 'google']
        );
        $request->setSession($session);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Invalid or expired OAuth CSRF state parameter.');

        $authenticator->authenticate($request);
    }

    public function testSocialLoginAuthenticatorConsumesStateAndVerifier(): void
    {
        $socialLogin = $this->createMock(SocialLoginService::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $authenticator = new SocialLoginAuthenticator($socialLogin, $urlGenerator);

        $session = new Session(new MockArraySessionStorage());
        $session->set('oauth2state_github', 'valid-state-abc');
        $session->set('oauth2verifier_github', 'pkce-verifier-123456789012345678901234567890');

        $request = new Request(
            query: ['code' => 'gh-code-999', 'state' => 'valid-state-abc'],
            attributes: ['_route' => 'connect_check', 'provider' => 'github']
        );
        $request->setSession($session);

        $urlGenerator->method('generate')->willReturn('https://test.site/connect/github/check');

        $socialUser = new SocialUser('12345', 'dev@example.com', 'developer', null);
        $socialLogin->expects($this->once())
            ->method('getUserFromProvider')
            ->with('github', 'gh-code-999', 'https://test.site/connect/github/check', null, 'pkce-verifier-123456789012345678901234567890')
            ->willReturn($socialUser);

        $user = new User();
        $user->email = 'dev@example.com';
        $socialLogin->method('findOrCreateAdminUser')->willReturn($user);

        $passport = $authenticator->authenticate($request);

        $this->assertNotNull($passport);
        $this->assertSame($user, $passport->getUser());
        $this->assertNull($session->get('oauth2state_github'));
        $this->assertNull($session->get('oauth2verifier_github'));
    }

    public function testSocialLoginControllerInitiatesPkceFlow(): void
    {
        $socialLogin = $this->createMock(SocialLoginService::class);
        $controller = new SocialLoginController($socialLogin);

        $container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $router = $this->createMock(\Symfony\Component\Routing\RouterInterface::class);
        $router->method('generate')->willReturn('https://example.com/connect/google/check');
        $container->method('has')->willReturn(true);
        $container->expects($this->once())->method('get')->with('router')->willReturn($router);
        $controller->setContainer($container);

        $session = new Session(new MockArraySessionStorage());
        $request = new Request([], [], ['provider' => 'google']);
        $request->setSession($session);

        $socialLogin->expects($this->once())
            ->method('getAuthorizationUrlAndState')
            ->with(
                'google',
                $this->anything(),
                null,
                $this->callback(fn ($challenge) => is_string($challenge) && strlen($challenge) >= 43),
                'S256'
            )
            ->willReturn([
                'url' => 'https://accounts.google.com/o/oauth2/v2/auth?state=gen-state-123',
                'state' => 'gen-state-123',
            ]);

        $response = $controller->connect('google', $request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('https://accounts.google.com/o/oauth2/v2/auth?state=gen-state-123', $response->getTargetUrl());
        $this->assertSame('gen-state-123', $session->get('oauth2state_google'));
        $this->assertNotEmpty($session->get('oauth2verifier_google'));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-._~]{43,128}$/', $session->get('oauth2verifier_google'));
    }

    public function testSocialLoginServiceRejectsUnverifiedEmailViaReflection(): void
    {
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $userRepo = $this->createMock(\App\Repository\UserRepository::class);
        $endUserRepo = $this->createMock(EndUserRepository::class);
        $appSettingsRepo = $this->createMock(\App\Repository\AppSettingsRepository::class);
        $secretService = $this->createMock(\App\Service\WebhookSecretService::class);

        $service = new SocialLoginService($em, $userRepo, $endUserRepo, $appSettingsRepo, $secretService);

        $owner = $this->createMock(\League\OAuth2\Client\Provider\ResourceOwnerInterface::class);
        $owner->method('toArray')->willReturn([
            'sub' => 'sub-1234',
            'email' => 'unverified@jambo.test',
            'email_verified' => false,
            'name' => 'Attacker',
        ]);

        $ref = new \ReflectionClass($service);
        $method = $ref->getMethod('mapToSocialUser');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The email address from google is not verified.');

        $method->invoke($service, 'google', $owner);
    }

    public function testSecurityHeadersSubscriberSetsHardenedCspOnHtml(): void
    {
        $subscriber = new SecurityHeadersSubscriber('prod');
        $request = Request::create('https://example.com/oauth/authorize');
        $request->server->set('HTTPS', 'on');

        $response = new Response('<!DOCTYPE html><html><body>Consent Form</body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);

        $subscriber->onKernelResponse($this->makeResponseEvent($request, $response));

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self' 'unsafe-inline' 'unsafe-eval'", $csp);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline' https://fonts.googleapis.com", $csp);
        $this->assertStringContainsString("font-src 'self' https://fonts.gstatic.com data:", $csp);
        $this->assertStringContainsString("connect-src 'self' https: wss:", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
    }

    public function testSecurityHeadersSubscriberFrameAncestorsWildcardOnEmbed(): void
    {
        $subscriber = new SecurityHeadersSubscriber('prod');
        $request = Request::create('https://example.com/forms/embed/123');
        $response = new Response('<html><body>Widget</body></html>', 200, ['Content-Type' => 'text/html']);

        $subscriber->onKernelResponse($this->makeResponseEvent($request, $response));

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp);
        $this->assertStringContainsString("frame-ancestors *", $csp);
    }

    public function testSecurityHeadersSubscriberAdminSessionCookieHardening(): void
    {
        $subscriber = new SecurityHeadersSubscriber('prod');
        $request = Request::create('https://example.com/admin/settings');
        $request->server->set('HTTPS', 'on');

        $response = new Response('', 200);
        $cookie = Cookie::create('JAMBOSESSID', 'secure-session-id-token');
        $response->headers->setCookie($cookie);

        $subscriber->onKernelResponse($this->makeResponseEvent($request, $response));

        $cookies = $response->headers->getCookies();
        $this->assertCount(1, $cookies);
        $hardened = $cookies[0];

        $this->assertSame('JAMBOSESSID', $hardened->getName());
        $this->assertTrue($hardened->isHttpOnly());
        $this->assertTrue($hardened->isSecure());
        $this->assertSame('strict', strtolower((string) $hardened->getSameSite()));
    }

    public function testProjectOAuthControllerTranslatesErrorMessages(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $endUserRepo = $this->createMock(EndUserRepository::class);
        $oauthService = $this->createMock(ProjectOAuthService::class);
        $jwtService = $this->createMock(EndUserJwtService::class);
        $lockoutService = $this->createMock(ProjectAuthLockoutService::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')
            ->willReturnCallback(function (string $id, array $params = []) {
                return match ($id) {
                    'project_oauth.error.missing_client_id' => 'Paramètre client_id manquant.',
                    'project_oauth.error.project_not_found' => 'Projet introuvable.',
                    default => $id,
                };
            });

        $controller = new ProjectOAuthController(
            $projectRepo,
            $endUserRepo,
            $oauthService,
            $jwtService,
            $lockoutService,
            $hasher,
            $translator
        );

        $project = new Project();
        $projectRepo->method('findOneBy')->willReturn($project);

        $request = new Request();
        $response = $controller->authorize('proj-uuid-1', $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('invalid_request', $data['error']);
        $this->assertSame('Paramètre client_id manquant.', $data['error_description']);
    }

    public function testProjectOAuthServiceAppliesTenantJwtRefreshTtl(): void
    {
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $clientRepo = $this->createMock(\App\Repository\ProjectAuthClientRepository::class);
        $codeRepo = $this->createMock(\App\Repository\ProjectAuthAuthorizationCodeRepository::class);
        $refreshRepo = $this->createMock(ProjectAuthRefreshTokenRepository::class);
        $jwtService = $this->createMock(EndUserJwtService::class);

        $service = new ProjectOAuthService($em, $clientRepo, $codeRepo, $refreshRepo, $jwtService);

        $project = new Project();
        $project->jwtRefreshTtl = 172800; // 2 days

        $client = new ProjectAuthClient();
        $client->id = 12;
        $client->project = $project;

        $user = new EndUser($project, 'rot@test.site');

        $existingToken = new ProjectAuthRefreshToken();
        $existingToken->client = $client;
        $existingToken->endUser = $user;
        $existingToken->scopes = ['openid', 'email'];

        $refreshRepo->method('findValidToken')->willReturn($existingToken);
        $jwtService->method('createAccessToken')->willReturn('new.token.jwt');

        $persisted = null;
        $em->method('persist')->willReturnCallback(function ($entity) use (&$persisted) {
            if ($entity instanceof ProjectAuthRefreshToken) {
                $persisted = $entity;
            }
        });

        $res = $service->refreshAccessToken($client, 'valid-token-plain');

        $this->assertNotNull($persisted);
        $this->assertTrue($persisted->expiresAt > (new \DateTimeImmutable())->modify('+172700 seconds'));
        $this->assertTrue($persisted->expiresAt < (new \DateTimeImmutable())->modify('+172900 seconds'));
        $this->assertSame('new.token.jwt', $res['access_token']);
    }

    public function testI18nSymmetryAcrossAllFourLocales(): void
    {
        $locales = ['en', 'fr', 'es', 'ar'];
        $dictionaries = [];
        foreach ($locales as $l) {
            $path = __DIR__ . "/../../translations/messages.{$l}.php";
            $this->assertFileExists($path);
            $dict = require $path;
            $this->assertIsArray($dict);
            $dictionaries[$l] = $dict;
        }

        $enKeys = array_keys($dictionaries['en']);
        $this->assertGreaterThanOrEqual(2631, count($enKeys), 'Each locale must contain at least 2,631 translation keys.');

        foreach (['fr', 'es', 'ar'] as $other) {
            $diff = array_diff($enKeys, array_keys($dictionaries[$other]));
            $this->assertEmpty($diff, "Locale {$other} is missing keys: " . implode(', ', array_slice($diff, 0, 5)));
            $this->assertCount(count($enKeys), $dictionaries[$other], "Locale {$other} key count mismatch.");
        }
    }
}
