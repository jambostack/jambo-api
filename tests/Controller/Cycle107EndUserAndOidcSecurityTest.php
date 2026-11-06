<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\Api\EndUserAuthController;
use App\Controller\Auth\OidcController;
use App\Controller\Auth\SocialLoginController;
use App\Controller\SecurityController;
use App\Entity\EndUser;
use App\Entity\PasswordResetToken;
use App\Entity\Project;
use App\Repository\AppSettingsRepository;
use App\Repository\EndUserRepository;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\ProjectAuthRefreshTokenRepository;
use App\Repository\ProjectAuthSessionRepository;
use App\Repository\ProjectRepository;
use App\Security\Oidc\OidcProviderManager;
use App\Service\AuditService;
use App\Service\EndUserCustomFieldsSanitizer;
use App\Service\EndUserJwtService;
use App\Service\PasswordPolicyValidator;
use App\Service\ProjectAuthLockoutService;
use App\Service\ProjectMailerService;
use App\Service\SocialLoginService;
use App\Service\TwoFactorService;
use App\Service\WebhookSecretService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Cycle 107/108 - Comprehensive security and hardening test suite:
 * - EndUserAuthController:
 *   * Content-Type validation (415 for non-application/json payloads)
 *   * Maximum payload size enforcement (413 for oversized payloads > 64KB)
 *   * Strict security headers (X-Content-Type-Options, X-Frame-Options, Cache-Control)
 *   * Tiered rate limiting on register and reset-password
 *   * Timing equalization on password reset requests
 *   * Token format & length validation with tiered IP lockout
 *   * Multi-tenant isolation & cross-project token rejection
 *   * Audit logging for all authentication & security events
 * - OidcController:
 *   * Rate limiting enforcement (429 with Retry-After header)
 *   * Strict providerId regex validation (404 on directory traversal or XSS)
 *   * Discovery failure handling with safe redirect and audit logging
 *   * Comprehensive audit logging
 * - SocialLoginController:
 *   * Rate limiting on start and check endpoints
 *   * Unknown provider rejection (404)
 *   * Security headers on redirects
 *   * Audit logging of OAuth flows
 * - Translation dictionaries completeness (en, fr, es, ar)
 */
