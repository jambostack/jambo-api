<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\AutomationController;
use App\Controller\ColumnSettingController;
use App\Controller\CommentController;
use App\Controller\DeploymentController;
use App\Controller\FlowController;
use App\Controller\InsightsController;
use App\Controller\MediaFolderController;
use App\Controller\NotificationController;
use App\Controller\ProjectController;
use App\Controller\PublicShareController;
use App\Controller\ShareController;
use App\Controller\UserManagement\PermissionController;
use App\Controller\UserManagement\RoleController;
use App\Controller\UserManagement\UserController;
use App\Controller\VersioningController;
use App\Entity\User;
use App\Repository\AutomationRepository;
use App\Repository\AutomationRunRepository;
use App\Repository\CollectionRepository;
use App\Repository\ColumnSettingRepository;
use App\Repository\CommentRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\ContentVersionRepository;
use App\Repository\DeploymentRepository;
use App\Repository\MediaFolderRepository;
use App\Repository\NotificationRepository;
use App\Repository\PermissionRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Repository\RoleRepository;
use App\Repository\ShareRepository;
use App\Repository\UserRepository;
use App\Service\ApiTokenChecker;
use App\Service\AuditService;
use App\Service\Automation\AutomationEngine;
use App\Service\Automation\AutomationExportImportService;
use App\Service\Automation\AutomationQuotaService;
use App\Service\EavDataFormatterService;
use App\Service\EndUserSchemaSeeder;
use App\Service\Flow\FlowInterpreter;
use App\Service\Flow\FlowValidator;
use App\Service\Flow\NodeRegistry;
use App\Service\Insights\InsightsService;
use App\Service\Share\ShareService;
use App\Service\VersioningService;
use App\Service\WebhookSecretService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

