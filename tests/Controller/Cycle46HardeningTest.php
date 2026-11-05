<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\BackupController;
use App\Controller\PluginController;
use App\Controller\RealtimeController;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\Role;
use App\Entity\User;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Service\AuditService;
use App\Service\Backup\BackupManager;
use App\Service\Mercure\MercureTopicSecurityService;
use App\Service\MercurePublisher;
use App\Service\Plugin\PluginManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
class Cycle46HardeningTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/jambo_test_cycle46_' . bin2hex(random_bytes(4));
        @mkdir($this->tempDir . '/var/plugins', 0777, true);
        @mkdir($this->tempDir . '/var/backups', 0777, true);
        @mkdir($this->tempDir . '/var/realtime', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = scandir($dir);
        if ($files === false) {
            return;
        }
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $p = $dir . '/' . $file;
            is_dir($p) ? $this->removeDirectory($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function createContainerWithUser(?User $user = null): Container
    {
        $container = new Container();
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        if ($user !== null) {
            $token = $this->createMock(TokenInterface::class);
            $token->method('getUser')->willReturn($user);
            $tokenStorage->method('getToken')->willReturn($token);
        } else {
            $tokenStorage->method('getToken')->willReturn(null);
        }
        $container->set('security.token_storage', $tokenStorage);

        $authChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturn(true);
        $container->set('security.authorization_checker', $authChecker);

        $bag = new ParameterBag([
            'kernel.project_dir'  => $this->tempDir,
            'mercure_jwt_secret'  => 'super-secret-jwt-key-with-at-least-32-characters-length!',
            'mercure_public_url'  => 'http://localhost:3000/.well-known/mercure',
        ]);
        $container->set('parameter_bag', $bag);

        return $container;
    }

    private function createTranslatorMock(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(function (string $id, array $params = []) {
            return $id;
        });
        return $translator;
    }

    private function createRateLimiter(): RateLimiterFactory
    {
        return new RateLimiterFactory([
            'id'       => 'test',
            'policy'   => 'sliding_window',
            'limit'    => 1000,
            'interval' => '60 seconds',
        ], new InMemoryStorage());
    }

    // ─── MercureTopicSecurityService Tests ───────────────────────────────

    public function testMercureTopicValidationDefault(): void
    {
        $memberRepo = $this->createMock(ProjectMemberRepository::class);
        $service = new MercureTopicSecurityService($memberRepo, $this->createTranslatorMock());

        $uuid = '11111111-2222-3333-4444-555555555555';
        $res = $service->validateAndFilterTopics($uuid, null);
        $this->assertArrayHasKey('topics', $res);
        $this->assertContains("projects/{$uuid}", $res['topics']);
        $this->assertContains("projects/{$uuid}/content", $res['topics']);
    }

    public function testMercureTopicRejectsCrossTenant(): void
    {
        $memberRepo = $this->createMock(ProjectMemberRepository::class);
        $service = new MercureTopicSecurityService($memberRepo, $this->createTranslatorMock());

        $uuid1 = '11111111-2222-3333-4444-555555555555';
        $uuid2 = '99999999-8888-7777-6666-555555555555';

        $res = $service->validateAndFilterTopics($uuid1, ["projects/{$uuid2}/secret"]);
        $this->assertArrayHasKey('error', $res);
        $this->assertSame('realtime.topic_access_denied', $res['error']);
    }

    public function testMercureTopicRejectsWildcardsAndTraversal(): void
    {
        $memberRepo = $this->createMock(ProjectMemberRepository::class);
        $service = new MercureTopicSecurityService($memberRepo, $this->createTranslatorMock());

        $uuid = '11111111-2222-3333-4444-555555555555';

        $res1 = $service->validateAndFilterTopics($uuid, ["projects/{$uuid}/*"]);
        $this->assertArrayHasKey('error', $res1);

        $res2 = $service->validateAndFilterTopics($uuid, ["projects/{$uuid}/../other"]);
        $this->assertArrayHasKey('error', $res2);

        $res3 = $service->validateAndFilterTopics($uuid, ["projects/{$uuid}\0null"]);
        $this->assertArrayHasKey('error', $res3);
    }

    public function testMercureCanUserPublish(): void
    {
        $memberRepo = $this->createMock(ProjectMemberRepository::class);
        $service = new MercureTopicSecurityService($memberRepo, $this->createTranslatorMock());

        $project = new Project();
        $project->uuid = Uuid::fromString('11111111-2222-3333-4444-555555555555');

        // Anonymous user cannot publish
        $this->assertFalse($service->canUserPublish(null, $project));

        // Super admin can publish
        $superAdmin = new User();
        $superAdmin->email = 'superadmin@test.com';
        $superAdmin->roles = ['ROLE_SUPER_ADMIN'];
        $this->assertTrue($service->canUserPublish($superAdmin, $project));

        // Editor member can publish
        $user = new User();
        $user->email = 'editor@test.com';
        $user->roles = ['ROLE_USER'];
        $editorRole = new Role();
        $editorRole->name = 'editor';
        $member = new ProjectMember();
        $member->role = $editorRole;

        $memberRepo->method('findActiveByUserAndProject')->willReturn($member);
        $this->assertTrue($service->canUserPublish($user, $project));
    }

    // ─── RealtimeController Tests ────────────────────────────────────────

    public function testRealtimeTokenSuccessAndHeaders(): void
    {
        $projectUuid = '11111111-2222-3333-4444-555555555555';
        $project = new Project();
        $project->uuid = Uuid::fromString($projectUuid);

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $memberRepo = $this->createMock(ProjectMemberRepository::class);
        $member = new ProjectMember();
        $memberRepo->method('findActiveByUserAndProject')->willReturn($member);

        $topicSecurity = new MercureTopicSecurityService($memberRepo, $this->createTranslatorMock());

        $user = new User();
        $user->email = 'admin@test.com';
        $user->roles = ['ROLE_ADMIN'];

        $controller = new RealtimeController(
            $projectRepo,
            $memberRepo,
            true,
            $this->createRateLimiter(),
            $this->createTranslatorMock(),
            $topicSecurity,
            null
        );
        $controller->setContainer($this->createContainerWithUser($user));

        $request = Request::create("/api/projects/{$projectUuid}/realtime/token", 'GET');
        $response = $controller->token($projectUuid, $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->headers->has('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['enabled']);
        $this->assertNotEmpty($data['token']);
        $this->assertContains("projects/{$projectUuid}", $data['topics']);
    }

    public function testRealtimePublishAuthorized(): void
    {
        $projectUuid = '11111111-2222-3333-4444-555555555555';
        $project = new Project();
        $project->uuid = Uuid::fromString($projectUuid);

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $memberRepo = $this->createMock(ProjectMemberRepository::class);
        $topicSecurity = new MercureTopicSecurityService($memberRepo, $this->createTranslatorMock());

        $user = new User();
        $user->email = 'superadmin@test.com';
        $user->roles = ['ROLE_SUPER_ADMIN'];

        $publisher = $this->createMock(MercurePublisher::class);
        $publisher->expects($this->once())->method('contentChanged');

        $controller = new RealtimeController(
            $projectRepo,
            $memberRepo,
            true,
            $this->createRateLimiter(),
            $this->createTranslatorMock(),
            $topicSecurity,
            $publisher
        );
        $controller->setContainer($this->createContainerWithUser($user));

        $payload = json_encode([
            'topic' => "projects/{$projectUuid}/content",
            'event' => 'custom.event',
            'data'  => ['title' => 'Test Realtime'],
        ]);
        $request = Request::create("/api/projects/{$projectUuid}/realtime/publish", 'POST', [], [], [], [], $payload);
        $response = $controller->publish($projectUuid, $request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('published', $data['status']);
        $this->assertSame("projects/{$projectUuid}/content", $data['topic']);
    }

    public function testRealtimePublishDeniedForViewer(): void
    {
        $projectUuid = '11111111-2222-3333-4444-555555555555';
        $project = new Project();
        $project->uuid = Uuid::fromString($projectUuid);

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $memberRepo = $this->createMock(ProjectMemberRepository::class);
        $viewerRole = new Role();
        $viewerRole->name = 'viewer';
        $member = new ProjectMember();
        $member->role = $viewerRole;
        $memberRepo->method('findActiveByUserAndProject')->willReturn($member);

        $topicSecurity = new MercureTopicSecurityService($memberRepo, $this->createTranslatorMock());

        $user = new User();
        $user->email = 'viewer@test.com';
        $user->roles = ['ROLE_USER'];

        $controller = new RealtimeController(
            $projectRepo,
            $memberRepo,
            true,
            $this->createRateLimiter(),
            $this->createTranslatorMock(),
            $topicSecurity,
            null
        );
        $controller->setContainer($this->createContainerWithUser($user));

        $payload = json_encode(['topic' => "projects/{$projectUuid}"]);
        $request = Request::create("/api/projects/{$projectUuid}/realtime/publish", 'POST', [], [], [], [], $payload);
        $response = $controller->publish($projectUuid, $request);

        $this->assertSame(403, $response->getStatusCode());
    }

    // ─── PluginManager & PluginController Tests ──────────────────────────

    public function testPluginValidationAndRegister(): void
    {
        $manager = new PluginManager($this->tempDir, $this->createTranslatorMock());

        // Invalid ID
        $res1 = $manager->validateManifest(['id' => 'INVALID_CAPS!', 'name' => 'Test', 'version' => '1.0.0']);
        $this->assertFalse($res1['valid']);

        // Invalid Permission
        $res2 = $manager->validateManifest([
            'id'          => 'test-plugin',
            'name'        => 'Test Plugin',
            'version'     => '1.0.0',
            'permissions' => ['root:destroy_all'],
        ]);
        $this->assertFalse($res2['valid']);

        // Valid Manifest
        $validData = [
            'id'          => 'analytics-addon',
            'name'        => 'Analytics Addon',
            'version'     => '1.2.0',
            'description' => 'Realtime analytics extension',
            'permissions' => ['content:read', 'analytics:read'],
            'hooks'       => ['content.after_create'],
            'entrypoint'  => 'addon.php',
        ];
        $res3 = $manager->validateManifest($validData);
        $this->assertTrue($res3['valid']);

        // Register
        $registered = $manager->registerPlugin($res3['manifest']);
        $this->assertTrue($registered);

        // Retrieve
        $plugin = $manager->getPlugin('analytics-addon');
        $this->assertNotNull($plugin);
        $this->assertSame('analytics-addon', $plugin['id']);
        $this->assertSame('1.2.0', $plugin['version']);

        // Toggle
        $toggled = $manager->togglePlugin('analytics-addon', false);
        $this->assertFalse($toggled['enabled']);

        // Delete
        $deleted = $manager->deletePlugin('analytics-addon');
        $this->assertTrue($deleted);
        $this->assertNull($manager->getPlugin('analytics-addon'));
    }

    public function testPluginControllerAdminRBAC(): void
    {
        $manager = new PluginManager($this->tempDir, $this->createTranslatorMock());
        $auditService = $this->createMock(AuditService::class);

        $controller = new PluginController($manager, $auditService, $this->createRateLimiter(), $this->createTranslatorMock());

        // Anonymous user -> 401
        $controller->setContainer($this->createContainerWithUser(null));
        $res1 = $controller->list();
        $this->assertSame(401, $res1->getStatusCode());

        // Regular user -> 403
        $regularUser = new User();
        $regularUser->email = 'user@test.com';
        $regularUser->roles = ['ROLE_USER'];
        $controller->setContainer($this->createContainerWithUser($regularUser));
        $res2 = $controller->list();
        $this->assertSame(403, $res2->getStatusCode());

        // Admin user -> 200
        $adminUser = new User();
        $adminUser->email = 'admin@test.com';
        $adminUser->roles = ['ROLE_ADMIN'];
        $controller->setContainer($this->createContainerWithUser($adminUser));
        $res3 = $controller->list();
        $this->assertSame(200, $res3->getStatusCode());
    }

    // ─── BackupManager & BackupController Tests ──────────────────────────

    public function testBackupCreationAndSafetyVerification(): void
    {
        $manager = new BackupManager($this->tempDir, null, $this->createTranslatorMock());

        // Create backup
        $backup = $manager->createBackup('cycle46-test');
        $this->assertFileExists($backup['path']);
        $this->assertMatchesRegularExpression(BackupManager::FILENAME_REGEX, $backup['filename']);

        // List backups
        $list = $manager->listBackups();
        $this->assertCount(1, $list);
        $this->assertSame($backup['filename'], $list[0]['filename']);

        // Safety verification on clean file
        $safety = $manager->verifySqlDumpSafety($backup['path']);
        $this->assertTrue($safety['safe']);

        // Safety verification on dangerous command injection
        $maliciousPath = $this->tempDir . '/var/backups/backup-malicious-20261105-123456-abcd1234.sql';
        file_put_contents($maliciousPath, "SELECT * FROM users INTO OUTFILE '/tmp/dump.txt';");
        $maliciousSafety = $manager->verifySqlDumpSafety($maliciousPath);
        $this->assertFalse($maliciousSafety['safe']);
        $this->assertSame('backup.dangerous_command_detected', $maliciousSafety['error']);

        // Restore requires confirmation
        $resRestore = $manager->restoreBackup($backup['path'], false);
        $this->assertFalse($resRestore['success']);
        $this->assertSame('backup.confirmation_required', $resRestore['error']);

        $resRestoreConfirmed = $manager->restoreBackup($backup['path'], true);
        $this->assertTrue($resRestoreConfirmed['success']);

        // Delete backup
        $deleted = $manager->deleteBackup($backup['filename']);
        $this->assertTrue($deleted);
        $this->assertFileDoesNotExist($backup['path']);
    }

    public function testBackupControllerSuperAdminOnly(): void
    {
        $manager = new BackupManager($this->tempDir, null, $this->createTranslatorMock());
        $auditService = $this->createMock(AuditService::class);

        $controller = new BackupController($manager, $auditService, $this->createRateLimiter(), $this->createTranslatorMock());

        // Normal admin is NOT allowed (requires SUPER_ADMIN)
        $adminUser = new User();
        $adminUser->email = 'admin@test.com';
        $adminUser->roles = ['ROLE_ADMIN'];
        $controller->setContainer($this->createContainerWithUser($adminUser));

        $resAdmin = $controller->list();
        $this->assertSame(403, $resAdmin->getStatusCode());

        // Super Admin is allowed
        $superAdmin = new User();
        $superAdmin->email = 'superadmin@test.com';
        $superAdmin->roles = ['ROLE_SUPER_ADMIN'];
        $controller->setContainer($this->createContainerWithUser($superAdmin));

        $resSuper = $controller->list();
        $this->assertSame(200, $resSuper->getStatusCode());

        // Create backup via controller
        $reqCreate = Request::create('/api/admin/backups', 'POST', [], [], [], [], json_encode(['label' => 'test']));
        $resCreate = $controller->create($reqCreate);
        $this->assertSame(201, $resCreate->getStatusCode());
        $createData = json_decode($resCreate->getContent(), true);
        $filename = $createData['data']['filename'];

        // Download backup via controller
        $resDownload = $controller->download($filename);
        $this->assertInstanceOf(BinaryFileResponse::class, $resDownload);
        $this->assertStringContainsString('no-store', (string) $resDownload->headers->get('Cache-Control'));

        // Anti-traversal check on download
        $resTraversal = $controller->download('../../etc/passwd');
        $this->assertInstanceOf(JsonResponse::class, $resTraversal);
        $this->assertSame(404, $resTraversal->getStatusCode());

        // Delete backup via controller
        $resDelete = $controller->delete($filename);
        $this->assertSame(200, $resDelete->getStatusCode());
    }

    public function testRealtimePayloadBoundaries(): void
    {
        $projectUuid = '11111111-2222-3333-4444-555555555555';
        $project = new Project();
        $project->uuid = Uuid::fromString($projectUuid);

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $memberRepo = $this->createMock(ProjectMemberRepository::class);
        $topicSecurity = new MercureTopicSecurityService($memberRepo, $this->createTranslatorMock());

        $user = new User();
        $user->email = 'admin@test.com';
        $user->roles = ['ROLE_SUPER_ADMIN'];

        $controller = new RealtimeController(
            $projectRepo,
            $memberRepo,
            true,
            $this->createRateLimiter(),
            $this->createTranslatorMock(),
            $topicSecurity,
            null
        );
        $controller->setContainer($this->createContainerWithUser($user));

        // Payload > 64KB
        $largeData = str_repeat('A', 70000);
        $request = Request::create("/api/projects/{$projectUuid}/realtime/publish", 'POST', [], [], [], [], $largeData);
        $res = $controller->publish($projectUuid, $request);
        $this->assertSame(413, $res->getStatusCode());

        // Malformed event name
        $malformedEvent = json_encode(['event' => 'malicious/event; injection']);
        $reqEvent = Request::create("/api/projects/{$projectUuid}/realtime/publish", 'POST', [], [], [], [], $malformedEvent);
        $resEvent = $controller->publish($projectUuid, $reqEvent);
        $this->assertSame(422, $resEvent->getStatusCode());
    }

    public function testPluginManifestBoundaries(): void
    {
        $manager = new PluginManager($this->tempDir, $this->createTranslatorMock());

        // Name too long
        $res1 = $manager->validateManifest([
            'id'      => 'long-name-plugin',
            'name'    => str_repeat('X', 101),
            'version' => '1.0.0',
        ]);
        $this->assertFalse($res1['valid']);

        // Description too long
        $res2 = $manager->validateManifest([
            'id'          => 'long-desc-plugin',
            'name'        => 'Test',
            'version'     => '1.0.0',
            'description' => str_repeat('X', 501),
        ]);
        $this->assertFalse($res2['valid']);

        // Too many permissions
        $manyPerms = array_fill(0, 51, 'content:read');
        $res3 = $manager->validateManifest([
            'id'          => 'many-perms-plugin',
            'name'        => 'Test',
            'version'     => '1.0.0',
            'permissions' => $manyPerms,
        ]);
        $this->assertFalse($res3['valid']);

        // Entrypoint traversal
        $res4 = $manager->validateManifest([
            'id'         => 'traversal-plugin',
            'name'       => 'Test',
            'version'    => '1.0.0',
            'entrypoint' => '../evil.php',
        ]);
        $this->assertFalse($res4['valid']);
    }

    public function testBackupRestoreValidationAndLabelLimit(): void
    {
        $manager = new BackupManager($this->tempDir, null, $this->createTranslatorMock());
        $auditService = $this->createMock(AuditService::class);
        $controller = new BackupController($manager, $auditService, $this->createRateLimiter(), $this->createTranslatorMock());

        $superAdmin = new User();
        $superAdmin->email = 'superadmin@test.com';
        $superAdmin->roles = ['ROLE_SUPER_ADMIN'];
        $controller->setContainer($this->createContainerWithUser($superAdmin));

        // Label > 50 characters
        $longLabelReq = Request::create('/api/admin/backups', 'POST', [], [], [], [], json_encode(['label' => str_repeat('L', 51)]));
        $resLongLabel = $controller->create($longLabelReq);
        $this->assertSame(422, $resLongLabel->getStatusCode());

        // Missing filename in restore
        $noFileReq = Request::create('/api/admin/backups/restore', 'POST', [], [], [], [], json_encode(['confirm' => true]));
        $resNoFile = $controller->restore($noFileReq);
        $this->assertSame(422, $resNoFile->getStatusCode());

        // Non-existent filename in restore
        $nonExistentReq = Request::create('/api/admin/backups/restore', 'POST', [], [], [], [], json_encode(['filename' => 'backup-nonexistent-12345678.sql', 'confirm' => true]));
        $resNonExistent = $controller->restore($nonExistentReq);
        $this->assertSame(404, $resNonExistent->getStatusCode());
    }
}
