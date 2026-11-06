<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ProjectOAuthController;
use App\Entity\EndUser;
use App\Entity\Project;
use App\Entity\ProjectAuthClient;
use App\Repository\EndUserRepository;
use App\Repository\ProjectRepository;
use App\Service\AuditService;
use App\Service\EndUserJwtService;
use App\Service\ProjectAuthLockoutService;
use App\Service\ProjectOAuthService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Cycle 104 - Security and hardening tests for ProjectOAuthController:
 * - Content-Type validation (415 for untrusted mime types on POST endpoints)
 * - Strict project UUID sanitization and validation
 * - Security headers presence on all JSON responses
 * - Tenant isolation and client/user project mismatch detection
 * - Audit logging via AuditService on authentication, token and revocation events
 * - Translation dictionary completeness and i18n compliance across locales
 */
class Cycle104OAuthHardeningTest extends TestCase
{
    private ProjectRepository $projectRepo;
    private EndUserRepository $endUserRepo;
    private ProjectOAuthService $oauthService;
    private EndUserJwtService $jwtService;
    private ProjectAuthLockoutService $lockoutService;
    private UserPasswordHasherInterface $passwordHasher;
    private TranslatorInterface $translator;
    private AuditService $auditService;
    private RateLimiterFactory $loginLimiter;

    protected function setUp(): void
    {
        $this->projectRepo = $this->createMock(ProjectRepository::class);
        $this->endUserRepo = $this->createMock(EndUserRepository::class);
        $this->oauthService = $this->createMock(ProjectOAuthService::class);
        $this->jwtService = $this->createMock(EndUserJwtService::class);
        $this->lockoutService = $this->createMock(ProjectAuthLockoutService::class);
        $this->passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->auditService = $this->createMock(AuditService::class);
        $this->loginLimiter = new RateLimiterFactory(
            ['id' => 'test_login', 'policy' => 'fixed_window', 'limit' => 100, 'interval' => '1 minute'],
            new InMemoryStorage()
        );
    }

    private function createController(): ProjectOAuthController
    {
        return new ProjectOAuthController(
            $this->projectRepo,
            $this->endUserRepo,
            $this->oauthService,
            $this->jwtService,
            $this->lockoutService,
            $this->passwordHasher,
            $this->translator,
            $this->loginLimiter,
            $this->auditService
        );
    }

    public function testTokenRejectsInvalidContentTypeWith415(): void
    {
        $controller = $this->createController();

        $request = new Request([], [], [], [], [], ['HTTP_CONTENT_TYPE' => 'text/xml'], '<xml></xml>');
        $request->setMethod('POST');

        $response = $controller->token('valid-project-uuid', $request);

        $this->assertSame(415, $response->getStatusCode());
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('invalid_request', $data['error']);
    }

    public function testRevokeRejectsInvalidContentTypeWith415(): void
    {
        $controller = $this->createController();

        $request = new Request([], [], [], [], [], ['HTTP_CONTENT_TYPE' => 'application/custom-evil'], '{"bad":true}');
        $request->setMethod('POST');

        $response = $controller->revoke('valid-project-uuid', $request);

        $this->assertSame(415, $response->getStatusCode());
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }

