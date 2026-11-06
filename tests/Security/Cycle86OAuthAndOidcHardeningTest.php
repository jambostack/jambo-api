<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Controller\Auth\OidcController;
use App\Controller\Auth\SocialLoginController;
use App\Controller\Auth\RegisteredUserController;
use App\Controller\SecurityController;
use App\Dto\OidcConfig;
use App\Entity\AppSettings;
use App\Entity\User;
use App\Repository\AppSettingsRepository;
use App\Repository\EndUserRepository;
use App\Repository\UserRepository;
use App\Security\Oidc\OidcProviderManager;
use App\Service\FrontendRouteManifestService;
use App\Service\SocialLoginService;
use App\Service\WebhookSecretService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class Cycle86OAuthAndOidcHardeningTest extends TestCase
{
    private function createSession(): Session
    {
        return new Session(new MockArraySessionStorage());
    }

    // ─── 1. SocialLoginController::check Cancellation & Error Graceful Handling ─

    public function testSocialLoginCheckGracefullyHandlesCancellation(): void
    {
        $socialLogin = $this->createMock(SocialLoginService::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(function (string $route, array $params = []) {
            return '/login?' . http_build_query($params);
        });

        $controller = new SocialLoginController($socialLogin);
        // Inject router into container or call check directly
        $session = $this->createSession();
        $session->set('oauth2state_google', 'test-state-token');
        $session->set('oauth2verifier_google', 'test-verifier-token');

        $request = new Request(query: ['error' => 'access_denied']);
        $request->setSession($session);

        // AbstractController::redirectToRoute uses container router service
        $container = new \Symfony\Component\DependencyInjection\Container();
        $container->set('router', $urlGenerator);
        $controller->setContainer($container);

        $response = $controller->check('google', $request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('error=auth.social.cancelled', $response->getTargetUrl());
        $this->assertNull($session->get('oauth2state_google'));
        $this->assertNull($session->get('oauth2verifier_google'));
    }

    public function testSocialLoginCheckGracefullyHandlesGenericProviderError(): void
    {
        $socialLogin = $this->createMock(SocialLoginService::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(function (string $route, array $params = []) {
            return '/login?' . http_build_query($params);
        });

        $controller = new SocialLoginController($socialLogin);
        $session = $this->createSession();
        $session->set('oauth2state_github', 'gh-state');
        $session->set('oauth2verifier_github', 'gh-verifier');

        $request = new Request(query: ['error' => 'server_error']);
        $request->setSession($session);

        $container = new \Symfony\Component\DependencyInjection\Container();
        $container->set('router', $urlGenerator);
        $controller->setContainer($container);

        $response = $controller->check('github', $request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('error=auth.social.error_failed', $response->getTargetUrl());
        $this->assertNull($session->get('oauth2state_github'));
        $this->assertNull($session->get('oauth2verifier_github'));
    }

    public function testSocialLoginCheckRedirectsToLoginWhenNoCodeOrError(): void
    {
        $socialLogin = $this->createMock(SocialLoginService::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/login');

        $controller = new SocialLoginController($socialLogin);
        $request = new Request();

        $container = new \Symfony\Component\DependencyInjection\Container();
        $container->set('router', $urlGenerator);
        $controller->setContainer($container);

        $response = $controller->check('google', $request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/login', $response->getTargetUrl());
    }

    // ─── 2. SocialLoginController::connect Anti-Cache Headers & Session Init ───

    public function testSocialLoginConnectEnforcesAntiCacheHeadersAndInitializesSession(): void
    {
        $socialLogin = $this->createMock(SocialLoginService::class);
        $socialLogin->method('getAuthorizationUrlAndState')->willReturn([
            'url' => 'https://accounts.google.com/o/oauth2/auth?client_id=xyz',
            'state' => 'random-state-12345',
        ]);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://api.jambostack.site/connect/google/check');

        $controller = new SocialLoginController($socialLogin);
        $container = new \Symfony\Component\DependencyInjection\Container();
        $container->set('router', $urlGenerator);
        $controller->setContainer($container);

        $session = $this->createSession();
        $request = new Request();
        $request->setSession($session);

        $response = $controller->connect('google', $request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('https://accounts.google.com/o/oauth2/auth?client_id=xyz', $response->getTargetUrl());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', $response->headers->get('Pragma'));
        $this->assertSame('random-state-12345', $session->get('oauth2state_google'));
        $this->assertNotNull($session->get('oauth2verifier_google'));
    }

    public function testSocialLoginConnectRejectsUnknownProviderWith404(): void
    {
        $socialLogin = $this->createMock(SocialLoginService::class);
        $controller = new SocialLoginController($socialLogin);
        $container = new \Symfony\Component\DependencyInjection\Container();
        $controller->setContainer($container);

        $request = new Request();
        $this->expectException(NotFoundHttpException::class);
        $controller->connect('invalid_provider', $request);
    }

    // ─── 3. OidcController::check Cancellation & Error Graceful Handling ─────

    public function testOidcCheckGracefullyHandlesCancellation(): void
    {
        $oidcManager = $this->createMock(OidcProviderManager::class);
        $appSettingsRepo = $this->createMock(AppSettingsRepository::class);
        $secretService = $this->createMock(WebhookSecretService::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(function (string $route, array $params = []) {
            return '/login?' . http_build_query($params);
        });

        $controller = new OidcController(
            $oidcManager,
            $appSettingsRepo,
            $secretService,
            $urlGenerator,
        );

        $session = $this->createSession();
        $session->set('oidc_state', 'state-jwt-xyz');
        $session->set('oidc_code_verifier', 'verifier-abc');
        $session->set('oidc_nonce', 'nonce-123');

        $request = new Request(query: ['error' => 'access_denied']);
        $request->setSession($session);

        $container = new \Symfony\Component\DependencyInjection\Container();
        $container->set('router', $urlGenerator);
        $controller->setContainer($container);

        $response = $controller->check($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('error=oidc.cancelled', $response->getTargetUrl());
        $this->assertNull($session->get('oidc_state'));
        $this->assertNull($session->get('oidc_code_verifier'));
        $this->assertNull($session->get('oidc_nonce'));
    }

    public function testOidcCheckGracefullyHandlesGenericError(): void
    {
        $oidcManager = $this->createMock(OidcProviderManager::class);
        $appSettingsRepo = $this->createMock(AppSettingsRepository::class);
        $secretService = $this->createMock(WebhookSecretService::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(function (string $route, array $params = []) {
            return '/login?' . http_build_query($params);
        });

        $controller = new OidcController(
            $oidcManager,
            $appSettingsRepo,
            $secretService,
            $urlGenerator,
        );

        $session = $this->createSession();
        $session->set('oidc_state', 'state-jwt');
        $session->set('oidc_code_verifier', 'verifier');
        $session->set('oidc_nonce', 'nonce');

        $request = new Request(query: ['error' => 'unauthorized_client']);
        $request->setSession($session);

        $container = new \Symfony\Component\DependencyInjection\Container();
        $container->set('router', $urlGenerator);
        $controller->setContainer($container);

        $response = $controller->check($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('error=oidc.error_failed', $response->getTargetUrl());
        $this->assertNull($session->get('oidc_state'));
    }

    // ─── 4. OidcController::start Anti-Cache & Decryption Failure Defense ────

    public function testOidcStartEnforcesAntiCacheHeaders(): void
    {
        $oidcManager = $this->createMock(OidcProviderManager::class);
        $appSettingsRepo = $this->createMock(AppSettingsRepository::class);
        $secretService = $this->createMock(WebhookSecretService::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);

        $settings = new AppSettings();
        $settings->oauthProviders = [
            'oidcProviders' => [
                [
                    'id' => 'okta',
                    'enabled' => true,
                    'issuer' => 'https://dev-12345.okta.com',
                    'clientId' => 'okta-client-id',
                    'clientSecret' => 'plain-secret',
                ],
            ],
        ];
        $appSettingsRepo->method('getOrCreate')->willReturn($settings);

        $config = new OidcConfig(
            issuer: 'https://dev-12345.okta.com',
            authorizationEndpoint: 'https://dev-12345.okta.com/oauth2/v1/authorize',
            tokenEndpoint: 'https://dev-12345.okta.com/oauth2/v1/token',
            userinfoEndpoint: 'https://dev-12345.okta.com/oauth2/v1/userinfo',
            jwksUri: 'https://dev-12345.okta.com/oauth2/v1/keys',
            scopesSupported: ['openid', 'email', 'profile'],
            idTokenSigningAlg: 'RS256'
        );
        $oidcManager->method('discover')->willReturn($config);
        $oidcManager->method('generateState')->willReturn([
            'state' => 'signed-state-jwt',
            'nonce' => 'nonce-value',
            'codeVerifier' => 'verifier-value',
        ]);
        $oidcManager->method('computeCodeChallenge')->willReturn('challenge-value');
        $oidcManager->method('buildAuthorizationUrl')->willReturn('https://dev-12345.okta.com/oauth2/v1/authorize?client_id=okta-client-id');

        $controller = new OidcController(
            $oidcManager,
            $appSettingsRepo,
            $secretService,
            $urlGenerator,
        );

        $container = new \Symfony\Component\DependencyInjection\Container();
        $controller->setContainer($container);

        $request = new Request();
        $response = $controller->start('okta', $request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', $response->headers->get('Pragma'));
    }

    public function testOidcStartCatchesDecryptionErrorAndThrowsNotFound(): void
    {
        $oidcManager = $this->createMock(OidcProviderManager::class);
        $appSettingsRepo = $this->createMock(AppSettingsRepository::class);
        $secretService = $this->createMock(WebhookSecretService::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);

        $settings = new AppSettings();
        $settings->oauthProviders = [
            'oidcProviders' => [
                [
                    'id' => 'corrupted_provider',
                    'enabled' => true,
                    'issuer' => 'https://auth.company.com',
                    'clientId' => 'client-id',
                    'clientSecret' => 'enc:corrupted-ciphertext',
                ],
            ],
        ];
        $appSettingsRepo->method('getOrCreate')->willReturn($settings);

        $secretService->method('decrypt')->willThrowException(new \RuntimeException('Decryption failed'));

        $controller = new OidcController(
            $oidcManager,
            $appSettingsRepo,
            $secretService,
            $urlGenerator,
        );

        $container = new \Symfony\Component\DependencyInjection\Container();
        $controller->setContainer($container);

        $this->expectException(NotFoundHttpException::class);
        $controller->start('corrupted_provider', new Request());
    }

    // ─── 5. SecurityController::login Safe Error Propagation ──────────────────

    public function testSecurityControllerLoginPropagatesAllowedErrorKeys(): void
    {
        $socialLogin = $this->createMock(SocialLoginService::class);
        $socialLogin->method('getAvailableProviders')->willReturn(['google', 'github']);

        $appSettingsRepo = $this->createMock(AppSettingsRepository::class);
        $settings = new AppSettings();
        $settings->oauthProviders = [
            'oidcProviders' => [
                ['id' => 'okta', 'name' => 'Okta SSO', 'enabled' => true],
            ],
        ];
        $appSettingsRepo->method('getOrCreate')->willReturn($settings);

        $authUtils = $this->createMock(AuthenticationUtils::class);
        $authUtils->method('getLastAuthenticationError')->willReturn(null);
        $authUtils->method('getLastUsername')->willReturn('');

        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('getToken')->willReturn(new CsrfToken('authenticate', 'dummy-token'));

        $manifestService = $this->createMock(FrontendRouteManifestService::class);
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturnCallback(function ($key, $callback) {
            return [];
        });
        $translator = $this->createMock(TranslatorInterface::class);

        $tokenStorage = $this->createMock(\Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);

        $controller = new SecurityController($socialLogin, $appSettingsRepo);
        $controller->setRouteManifest($manifestService);
        $controller->setCache($cache);
        $controller->setTranslator($translator);
        $controller->setAppSettingsRepository($appSettingsRepo);

        $container = new \Symfony\Component\DependencyInjection\Container();
        $container->set('security.token_storage', $tokenStorage);
        $container->set('parameter_bag', new \Symfony\Component\DependencyInjection\ParameterBag\ParameterBag([
            'kernel.project_dir' => dirname(__DIR__, 2),
        ]));
        $controller->setContainer($container);

        // Test with X-Inertia header to inspect props directly via JSON
        $request = new Request(query: ['error' => 'social_auth_failed']);
        $request->headers->set('X-Inertia', 'true');

        $response = $controller->login($request, $authUtils, $csrfTokenManager);

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $response);
        $data = json_decode($response->getContent(), true);
        $this->assertSame('auth.social.error_failed', $data['props']['error']);

        // Test with oidc_failed
        $requestOidc = new Request(query: ['error' => 'oidc_failed']);
        $requestOidc->headers->set('X-Inertia', 'true');
        $responseOidc = $controller->login($requestOidc, $authUtils, $csrfTokenManager);
        $dataOidc = json_decode($responseOidc->getContent(), true);
        $this->assertSame('oidc.error_failed', $dataOidc['props']['error']);

        // Test with cancelled keys
        $requestCancelled = new Request(query: ['error' => 'auth.social.cancelled']);
        $requestCancelled->headers->set('X-Inertia', 'true');
        $responseCancelled = $controller->login($requestCancelled, $authUtils, $csrfTokenManager);
        $dataCancelled = json_decode($responseCancelled->getContent(), true);
        $this->assertSame('auth.social.cancelled', $dataCancelled['props']['error']);

        // Test with arbitrary unallowed key -> must NOT be propagated
        $requestBad = new Request(query: ['error' => '<script>alert(1)</script>']);
        $requestBad->headers->set('X-Inertia', 'true');
        $responseBad = $controller->login($requestBad, $authUtils, $csrfTokenManager);
        $dataBad = json_decode($responseBad->getContent(), true);
        $this->assertNull($dataBad['props']['error']);
    }

    // ─── 6. SocialLoginService::canUnlinkProvider with OIDC Identity ───────────

    public function testCanUnlinkProviderRecognizesOidcSubAsValidAuthMethod(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $userRepo = $this->createMock(UserRepository::class);
        $endUserRepo = $this->createMock(EndUserRepository::class);
        $appSettingsRepo = $this->createMock(AppSettingsRepository::class);
        $secretService = $this->createMock(WebhookSecretService::class);

        $service = new SocialLoginService(
            $em,
            $userRepo,
            $endUserRepo,
            $appSettingsRepo,
            $secretService,
        );

        $user = new User();
        $user->googleId = 'google-sub-123';
        $user->password = null; // No password!
        $user->githubId = null;
        $user->gitlabId = null;
        $user->microsoftId = null;

        // Without OIDC, user cannot unlink their only social provider
        $user->oidcSub = null;
        $this->assertFalse($service->canUnlinkProvider($user, 'google'));

        // With OIDC identity linked, user CAN unlink google
        $user->oidcSub = 'oidc-enterprise-user-789';
        $user->oidcIssuer = 'https://sso.enterprise.com';
        $this->assertTrue($service->canUnlinkProvider($user, 'google'));
    }

    // ─── 7. RegisteredUserController Password Policy Before DB Email Check ────

    public function testRegisteredUserRejectsWeakPasswordBeforeEmailQuery(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        // The User repository must NOT be called if the password fails policy!
        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->expects($this->never())->method('findOneBy');
        $em->method('getRepository')->willReturn($userRepo);

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new ConstraintViolationList());

        $limiterFactory = new RateLimiterFactory(
            ['id' => 'test_register', 'policy' => 'sliding_window', 'limit' => 100, 'interval' => '60 seconds'],
            new InMemoryStorage()
        );

        $controller = new RegisteredUserController($em, $hasher, $validator, $limiterFactory);

        $container = new \Symfony\Component\DependencyInjection\Container();
        $controller->setContainer($container);

        $payload = [
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'password' => 'password123', // In COMMON_PASSWORDS list -> rejected by policy
            'password_confirmation' => 'password123',
        ];
        $request = new Request(
            content: json_encode($payload)
        );
        $request->headers->set('Content-Type', 'application/json');

        $response = $controller->store($request);

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $response);
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('password', $data['errors']);
    }

    // ─── 8. Strict Translation Parity Verification ───────────────────────────

    public function testTranslationParityAcrossAllLocales(): void
    {
        $en = require __DIR__ . '/../../translations/messages.en.php';
        $fr = require __DIR__ . '/../../translations/messages.fr.php';
        $es = require __DIR__ . '/../../translations/messages.es.php';
        $ar = require __DIR__ . '/../../translations/messages.ar.php';

        $this->assertCount(count($en), $fr, 'FR count does not match EN count');
        $this->assertCount(count($en), $es, 'ES count does not match EN count');
        $this->assertCount(count($en), $ar, 'AR count does not match EN count');

        $requiredKeys = [
            'auth.social.error_failed',
            'auth.social.cancelled',
            'oidc.cancelled',
        ];

        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $en, "Missing key {$key} in EN");
            $this->assertArrayHasKey($key, $fr, "Missing key {$key} in FR");
            $this->assertArrayHasKey($key, $es, "Missing key {$key} in ES");
            $this->assertArrayHasKey($key, $ar, "Missing key {$key} in AR");
        }
    }
}
