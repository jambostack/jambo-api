<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\ProjectJwtTtlController;
use App\Controller\AdminApi\AdminApiControllerTrait;
use App\Controller\AdminApi\PingController;
use App\Controller\Settings\LocaleController;
use App\Controller\UserManagement\PermissionController;
use App\Controller\UserManagement\RoleController;
use App\Controller\UserManagement\UserController;
use App\Entity\PersonalAccessToken;
use App\Entity\Project;
use App\Entity\Role;
use App\Entity\User;
use App\Repository\PermissionRepository;
use App\Repository\RoleRepository;
use App\Repository\UserRepository;
use App\Service\AuditService;
use App\Service\ProjectAuthLockoutService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class Cycle98AdminSecurityHardeningTest extends TestCase
{
    private function createRejectingLimiter(string $id = 'test_reject'): RateLimiterFactory
    {
        $factory = new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '60 seconds'],
            new InMemoryStorage()
        );
        $factory->create('127.0.0.1')->consume(1);
        $factory->create('192.168.1.1')->consume(1);
        $factory->create('10.0.0.1')->consume(1);
        return $factory;
    }

    private function createAcceptingLimiter(string $id = 'test_accept'): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => 100, 'interval' => '60 seconds'],
            new InMemoryStorage()
        );
    }

    private function createMockTranslator(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(function (string $id, array $params = [], ?string $domain = null) {
            return $id;
        });
        return $translator;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ProjectJwtTtlController Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function testProjectJwtTtlRateLimiting(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $translator = $this->createMockTranslator();
        $rateLimiter = $this->createRejectingLimiter('test_jwt_ttl_reject');
        $auditService = $this->createMock(AuditService::class);

        $controller = new ProjectJwtTtlController($em, $translator, $rateLimiter, $auditService);

        $request = new Request([], [], [], [], [], ['REMOTE_ADDR' => '192.168.1.1']);
        $response = $controller->getJwtTtl('c9a8b765-4321-4def-9876-123456789abc', $request);

        $this->assertSame(429, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('RATE_LIMIT_EXCEEDED', $data['code']);
    }

    public function testProjectJwtTtlUpdateAuditLoggingOnInvalidRange(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $translator = $this->createMockTranslator();
        $rateLimiter = $this->createAcceptingLimiter('test_accept_limiter');

        $auditService = $this->createMock(AuditService::class);
        $auditService->expects($this->once())
            ->method('log')
            ->with(
                $this->equalTo('admin_project_jwt_ttl_update'),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->equalTo('failure'),
                $this->stringContains('Refresh TTL cannot be less than access TTL'),
            );

        $project = new Project();
        $project->jwtAccessTtl = 3600;
        $project->jwtRefreshTtl = 1800; // Less than access TTL!

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn($project);
        $em->method('getRepository')->willReturn($repo);

        $authChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturn(true);

        $controller = new ProjectJwtTtlController($em, $translator, $rateLimiter, $auditService);

        $container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $container->method('has')->willReturnCallback(fn ($id) => in_array($id, ['security.authorization_checker', 'security.token_storage'], true));
        $container->method('get')->willReturnCallback(fn ($id) => $id === 'security.authorization_checker' ? $authChecker : null);
        $controller->setContainer($container);

        $request = new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'jwt_access_ttl' => 3600,
            'jwt_refresh_ttl' => 1800,
        ]));

        $response = $controller->updateJwtTtl('c9a8b765-4321-4def-9876-123456789abc', $request);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testProjectJwtTtlUpdateAuditLoggingOnSuccess(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $translator = $this->createMockTranslator();
        $rateLimiter = $this->createAcceptingLimiter('test_accept_limiter');

        $auditService = $this->createMock(AuditService::class);
        $auditService->expects($this->once())
            ->method('log')
            ->with(
                $this->equalTo('admin_project_jwt_ttl_update'),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->equalTo('success')
            );

        $project = new Project();
        $project->jwtAccessTtl = 900;
        $project->jwtRefreshTtl = 86400;

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn($project);
        $em->method('getRepository')->willReturn($repo);

        $authChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturn(true);

        $controller = new ProjectJwtTtlController($em, $translator, $rateLimiter, $auditService);

        $container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $container->method('has')->willReturnCallback(fn ($id) => in_array($id, ['security.authorization_checker', 'security.token_storage'], true));
        $container->method('get')->willReturnCallback(fn ($id) => $id === 'security.authorization_checker' ? $authChecker : null);
        $controller->setContainer($container);

        $request = new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'jwt_access_ttl' => 1800,
            'jwt_refresh_ttl' => 86400,
        ]));

        $response = $controller->updateJwtTtl('c9a8b765-4321-4def-9876-123456789abc', $request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1800, $project->jwtAccessTtl);
        $this->assertSame(86400, $project->jwtRefreshTtl);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PingController Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function testPingControllerRateLimiting(): void
    {
        $rateLimiter = $this->createRejectingLimiter('test_jwt_ttl_reject');
        $translator = $this->createMockTranslator();
        $audit = $this->createMock(AuditService::class);

        $controller = new PingController($rateLimiter, $translator, $audit);
        $request = new Request([], [], [], [], [], ['REMOTE_ADDR' => '10.0.0.1']);

        $response = $controller->ping($request);
        $this->assertSame(429, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('RATE_LIMIT_EXCEEDED', $data['code']);
    }

    public function testPingControllerSuccess(): void
    {
        $rateLimiter = $this->createAcceptingLimiter('test_accept_limiter');
        $translator = $this->createMockTranslator();
        $audit = $this->createMock(AuditService::class);

        $controller = new PingController($rateLimiter, $translator, $audit);

        $user = new User();
        $user->email = 'ping_user@jambostack.site';

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $tokenStorage->method('getToken')->willReturn($token);

        $container = $this->createMock(\Psr\Container\ContainerInterface::class);
        $container->method('has')->willReturnCallback(fn ($id) => $id === 'security.token_storage');
        $container->method('get')->willReturnCallback(fn ($id) => $id === 'security.token_storage' ? $tokenStorage : null);
        $controller->setContainer($container);

        $request = new Request([], [], [], [], [], ['REMOTE_ADDR' => '10.0.0.1']);
        $response = $controller->ping($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('ping_user@jambostack.site', $data['data']['user']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AdminApiControllerTrait Scope Violation Audit Test
    // ─────────────────────────────────────────────────────────────────────────

    public function testAdminApiScopeViolationAuditing(): void
    {
        $auditService = $this->createMock(AuditService::class);
        $auditService->expects($this->once())
            ->method('log')
            ->with(
                $this->equalTo('admin_api_scope_violation'),
                $this->isNull(),
                $this->anything(),
                $this->isNull(),
                $this->equalTo('failure'),
                $this->stringContains('Missing required scope: schema:write')
            );

        $tester = new class($auditService) {
            use AdminApiControllerTrait;

            public function __construct(public ?AuditService $auditService) {}

            public function testScope(Request $request, string $scope): void
            {
                $this->requireScope($request, $scope);
            }

            public function getUser(): ?User
            {
                $user = new User();
                $user->email = 'scope_violator@test.com';
                return $user;
            }
        };

        $pat = new PersonalAccessToken();
        $pat->name = 'Read Only Token';
        $pat->scopes = ['content:read']; // missing schema:write!

        $request = new Request();
        $request->attributes->set('_pat', $pat);

        $this->expectException(AccessDeniedHttpException::class);
        $tester->testScope($request, 'schema:write');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LocaleController Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function testLocaleControllerRateLimiting(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $translator = $this->createMockTranslator();
        $audit = $this->createMock(AuditService::class);
        $rateLimiter = $this->createRejectingLimiter('test_jwt_ttl_reject');

        $controller = new LocaleController($em, $translator, $audit, $rateLimiter);

        $request = new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['locale' => 'fr']));
        $response = $controller->update($request);

        $this->assertSame(429, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('RATE_LIMIT_EXCEEDED', $data['code']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // UserController Quota and Rate Limiting Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function testUserControllerRateLimiting(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $roleRepo = $this->createMock(RoleRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $translator = $this->createMockTranslator();
        $audit = $this->createMock(AuditService::class);
        $rateLimiter = $this->createRejectingLimiter('test_jwt_ttl_reject');

        $controller = new UserController($userRepo, $roleRepo, $em, $hasher, $translator, $audit, null, $rateLimiter);

        $request = new Request();
        $response = $controller->index($request);

        $this->assertSame(429, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('RATE_LIMIT_EXCEEDED', $data['code']);
    }

    public function testUserControllerCapacityLimitEnforcement(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('count')->willReturn(UserController::MAX_USERS_COUNT); // 500

        $roleRepo = $this->createMock(RoleRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $translator = $this->createMockTranslator();
        $audit = $this->createMock(AuditService::class);
        $rateLimiter = $this->createAcceptingLimiter('test_accept_limiter');

        $controller = new UserController($userRepo, $roleRepo, $em, $hasher, $translator, $audit, null, $rateLimiter);

        $request = new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => 'newuser@jambostack.site',
            'name' => 'New User',
            'password' => 'Str0ngP@ssword2026!',
        ]));

        $response = $controller->create($request);
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('MAX_USERS_EXCEEDED', $data['code']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RoleController Quota and Rate Limiting Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function testRoleControllerRateLimiting(): void
    {
        $roleRepo = $this->createMock(RoleRepository::class);
        $permRepo = $this->createMock(PermissionRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $translator = $this->createMockTranslator();
        $audit = $this->createMock(AuditService::class);
        $rateLimiter = $this->createRejectingLimiter('test_jwt_ttl_reject');

        $controller = new RoleController($roleRepo, $permRepo, $em, $hasher, $translator, $audit, $rateLimiter);

        $request = new Request();
        $response = $controller->index($request);

        $this->assertSame(429, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('RATE_LIMIT_EXCEEDED', $data['code']);
    }

    public function testRoleControllerQuotaLimitEnforcement(): void
    {
        $roleRepo = $this->createMock(RoleRepository::class);
        $roleRepo->method('count')->willReturn(RoleController::MAX_ROLES_COUNT); // 100

        $permRepo = $this->createMock(PermissionRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $translator = $this->createMockTranslator();
        $audit = $this->createMock(AuditService::class);
        $rateLimiter = $this->createAcceptingLimiter('test_accept_limiter');

        $controller = new RoleController($roleRepo, $permRepo, $em, $hasher, $translator, $audit, $rateLimiter);

        $request = new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'custom_editor_role',
            'label' => 'Custom Editor Role',
        ]));

        $response = $controller->create($request);
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('MAX_ROLES_EXCEEDED', $data['code']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PermissionController Quota and Rate Limiting Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function testPermissionControllerRateLimiting(): void
    {
        $permRepo = $this->createMock(PermissionRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $translator = $this->createMockTranslator();
        $audit = $this->createMock(AuditService::class);
        $rateLimiter = $this->createRejectingLimiter('test_jwt_ttl_reject');

        $controller = new PermissionController($permRepo, $em, $hasher, $translator, $audit, $rateLimiter);

        $request = new Request();
        $response = $controller->index($request);

        $this->assertSame(429, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('RATE_LIMIT_EXCEEDED', $data['code']);
    }

    public function testPermissionControllerQuotaLimitEnforcement(): void
    {
        $permRepo = $this->createMock(PermissionRepository::class);
        $permRepo->method('count')->willReturn(PermissionController::MAX_PERMISSIONS_COUNT); // 300

        $em = $this->createMock(EntityManagerInterface::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $translator = $this->createMockTranslator();
        $audit = $this->createMock(AuditService::class);
        $rateLimiter = $this->createAcceptingLimiter('test_accept_limiter');

        $controller = new PermissionController($permRepo, $em, $hasher, $translator, $audit, $rateLimiter);

        $request = new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'custom.permission.manage',
            'label' => 'Custom Permission',
        ]));

        $response = $controller->create($request);
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('MAX_PERMISSIONS_EXCEEDED', $data['code']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Cycle #98 i18n Translation Completeness Test
    // ─────────────────────────────────────────────────────────────────────────

    public function testCycle98TranslationsCompleteInAllLocales(): void
    {
        $locales = ['en', 'fr', 'es', 'ar'];
        $requiredKeys = [
            'project.jwt_ttl.rate_limit_exceeded',
            'admin_api.ping.rate_limit_exceeded',
            'settings.locale.rate_limit_exceeded',
            'user.admin.rate_limit_exceeded',
            'user.admin.max_users_exceeded',
            'role.admin.rate_limit_exceeded',
            'role.admin.max_roles_exceeded',
            'permission.admin.rate_limit_exceeded',
            'permission.admin.max_permissions_exceeded',
        ];

        foreach ($locales as $loc) {
            $filePath = __DIR__ . '/../../../translations/messages.' . $loc . '.php';
            $this->assertFileExists($filePath, "Translation file missing for {$loc}");

            $messages = require $filePath;
            $this->assertIsArray($messages, "Translation file {$loc} did not return an array");

            foreach ($requiredKeys as $key) {
                $this->assertArrayHasKey($key, $messages, "Key '{$key}' missing from messages.{$loc}.php");
                $this->assertNotEmpty($messages[$key], "Key '{$key}' is empty in messages.{$loc}.php");
            }
        }
    }
}