class Cycle124SecurityHardeningTest extends TestCase
{
    private function createRejectingLimiterFactory(string $id = 'test_reject', string $identifier = '127.0.0.1'): RateLimiterFactory
    {
        $factory = new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '60 seconds'],
            new InMemoryStorage()
        );
        $factory->create($identifier)->consume(1);
        return $factory;
    }

    public function testPublicShareControllerReturnsRateLimitHeadersOn429(): void
    {
        $shares = $this->createMock(ShareService::class);
        $formatter = $this->createMock(EavDataFormatterService::class);
        $limiter = $this->createRejectingLimiterFactory('public_share_test');

        $controller = new PublicShareController($shares, $formatter, $limiter);
        $request = Request::create('/share/abc12345.json', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->show('abc12345', 'json', $request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function testShareControllerReturnsRateLimitHeadersOn429(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $shareRepo = $this->createMock(ShareRepository::class);
        $shareService = $this->createMock(ShareService::class);
        $limiter = $this->createRejectingLimiterFactory('share_test');

        $controller = new ShareController($projectRepo, $entryRepo, $shareRepo, $shareService, $limiter);
        $request = Request::create('/api/projects/a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11/shares', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->store('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', $request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function testMediaFolderControllerReturnsRateLimitHeadersOn429(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $projectRepo = $this->createMock(ProjectRepository::class);
        $folderRepo = $this->createMock(MediaFolderRepository::class);
        $limiter = $this->createRejectingLimiterFactory('media_folder_test');

        $controller = new MediaFolderController($em, $projectRepo, $folderRepo, $limiter);
        $request = Request::create('/api/projects/a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11/media-folders', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->index('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', $request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function testNotificationControllerReturnsRateLimitHeadersOn429(): void
    {
        $notifRepo = $this->createMock(NotificationRepository::class);
        $limiter = $this->createRejectingLimiterFactory('notif_test');

        $controller = new NotificationController($notifRepo, $limiter);
        $request = Request::create('/api/notifications', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->index($request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function testCommentControllerReturnsRateLimitHeadersOn429(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $colRepo = $this->createMock(CollectionRepository::class);
        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $commentRepo = $this->createMock(CommentRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $limiter = $this->createRejectingLimiterFactory('comment_test');

        $controller = new CommentController($projectRepo, $colRepo, $entryRepo, $commentRepo, $em, null, $limiter);
        $request = Request::create('/api/projects/a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11/collections/posts/content/1/comments', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->index('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'posts', 1, $request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function testAutomationControllerReturnsRateLimitHeadersOn429(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $projectRepo = $this->createMock(ProjectRepository::class);
        $autoRepo = $this->createMock(AutomationRepository::class);
        $runRepo = $this->createMock(AutomationRunRepository::class);
        $memberRepo = $this->createMock(ProjectMemberRepository::class);
        $flowInterpreter = $this->createMock(FlowInterpreter::class);
        $nodeRegistry = $this->createMock(NodeRegistry::class);
        $engine = $this->createMock(AutomationEngine::class);
        $limiter = $this->createRejectingLimiterFactory('auto_test');

        $controller = new AutomationController(
            $em,
            $projectRepo,
            $autoRepo,
            $runRepo,
            $memberRepo,
            $flowInterpreter,
            $nodeRegistry,
            $engine,
            null,
            null,
            null,
            $limiter
        );
        $request = Request::create('/api/projects/a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11/automations', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->index('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', $request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function testFlowControllerReturnsRateLimitHeadersOn429(): void
    {
        $registry = $this->createMock(NodeRegistry::class);
        $limiter = $this->createRejectingLimiterFactory('flow_test');

        $controller = new FlowController($registry, null, null, $limiter);
        $request = Request::create('/api/automations/node-catalog', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->nodeCatalog($request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function testInsightsControllerReturnsRateLimitHeadersOn429(): void
    {
        $insights = $this->createMock(InsightsService::class);
        $projectRepo = $this->createMock(ProjectRepository::class);
        $limiter = $this->createRejectingLimiterFactory('insights_test');

        $controller = new InsightsController($insights, $projectRepo, null, $limiter);
        $request = Request::create('/insights/summary', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->summary($request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function testVersioningControllerReturnsRateLimitHeadersOn429(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $versioning = $this->createMock(VersioningService::class);
        $versionRepo = $this->createMock(ContentVersionRepository::class);
        $limiter = $this->createRejectingLimiterFactory('versioning_test');

        $controller = new VersioningController(
            $em,
            $versioning,
            $versionRepo,
            null,
            $limiter
        );
        $request = Request::create('/api/projects/a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11/collections/posts/entries/a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12/versions', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->list('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'posts', 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a12', $request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function testProjectControllerReturnsRateLimitHeadersOn429(): void
    {
        $projRepo = $this->createMock(ProjectRepository::class);
        $userRepo = $this->createMock(UserRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $memberRepo = $this->createMock(ProjectMemberRepository::class);
        $roleRepo = $this->createMock(RoleRepository::class);
        $seeder = $this->createMock(EndUserSchemaSeeder::class);
        $tokenChecker = $this->createMock(ApiTokenChecker::class);
        $secretService = $this->createMock(WebhookSecretService::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $limiter = $this->createRejectingLimiterFactory('project_test');

        $controller = new ProjectController(
            $projRepo,
            $userRepo,
            $em,
            $memberRepo,
            $roleRepo,
            $seeder,
            $tokenChecker,
            $secretService,
            $translator,
            $limiter
        );
        $request = Request::create('/api/projects', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->create($request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function testColumnSettingControllerReturnsRateLimitHeadersOn429(): void
    {
        $projRepo = $this->createMock(ProjectRepository::class);
        $colRepo = $this->createMock(CollectionRepository::class);
        $settingRepo = $this->createMock(ColumnSettingRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $limiter = $this->createRejectingLimiterFactory('column_test');

        $controller = new ColumnSettingController(
            $projRepo,
            $colRepo,
            $settingRepo,
            $em,
            null,
            $limiter
        );
        $request = Request::create('/api/projects/a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11/collections/posts/column-settings', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->show('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11', 'posts', $request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function testDeploymentControllerReturnsRateLimitHeadersOn429(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $depRepo = $this->createMock(DeploymentRepository::class);
        $projRepo = $this->createMock(ProjectRepository::class);
        $limiter = $this->createRejectingLimiterFactory('deployment_test');

        $controller = new DeploymentController(
            $em,
            $depRepo,
            $projRepo,
            $limiter
        );
        $request = Request::create('/api/deployments/hook', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $response = $controller->hook($request);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function testUserManagementControllersReturnRateLimitHeadersOn429(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $roleRepo = $this->createMock(RoleRepository::class);
        $permRepo = $this->createMock(PermissionRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $auditService = $this->createMock(AuditService::class);
        $limiter = $this->createRejectingLimiterFactory('usermgmt_test');

        $userController = new UserController(
            $userRepo,
            $roleRepo,
            $em,
            $hasher,
            $translator,
            $auditService,
            null,
            $limiter
        );
        $request = Request::create('/api/admin/users', 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $userResponse = $userController->index($request);

        $this->assertSame(429, $userResponse->getStatusCode());
        $this->assertTrue($userResponse->headers->has('Retry-After'));
        $this->assertTrue($userResponse->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($userResponse->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($userResponse->headers->has('X-RateLimit-Reset'));

        $roleController = new RoleController(
            $roleRepo,
            $permRepo,
            $em,
            $hasher,
            $translator,
            $auditService,
            $limiter
        );
        $roleResponse = $roleController->index($request);

        $this->assertSame(429, $roleResponse->getStatusCode());
        $this->assertTrue($roleResponse->headers->has('Retry-After'));
        $this->assertTrue($roleResponse->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($roleResponse->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($roleResponse->headers->has('X-RateLimit-Reset'));

        $permController = new PermissionController(
            $permRepo,
            $em,
            $hasher,
            $translator,
            $auditService,
            $limiter
        );
        $permResponse = $permController->index($request);

        $this->assertSame(429, $permResponse->getStatusCode());
        $this->assertTrue($permResponse->headers->has('Retry-After'));
        $this->assertTrue($permResponse->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($permResponse->headers->has('X-RateLimit-Remaining'));
        $this->assertTrue($permResponse->headers->has('X-RateLimit-Reset'));
    }

    public function testTranslationSymmetryAcrossAllFourLocales(): void
    {
        $locales = ['en', 'fr', 'es', 'ar'];
        $dicts = [];
        foreach ($locales as $loc) {
            $path = __DIR__ . "/../../translations/messages.{$loc}.php";
            $this->assertFileExists($path);
            $dicts[$loc] = require $path;
            $this->assertIsArray($dicts[$loc]);
        }

        $enCount = count($dicts['en']);
        foreach ($locales as $loc) {
            $this->assertCount(
                $enCount,
                $dicts[$loc],
                sprintf('Locale %s must have exactly %d keys, got %d', $loc, $enCount, count($dicts[$loc]))
            );
        }

        $enKeys = array_keys($dicts['en']);
        foreach (['fr', 'es', 'ar'] as $otherLoc) {
            $diff = array_diff($enKeys, array_keys($dicts[$otherLoc]));
            $this->assertEmpty($diff, sprintf('Locale %s is missing keys: %s', $otherLoc, implode(', ', array_slice($diff, 0, 5))));
        }
    }
}