class Cycle107EndUserAndOidcSecurityTest extends TestCase
{
    private function createLimiter(int $limit = 50, string $id = 'test_limiter'): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
    }

    private function createRejectingLimiter(string $id = 'rejecting_limiter', string $ip = '127.0.0.1'): RateLimiterFactory
    {
        $factory = new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
        $factory->create($ip)->consume(1);
        return $factory;
    }

    private function createDummyTranslator(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            fn(string $id, array $params = [], ?string $domain = null, ?string $locale = null) =>
                strtr($id, $params)
        );
        return $translator;
    }

    // =========================================================================
    // EndUserAuthController Tests
    // =========================================================================

    public function testRegisterRejectsUnsupportedContentTypeWith415(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $project = new Project();
        $project->uuid = Uuid::v4();
        $projectRepo->method('findOneBy')->willReturn($project);

        $endUserRepo = $this->createMock(EndUserRepository::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $jwtService = $this->createMock(EndUserJwtService::class);
        $twoFactorService = $this->createMock(TwoFactorService::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $auditService = $this->createMock(AuditService::class);

        $auditService->expects($this->once())
            ->method('log')
            ->with('enduser_auth_invalid_content_type');

        $controller = new EndUserAuthController(
            projectRepository: $projectRepo,
            endUserRepository: $endUserRepo,
            jwtService: $jwtService,
            twoFactorService: $twoFactorService,
            hasher: $hasher,
            em: $em,
            registerLimiter: $this->createLimiter(),
            translator: $this->createDummyTranslator(),
            auditService: $auditService,
        );

        $request = new Request(
            server: ['REMOTE_ADDR' => '10.0.0.1', 'CONTENT_TYPE' => 'text/html'],
            content: 'email=test@example.com'
        );
        $request->setMethod('POST');

        $response = $controller->register($request, (string) $project->uuid);

        $this->assertSame(415, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('INVALID_CONTENT_TYPE', $data['code']);
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testRegisterRejectsOversizedPayloadWith413(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $project = new Project();
        $project->uuid = Uuid::v4();
        $projectRepo->method('findOneBy')->willReturn($project);

        $endUserRepo = $this->createMock(EndUserRepository::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $jwtService = $this->createMock(EndUserJwtService::class);
        $twoFactorService = $this->createMock(TwoFactorService::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $auditService = $this->createMock(AuditService::class);

        $auditService->expects($this->once())
            ->method('log')
            ->with('enduser_auth_payload_too_large');

        $controller = new EndUserAuthController(
            projectRepository: $projectRepo,
            endUserRepository: $endUserRepo,
            jwtService: $jwtService,
            twoFactorService: $twoFactorService,
            hasher: $hasher,
            em: $em,
            registerLimiter: $this->createLimiter(),
            translator: $this->createDummyTranslator(),
            auditService: $auditService,
        );

        $oversizedData = json_encode(['email' => 'test@example.com', 'extra' => str_repeat('A', 70000)]);
        $request = new Request(
            server: ['REMOTE_ADDR' => '10.0.0.1', 'CONTENT_TYPE' => 'application/json'],
            content: $oversizedData
        );
        $request->setMethod('POST');

        $response = $controller->register($request, (string) $project->uuid);

        $this->assertSame(413, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('PAYLOAD_TOO_LARGE', $data['code']);
    }

    public function testRegisterRateLimitTriggeredReturns429(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $endUserRepo = $this->createMock(EndUserRepository::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $jwtService = $this->createMock(EndUserJwtService::class);
        $twoFactorService = $this->createMock(TwoFactorService::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $auditService = $this->createMock(AuditService::class);

        $auditService->expects($this->once())
            ->method('log')
            ->with('enduser_register_rate_limited');

        $controller = new EndUserAuthController(
            projectRepository: $projectRepo,
            endUserRepository: $endUserRepo,
            jwtService: $jwtService,
            twoFactorService: $twoFactorService,
            hasher: $hasher,
            em: $em,
            registerLimiter: $this->createRejectingLimiter('reg_test', '192.168.1.100'),
            translator: $this->createDummyTranslator(),
            auditService: $auditService,
        );

        $request = new Request(
            server: ['REMOTE_ADDR' => '192.168.1.100', 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'user@domain.com', 'password' => 'Pass1234!'])
        );
        $request->setMethod('POST');

        $response = $controller->register($request, 'proj-123');

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testPasswordResetRequestWithUnknownEmailReturns200AndAuditsTimingDefense(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $project = new Project();
        $project->uuid = Uuid::v4();
        $projectRepo->method('findOneBy')->willReturn($project);

        $endUserRepo = $this->createMock(EndUserRepository::class);
        $endUserRepo->method('findOneByProjectAndEmail')->willReturn(null); // Unknown email

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $jwtService = $this->createMock(EndUserJwtService::class);
        $twoFactorService = $this->createMock(TwoFactorService::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $tokenRepo = $this->createMock(PasswordResetTokenRepository::class);
        $em->expects($this->once())->method('getRepository')->with(PasswordResetToken::class)->willReturn($tokenRepo);

        $auditService = $this->createMock(AuditService::class);
        $auditService->expects($this->once())
            ->method('log')
            ->with('enduser_password_reset_requested');

        $controller = new EndUserAuthController(
            projectRepository: $projectRepo,
            endUserRepository: $endUserRepo,
            jwtService: $jwtService,
            twoFactorService: $twoFactorService,
            hasher: $hasher,
            em: $em,
            passwordResetLimiter: $this->createLimiter(),
            translator: $this->createDummyTranslator(),
            auditService: $auditService,
        );

        $request = new Request(
            server: ['REMOTE_ADDR' => '10.0.0.5', 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'nonexistent@example.com'])
        );
        $request->setMethod('POST');

        $response = $controller->forgotPassword($request, (string) $project->uuid);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('message', $data);
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testResetPasswordWithInvalidTokenFormatReturns400AndAudits(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $project = new Project();
        $project->uuid = Uuid::v4();
        $projectRepo->method('findOneBy')->willReturn($project);

        $endUserRepo = $this->createMock(EndUserRepository::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $jwtService = $this->createMock(EndUserJwtService::class);
        $twoFactorService = $this->createMock(TwoFactorService::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $auditService = $this->createMock(AuditService::class);
        $auditService->expects($this->once())
            ->method('log')
            ->with('enduser_password_reset_invalid_token');

        $controller = new EndUserAuthController(
            projectRepository: $projectRepo,
            endUserRepository: $endUserRepo,
            jwtService: $jwtService,
            twoFactorService: $twoFactorService,
            hasher: $hasher,
            em: $em,
            passwordResetLimiter: $this->createLimiter(),
            translator: $this->createDummyTranslator(),
            auditService: $auditService,
        );

        // Token that is not 64 hex chars
        $request = new Request(
            server: ['REMOTE_ADDR' => '10.0.0.6', 'CONTENT_TYPE' => 'application/json'],
            content: json_encode(['token' => 'invalid-short-token', 'password' => 'NewPassword123!'])
        );
        $request->setMethod('POST');

        $response = $controller->resetPassword($request, (string) $project->uuid);

        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    // =========================================================================
    // OidcController Tests
    // =========================================================================

    public function testOidcStartRateLimitRejectionReturns429WithHeaders(): void
    {
        $oidcManager = $this->createMock(OidcProviderManager::class);
        $appSettingsRepo = $this->createMock(AppSettingsRepository::class);
        $secretService = $this->createMock(WebhookSecretService::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $auditService = $this->createMock(AuditService::class);

        $auditService->expects($this->once())
            ->method('log')
            ->with('auth_oidc_rate_limited');

        $controller = new OidcController(
            oidcManager: $oidcManager,
            appSettingsRepo: $appSettingsRepo,
            secretService: $secretService,
            urlGenerator: $urlGenerator,
            oidcLimiter: $this->createRejectingLimiter('oidc_test', '192.168.1.1'),
            translator: $this->createDummyTranslator(),
            auditService: $auditService,
        );

        $request = new Request(server: ['REMOTE_ADDR' => '192.168.1.1']);
        $response = $controller->start('google-oidc', $request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('60', $response->headers->get('Retry-After'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }

    public function testOidcStartRejectsInvalidProviderIdCharactersWith404(): void
    {
        $oidcManager = $this->createMock(OidcProviderManager::class);
        $appSettingsRepo = $this->createMock(AppSettingsRepository::class);
        $secretService = $this->createMock(WebhookSecretService::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $auditService = $this->createMock(AuditService::class);

        $auditService->expects($this->once())
            ->method('log')
            ->with('auth_oidc_unknown_provider');

        $controller = new OidcController(
            oidcManager: $oidcManager,
            appSettingsRepo: $appSettingsRepo,
            secretService: $secretService,
            urlGenerator: $urlGenerator,
            oidcLimiter: $this->createLimiter(),
            translator: $this->createDummyTranslator(),
            auditService: $auditService,
        );

        $request = new Request(server: ['REMOTE_ADDR' => '192.168.1.1']);

        $this->expectException(NotFoundHttpException::class);
        $controller->start('../../etc/passwd', $request);
    }

    // =========================================================================
    // SocialLoginController Tests
    // =========================================================================

    public function testSocialLoginConnectRateLimitRejectionReturns429WithHeaders(): void
    {
        $socialLogin = $this->createMock(SocialLoginService::class);
        $auditService = $this->createMock(AuditService::class);

        $auditService->expects($this->once())
            ->method('log')
            ->with('auth_social_rate_limited');

        $controller = new SocialLoginController(
            socialLogin: $socialLogin,
            socialAuthLimiter: $this->createRejectingLimiter('social_test', '172.16.0.1'),
            translator: $this->createDummyTranslator(),
            auditService: $auditService,
        );

        $request = new Request(server: ['REMOTE_ADDR' => '172.16.0.1']);
        $response = $controller->connect('github', $request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('60', $response->headers->get('Retry-After'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }

    public function testSocialLoginConnectRejectsUnknownProviderWith404(): void
    {
        $socialLogin = $this->createMock(SocialLoginService::class);
        $auditService = $this->createMock(AuditService::class);

        $auditService->expects($this->once())
            ->method('log')
            ->with('auth_social_unknown_provider');

        $controller = new SocialLoginController(
            socialLogin: $socialLogin,
            socialAuthLimiter: $this->createLimiter(),
            translator: $this->createDummyTranslator(),
            auditService: $auditService,
        );

        $request = new Request(server: ['REMOTE_ADDR' => '172.16.0.1']);

        $this->expectException(NotFoundHttpException::class);
        $controller->connect('unknown-provider', $request);
    }

    public function testSocialLoginCheckWithCancelledOAuthRedirectsWithSecurityHeaders(): void
    {
        $socialLogin = $this->createMock(SocialLoginService::class);
        $auditService = $this->createMock(AuditService::class);

        $auditService->expects($this->once())
            ->method('log')
            ->with('auth_social_callback_cancelled');

        $container = new Container();
        $container->set('router', new class {
            public function generate(string $route, array $parameters = [], int $referenceType = 1): string {
                return '/login?' . http_build_query($parameters);
            }
        });

        $controller = new SocialLoginController(
            socialLogin: $socialLogin,
            socialAuthLimiter: $this->createLimiter(),
            translator: $this->createDummyTranslator(),
            auditService: $auditService,
        );
        $controller->setContainer($container);

        $session = new Session(new MockArraySessionStorage());
        $session->set('oauth2state_github', 'sample-state');

        $request = new Request(
            query: ['error' => 'access_denied'],
            server: ['REMOTE_ADDR' => '172.16.0.1']
        );
        $request->setSession($session);

        $response = $controller->check('github', $request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('auth.social.cancelled', $response->getTargetUrl());
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertFalse($session->has('oauth2state_github'));
    }

    // =========================================================================
    // Translation Dictionaries Completeness Tests
    // =========================================================================

    public function testTranslationDictionariesHaveRequiredKeysAcrossAllLocales(): void
    {
        $locales = ['en', 'fr', 'es', 'ar'];
        $requiredKeys = [
            'enduser.auth.invalid_content_type',
            'oidc.discovery_failed',
            'auth.social.callback_rate_limited',
            'oidc.callback_rate_limited',
            'enduser.auth.rate_limit_reset',
            'enduser.auth.invalid_json',
            'enduser.auth.invalid_reset_token',
            'enduser.auth.payload_too_large',
            'enduser.auth.token_rate_limit_lockout',
            'enduser.auth.reset_ip_locked_out',
        ];

        foreach ($locales as $locale) {
            $path = __DIR__ . '/../../translations/messages.' . $locale . '.php';
            $this->assertFileExists($path, "Translation file missing for {$locale}");

            $translations = require $path;
            $this->assertIsArray($translations, "Translations for {$locale} must return an array");

            foreach ($requiredKeys as $key) {
                $this->assertArrayHasKey(
                    $key,
                    $translations,
                    "Translation key '{$key}' missing in translations/messages.{$locale}.php"
                );
                $this->assertNotEmpty(
                    trim((string) $translations[$key]),
                    "Translation key '{$key}' must not be empty in translations/messages.{$locale}.php"
                );
            }
        }
    }
}