    public function testAuthorizeRejectsMalformedProjectUuidWith404(): void
    {
        $controller = $this->createController();

        $request = new Request();
        // Project UUID containing illegal characters and injection attempts
        $response = $controller->authorize("bad'; DROP TABLE project;--", $request);

        $this->assertSame(404, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('invalid_client', $data['error']);
    }

    public function testAuthorizeSecurityHeadersAreStrictlyApplied(): void
    {
        $this->projectRepo->method('findOneBy')->willReturn(null);
        $controller = $this->createController();

        $request = new Request();
        $response = $controller->authorize('proj-uuid-not-found', $request);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testTokenLogsAuditOnSuccess(): void
    {
        $project = new Project();
        $project->id = 10;
        $this->projectRepo->method('findOneBy')->willReturn($project);

        $client = new ProjectAuthClient();
        $client->id = 42;
        $client->project = $project;
        $this->oauthService->method('validateClient')->willReturn($client);
        $this->oauthService->method('exchangeAuthorizationCode')->willReturn([
            'access_token' => 'access_xyz_test',
            'token_type'   => 'Bearer',
            'expires_in'   => 3600,
        ]);

        $this->auditService->expects($this->once())
            ->method('log')
            ->with(
                'oauth_token_issued',
                $project,
                ['client_id' => 42, 'grant_type' => 'authorization_code'],
                null,
                'success'
            );
        $this->auditService->expects($this->once())
            ->method('flush');

        $controller = $this->createController();

        $request = new Request(
            query: [],
            request: [
                'client_id' => 'cli_123',
                'grant_type' => 'authorization_code',
                'code' => 'auth_code_999',
                'redirect_uri' => 'https://app.example.com/callback',
            ],
            server: ['HTTP_CONTENT_TYPE' => 'application/x-www-form-urlencoded']
        );
        $request->setMethod('POST');

        $response = $controller->token('valid-project-uuid', $request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('access_xyz_test', $data['access_token']);
    }

    public function testTokenLogsAuditOnFailure(): void
    {
        $project = new Project();
        $project->id = 10;
        $this->projectRepo->method('findOneBy')->willReturn($project);

        $this->oauthService->method('validateClient')
            ->willThrowException(new \RuntimeException('Invalid client credentials.'));

        $this->auditService->expects($this->once())
            ->method('log')
            ->with(
                'oauth_token_failed',
                $project,
                $this->anything(),
                null,
                'failed',
                'Invalid client credentials.'
            );
        $this->auditService->expects($this->once())
            ->method('flush');

        $controller = $this->createController();

        $request = new Request(
            query: [],
            request: [
                'client_id' => 'cli_bad',
                'client_secret' => 'wrong_secret',
                'grant_type' => 'authorization_code',
            ],
            server: ['HTTP_CONTENT_TYPE' => 'application/json']
        );
        $request->setMethod('POST');

        $response = $controller->token('valid-project-uuid', $request);

        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('invalid_grant', $data['error']);
    }

    public function testRevokeLogsAuditOnSuccess(): void
    {
        $project = new Project();
        $project->id = 10;
        $this->projectRepo->method('findOneBy')->willReturn($project);

        $client = new ProjectAuthClient();
        $client->id = 42;
        $this->oauthService->method('validateClient')->willReturn($client);
        $this->oauthService->expects($this->once())->method('revokeToken')->with($client, 'tok_123');

        $this->auditService->expects($this->once())
            ->method('log')
            ->with(
                'oauth_token_revoked',
                $project,
                ['client_id' => 42],
                null,
                'success'
            );
        $this->auditService->expects($this->once())
            ->method('flush');

        $controller = $this->createController();

        $request = new Request(
            query: [],
            request: [
                'client_id' => 'cli_123',
                'token' => 'tok_123',
            ],
            server: ['HTTP_CONTENT_TYPE' => 'application/json']
        );
        $request->setMethod('POST');

        $response = $controller->revoke('valid-project-uuid', $request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('revoked', $data['status']);
    }

    public function testUserinfoDetectsTenantMismatchAndLogsAudit(): void
    {
        $projectA = new Project();
        $projectA->id = 1;

        $projectB = new Project();
        $projectB->id = 2;

        $this->projectRepo->method('findOneBy')->willReturn($projectA);

        $endUserFromB = new EndUser($projectB, 'user_b@test.com');
        $endUserFromB->id = 99;

        $this->jwtService->method('validateToken')->willReturn(['sub' => 99]);
        $this->endUserRepo->method('find')->with(99)->willReturn($endUserFromB);

        $this->auditService->expects($this->once())
            ->method('log')
            ->with(
                'oauth_userinfo_mismatch',
                $projectA,
                ['user_id' => 99],
                null,
                'blocked'
            );
        $this->auditService->expects($this->once())
            ->method('flush');

        $controller = $this->createController();

        $request = new Request();
        $request->headers->set('Authorization', 'Bearer valid_jwt_token_for_user_b');

        $response = $controller->userinfo('valid-project-uuid', $request);

        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('invalid_token', $data['error']);
    }

    public function testAuthorizeLogsAuditOnDeny(): void
    {
        $project = new Project();
        $project->id = 1;
        $this->projectRepo->method('findOneBy')->willReturn($project);

        $client = new ProjectAuthClient();
        $client->project = $project;
        $client->name = 'Deny Client';
        $client->redirectUris = ['https://myapp.test/callback'];
        $this->oauthService->method('validateClient')->willReturn($client);

        $this->auditService->expects($this->once())
            ->method('log')
            ->with(
                'oauth_authorize_denied',
                $project,
                $this->anything(),
                null,
                'denied'
            );
        $this->auditService->expects($this->once())->method('flush');

        $controller = $this->createController();

        $request = new Request(
            query: [
                'client_id' => 'cli_deny',
                'redirect_uri' => 'https://myapp.test/callback',
                'response_type' => 'code',
            ],
            request: ['action' => 'deny']
        );
        $request->setMethod('POST');

        $response = $controller->authorize('valid-project-uuid', $request);

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\RedirectResponse::class, $response);
        $this->assertStringContainsString('error=access_denied', $response->getTargetUrl());
    }

    public function testAuthorizeLogsAuditOnClientMismatch(): void
    {
        $projectA = new Project();
        $projectA->id = 1;

        $projectB = new Project();
        $projectB->id = 2;

        $this->projectRepo->method('findOneBy')->willReturn($projectA);

        $clientOfB = new ProjectAuthClient();
        $clientOfB->project = $projectB;
        $this->oauthService->method('validateClient')->willReturn($clientOfB);

        $this->auditService->expects($this->once())
            ->method('log')
            ->with(
                'oauth_client_mismatch',
                $projectA,
                ['client_id' => 'client_from_b'],
                null,
                'blocked'
            );
        $this->auditService->expects($this->once())->method('flush');

        $controller = $this->createController();

        $request = new Request(query: [
            'client_id' => 'client_from_b',
            'redirect_uri' => 'https://app.test/callback',
        ]);

        $response = $controller->authorize('valid-project-uuid', $request);

        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('invalid_client', $data['error']);
    }

    public function testRevokeFailureLogsAudit(): void
    {
        $project = new Project();
        $project->id = 1;
        $this->projectRepo->method('findOneBy')->willReturn($project);

        $this->oauthService->method('validateClient')
            ->willThrowException(new \RuntimeException('Client not found or invalid secret.'));

        $this->auditService->expects($this->once())
            ->method('log')
            ->with(
                'oauth_revoke_failed',
                $project,
                $this->anything(),
                null,
                'failed'
            );
        $this->auditService->expects($this->once())->method('flush');

        $controller = $this->createController();

        $request = new Request(
            request: ['client_id' => 'bad_client', 'token' => 'some_token'],
            server: ['HTTP_CONTENT_TYPE' => 'application/json']
        );
        $request->setMethod('POST');

        $response = $controller->revoke('valid-project-uuid', $request);

        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('invalid_request', $data['error']);
    }

    public function testTranslationKeysExistInAllLocales(): void
    {
        $requiredKeys = [
            'project_oauth.error.invalid_content_type',
            'project_oauth.error.invalid_grant',
            'project_oauth.error.invalid_client',
            'project_oauth.error.revocation_failed',
            'project_oauth.success.revoked',
            'project_oauth.error.project_not_found',
            'project_oauth.error.missing_client_id',
            'project_oauth.error.client_not_in_project',
            'project_oauth.error.redirect_uri_not_allowed',
            'project_oauth.error.unsupported_transform_algorithm',
            'project_oauth.error.unsupported_response_type',
            'project_oauth.error.access_denied',
            'project_oauth.error.invalid_credentials',
            'project_oauth.error.credentials_required',
            'project_oauth.error.login_required',
            'project_oauth.error.rate_limited',
            'project_oauth.error.unsupported_grant_type',
            'project_oauth.error.bearer_required',
            'project_oauth.error.invalid_or_expired_token',
            'project_oauth.error.user_not_found',
            'project_oauth.error.token_mismatch_project',
            'project_oauth.error.payload_too_large',
            'project_oauth.error.rate_limited_login',
            'project_oauth.error.rate_limited_token',
            'project_oauth.error.ip_locked_out',
            'project_oauth.error.account_locked_out',
            'project_oauth.error.account_inactive',
            'project_oauth.error.revoke_rate_limited',
            'project_oauth.error.server_error',
        ];

        $locales = ['en', 'fr', 'es', 'ar'];
        foreach ($locales as $loc) {
            $file = dirname(__DIR__, 2) . "/translations/messages.{$loc}.php";
            $this->assertFileExists($file);
            $translations = require $file;
            $this->assertIsArray($translations);

            foreach ($requiredKeys as $key) {
                $this->assertArrayHasKey($key, $translations, "Key '{$key}' missing from messages.{$loc}.php");
                $this->assertNotEmpty($translations[$key], "Key '{$key}' is empty in messages.{$loc}.php");
            }
        }
    }
}
