<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ColumnSettingController;
use App\Controller\PluginController;
use App\Controller\ProjectPluginController;
use App\Entity\Collection;
use App\Entity\ColumnSetting;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\CollectionRepository;
use App\Repository\ColumnSettingRepository;
use App\Repository\ProjectRepository;
use App\Service\AuditService;
use App\Service\Plugin\PluginManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class Cycle89PluginAndColumnSettingHardeningTest extends TestCase
{
    private string $tempPluginDir;

    protected function setUp(): void
    {
        $this->tempPluginDir = sys_get_temp_dir() . '/jambo_test_plugins_' . bin2hex(random_bytes(6));
        @mkdir($this->tempPluginDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempPluginDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = scandir($dir) ?: [];
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $p = $dir . '/' . $file;
            is_dir($p) ? $this->removeDirectory($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            fn (string $id, array $params = []) => strtr($id, $params)
        );
        return $translator;
    }

    private function createPluginManager(): PluginManager
    {
        $tempDir = dirname($this->tempPluginDir);
        $pm = new PluginManager($tempDir, $this->createTranslator());

        $ref = new \ReflectionProperty(PluginManager::class, 'pluginsDir');
        $ref->setValue($pm, $this->tempPluginDir);

        return $pm;
    }

    private function createMockUser(array $roles = ['ROLE_USER']): User
    {
        $user = new User();
        $user->id = 42;
        $user->email = 'testuser@example.com';
        $user->roles = $roles;
        return $user;
    }

    private function setupControllerSecurity(object $controller, ?User $user, bool $granted = true): void
    {
        $authChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturn($granted);

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        if ($user !== null) {
            $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
            $tokenStorage->method('getToken')->willReturn($token);
        } else {
            $tokenStorage->method('getToken')->willReturn(null);
        }

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(fn ($id) => in_array($id, ['security.authorization_checker', 'security.token_storage'], true));
        $container->method('get')->willReturnCallback(function ($id) use ($authChecker, $tokenStorage) {
            if ($id === 'security.authorization_checker') {
                return $authChecker;
            }
            if ($id === 'security.token_storage') {
                return $tokenStorage;
            }
            return null;
        });

        $ref = new \ReflectionClass($controller);
        $parent = $ref->getParentClass();
        while ($parent && $parent->getName() !== 'Symfony\Bundle\FrameworkBundle\Controller\AbstractController') {
            $parent = $parent->getParentClass();
        }
        if ($parent) {
            $prop = $parent->getProperty('container');
            $prop->setValue($controller, $container);
        }
    }

    // ════════════════════ 1. PluginManager Manifest & Quota Validation ════════════════════

    public function testManifestValidationRejectsNonArray(): void
    {
        $pm = $this->createPluginManager();
        $res = $pm->validateManifest('invalid');
        $this->assertFalse($res['valid']);
        $this->assertSame('plugin.invalid_json', $res['error']);
    }

    public function testManifestValidationRejectsInvalidIdFormat(): void
    {
        $pm = $this->createPluginManager();
        $res = $pm->validateManifest(['id' => 'INVALID ID WITH SPACES!']);
        $this->assertFalse($res['valid']);
        $this->assertSame('plugin.invalid_id', $res['error']);
    }

    public function testManifestValidationRejectsReservedIds(): void
    {
        $pm = $this->createPluginManager();
        foreach (PluginManager::RESERVED_PLUGIN_IDS as $reservedId) {
            $res = $pm->validateManifest([
                'id' => $reservedId,
                'name' => 'Test Plugin',
                'version' => '1.0.0',
            ]);
            $this->assertFalse($res['valid']);
            $this->assertSame('plugin.reserved_id', $res['error']);
        }
    }

    public function testManifestValidationRejectsInvalidSemVer(): void
    {
        $pm = $this->createPluginManager();
        $res = $pm->validateManifest([
            'id' => 'my-plugin',
            'name' => 'My Plugin',
            'version' => 'invalid-semver',
        ]);
        $this->assertFalse($res['valid']);
        $this->assertSame('plugin.invalid_version', $res['error']);
    }

    public function testManifestValidationRejectsTooManyPermissions(): void
    {
        $pm = $this->createPluginManager();
        $perms = array_fill(0, PluginManager::MAX_PERMISSIONS_COUNT + 1, 'content:read');
        $res = $pm->validateManifest([
            'id' => 'my-plugin',
            'name' => 'My Plugin',
            'version' => '1.0.0',
            'permissions' => $perms,
        ]);
        $this->assertFalse($res['valid']);
        $this->assertSame('plugin.too_many_permissions', $res['error']);
    }

    public function testManifestValidationRejectsUnauthorizedPermission(): void
    {
        $pm = $this->createPluginManager();
        $res = $pm->validateManifest([
            'id' => 'my-plugin',
            'name' => 'My Plugin',
            'version' => '1.0.0',
            'permissions' => ['system:super_admin_execute'],
        ]);
        $this->assertFalse($res['valid']);
        $this->assertSame('plugin.invalid_permission', $res['error']);
    }

    public function testManifestValidationRejectsDangerousEntrypoint(): void
    {
        $pm = $this->createPluginManager();
        $dangerous = ['shell.sh', 'binary.exe', 'archive.phar', 'script.cmd', 'bad.bin', 'payload.cgi'];
        foreach ($dangerous as $file) {
            $res = $pm->validateManifest([
                'id' => 'danger-plugin',
                'name' => 'Danger Plugin',
                'version' => '1.0.0',
                'entrypoint' => $file,
            ]);
            $this->assertFalse($res['valid']);
            $this->assertSame('plugin.unsafe_entrypoint', $res['error']);
        }
    }

    public function testManifestValidationRejectsEntrypointPathTraversal(): void
    {
        $pm = $this->createPluginManager();
        $traversals = ['../outside.js', '/etc/passwd.js', 'php://filter/index.js'];
        foreach ($traversals as $path) {
            $res = $pm->validateManifest([
                'id' => 'traversal-plugin',
                'name' => 'Traversal Plugin',
                'version' => '1.0.0',
                'entrypoint' => $path,
            ]);
            $this->assertFalse($res['valid']);
            $this->assertSame('plugin.entrypoint_traversal', $res['error']);
        }
    }

    public function testManifestValidationAcceptsSafeEntrypoint(): void
    {
        $pm = $this->createPluginManager();
        $res = $pm->validateManifest([
            'id' => 'safe-plugin',
            'name' => 'Safe Plugin',
            'version' => '1.0.0',
            'entrypoint' => 'assets/dist/index.js',
            'permissions' => ['content:read'],
            'hooks' => ['content.after_create'],
        ]);
        $this->assertTrue($res['valid']);
        $this->assertSame('assets/dist/index.js', $res['manifest']['entrypoint']);
    }

    public function testManifestValidationRejectsDeepSettingsTree(): void
    {
        $pm = $this->createPluginManager();
        // Construct 7 levels deep settings (max is 5)
        $deep = ['l1' => ['l2' => ['l3' => ['l4' => ['l5' => ['l6' => 'too_deep']]]]]];
        $res = $pm->validateManifest([
            'id' => 'deep-plugin',
            'name' => 'Deep Plugin',
            'version' => '1.0.0',
            'settings' => $deep,
        ]);
        $this->assertFalse($res['valid']);
        $this->assertSame('plugin.invalid_settings_depth', $res['error']);
    }

    public function testManifestValidationRejectsPrototypePollutionInSettings(): void
    {
        $pm = $this->createPluginManager();
        $res = $pm->validateManifest([
            'id' => 'proto-plugin',
            'name' => 'Proto Plugin',
            'version' => '1.0.0',
            'settings' => ['__proto__' => ['polluted' => true]],
        ]);
        $this->assertFalse($res['valid']);
        $this->assertSame('plugin.invalid_settings_key', $res['error']);
    }

    public function testManifestValidationRejectsScriptInjectionInSettings(): void
    {
        $pm = $this->createPluginManager();
        $res = $pm->validateManifest([
            'id' => 'xss-plugin',
            'name' => 'XSS Plugin',
            'version' => '1.0.0',
            'settings' => ['webhook_url' => 'javascript:alert(1)'],
        ]);
        $this->assertFalse($res['valid']);
        $this->assertSame('plugin.invalid_settings_value', $res['error']);
    }

    // ════════════════════ 2. Multi-Tenant Project Plugin Quotas ════════════════════

    public function testProjectTierLimitsResolution(): void
    {
        $pm = $this->createPluginManager();
        $project = new Project();

        // Default tier (free)
        $this->assertSame(3, $pm->getProjectPluginLimit($project));

        // Starter tier
        $project->settings = ['tier' => 'starter'];
        $this->assertSame(10, $pm->getProjectPluginLimit($project));

        // Pro tier
        $project->settings = ['plan' => 'pro'];
        $this->assertSame(25, $pm->getProjectPluginLimit($project));

        // Enterprise tier
        $project->settings = ['tier' => 'enterprise'];
        $this->assertSame(100, $pm->getProjectPluginLimit($project));

        // Custom override
        $project->settings = ['max_plugins' => 15];
        $this->assertSame(15, $pm->getProjectPluginLimit($project));
    }

    public function testActivatePluginEnforcesProjectTierQuota(): void
    {
        $pm = $this->createPluginManager();
        $project = new Project();
        $project->settings = ['tier' => 'free']; // max 3 plugins

        // Register 4 plugins globally
        for ($i = 1; $i <= 4; $i++) {
            $pm->registerPlugin([
                'id' => "plugin-{$i}",
                'name' => "Plugin {$i}",
                'version' => '1.0.0',
                'enabled' => true,
            ]);
        }

        // Activate 1, 2, 3 -> success
        $this->assertTrue($pm->activatePluginForProject($project, 'plugin-1')['success']);
        $this->assertTrue($pm->activatePluginForProject($project, 'plugin-2')['success']);
        $this->assertTrue($pm->activatePluginForProject($project, 'plugin-3')['success']);

        // 4th activation must be rejected due to tier limit
        $res4 = $pm->activatePluginForProject($project, 'plugin-4');
        $this->assertFalse($res4['success']);
        $this->assertSame('plugin.quota.project_limit_exceeded', $res4['error']);

        // Deactivate plugin-1
        $pm->deactivatePluginForProject($project, 'plugin-1');
        $this->assertFalse($pm->isPluginActiveForProject($project, 'plugin-1'));

        // Now plugin-4 can be activated
        $res4Again = $pm->activatePluginForProject($project, 'plugin-4');
        $this->assertTrue($res4Again['success']);
        $this->assertTrue($pm->isPluginActiveForProject($project, 'plugin-4'));
    }

    public function testActivatePluginRejectsGloballyDisabledPlugin(): void
    {
        $pm = $this->createPluginManager();
        $project = new Project();

        $pm->registerPlugin([
            'id' => 'disabled-plugin',
            'name' => 'Disabled Plugin',
            'version' => '1.0.0',
            'enabled' => false,
        ]);

        $res = $pm->activatePluginForProject($project, 'disabled-plugin');
        $this->assertFalse($res['success']);
        $this->assertSame('plugin.globally_disabled', $res['error']);
    }

    public function testProjectPluginSettingsSanitization(): void
    {
        $pm = $this->createPluginManager();
        $project = new Project();

        $pm->registerPlugin([
            'id' => 'custom-plugin',
            'name' => 'Custom Plugin',
            'version' => '1.0.0',
            'enabled' => true,
        ]);

        $res = $pm->setProjectPluginSettings($project, 'custom-plugin', [
            'api_endpoint' => 'https://api.example.com',
            'display_label' => '<b>Safe Label</b>',
        ]);

        $this->assertTrue($res['success']);
        $this->assertSame('Safe Label', $res['settings']['display_label']);
    }

    // ════════════════════ 3. ColumnSettingController Hardening ════════════════════

    public function testColumnSettingRequiresAuth(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $collRepo = $this->createMock(CollectionRepository::class);
        $csRepo = $this->createMock(ColumnSettingRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $controller = new ColumnSettingController($projectRepo, $collRepo, $csRepo, $em, $this->createTranslator());
        $this->setupControllerSecurity($controller, null);

        $res = $controller->show('00000000-0000-0000-0000-000000000001', 'articles');
        $this->assertSame(401, $res->getStatusCode());
    }

    public function testColumnSettingResolveFailsOnInvalidUuid(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $collRepo = $this->createMock(CollectionRepository::class);
        $csRepo = $this->createMock(ColumnSettingRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $controller = new ColumnSettingController($projectRepo, $collRepo, $csRepo, $em, $this->createTranslator());
        $this->setupControllerSecurity($controller, $this->createMockUser());

        $res = $controller->show('invalid-uuid', 'articles');
        $this->assertSame(404, $res->getStatusCode());
        $body = json_decode($res->getContent(), true);
        $this->assertSame('PROJECT_NOT_FOUND', $body['code']);
    }

    public function testColumnSettingResolveFailsOnInvalidSlug(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $collRepo = $this->createMock(CollectionRepository::class);
        $csRepo = $this->createMock(ColumnSettingRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $controller = new ColumnSettingController($projectRepo, $collRepo, $csRepo, $em, $this->createTranslator());
        $this->setupControllerSecurity($controller, $this->createMockUser());

        $res = $controller->show('00000000-0000-0000-0000-000000000001', 'bad slug with spaces!!');
        $this->assertSame(404, $res->getStatusCode());
        $body = json_decode($res->getContent(), true);
        $this->assertSame('COLLECTION_NOT_FOUND', $body['code']);
    }

    public function testColumnSettingUpdateRejectsOversizedPayload(): void
    {
        $project = new Project();
        $collection = new Collection();

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);
        $collRepo = $this->createMock(CollectionRepository::class);
        $collRepo->method('findOneByProjectAndSlug')->willReturn($collection);
        $csRepo = $this->createMock(ColumnSettingRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $controller = new ColumnSettingController($projectRepo, $collRepo, $csRepo, $em, $this->createTranslator());
        $this->setupControllerSecurity($controller, $this->createMockUser());

        $oversized = json_encode(['visible_columns' => [str_repeat('x', 35000)]]);
        $req = new Request([], [], [], [], [], [], $oversized);

        $res = $controller->update('00000000-0000-0000-0000-000000000001', 'articles', $req);
        $this->assertSame(413, $res->getStatusCode());
        $body = json_decode($res->getContent(), true);
        $this->assertSame('PAYLOAD_TOO_LARGE', $body['code']);
    }

    public function testColumnSettingUpdateRejectsDeepJson(): void
    {
        $project = new Project();
        $collection = new Collection();

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);
        $collRepo = $this->createMock(CollectionRepository::class);
        $collRepo->method('findOneByProjectAndSlug')->willReturn($collection);
        $csRepo = $this->createMock(ColumnSettingRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $controller = new ColumnSettingController($projectRepo, $collRepo, $csRepo, $em, $this->createTranslator());
        $this->setupControllerSecurity($controller, $this->createMockUser());

        $deepPayload = ['visible_columns' => ['level1' => ['level2' => ['level3' => ['level4' => 'too_deep']]]]];
        $req = new Request([], [], [], [], [], [], json_encode($deepPayload));

        $res = $controller->update('00000000-0000-0000-0000-000000000001', 'articles', $req);
        $this->assertSame(400, $res->getStatusCode());
        $body = json_decode($res->getContent(), true);
        $this->assertSame('JSON_TOO_DEEP', $body['code']);
    }

    public function testColumnSettingUpdateRejectsPrototypePollution(): void
    {
        $project = new Project();
        $collection = new Collection();

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);
        $collRepo = $this->createMock(CollectionRepository::class);
        $collRepo->method('findOneByProjectAndSlug')->willReturn($collection);
        $csRepo = $this->createMock(ColumnSettingRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $controller = new ColumnSettingController($projectRepo, $collRepo, $csRepo, $em, $this->createTranslator());
        $this->setupControllerSecurity($controller, $this->createMockUser());

        $req = new Request([], [], [], [], [], [], json_encode(['__proto__' => ['polluted' => true], 'visible_columns' => []]));

        $res = $controller->update('00000000-0000-0000-0000-000000000001', 'articles', $req);
        $this->assertSame(400, $res->getStatusCode());
        $body = json_decode($res->getContent(), true);
        $this->assertSame('FORBIDDEN_PROPERTY', $body['code']);
    }

    public function testColumnSettingUpdateCapsToMaxColumnsCount(): void
    {
        $project = new Project();
        $collection = new Collection();

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);
        $collRepo = $this->createMock(CollectionRepository::class);
        $collRepo->method('findOneByProjectAndSlug')->willReturn($collection);

        $cs = new ColumnSetting();
        $csRepo = $this->createMock(ColumnSettingRepository::class);
        $csRepo->method('findOneByUserAndCollection')->willReturn($cs);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $controller = new ColumnSettingController($projectRepo, $collRepo, $csRepo, $em, $this->createTranslator());
        $this->setupControllerSecurity($controller, $this->createMockUser());

        $cols = [];
        for ($i = 1; $i <= ColumnSettingController::MAX_COLUMNS_COUNT + 10; $i++) {
            $cols[] = "col_{$i}";
        }
        $req = new Request([], [], [], [], [], [], json_encode(['visible_columns' => $cols]));

        $res = $controller->update('00000000-0000-0000-0000-000000000001', 'articles', $req);
        $this->assertSame(200, $res->getStatusCode());
        $body = json_decode($res->getContent(), true);
        $this->assertCount(50, $body['visibleColumns']);
    }

    public function testColumnSettingUpdateSanitizesTagsAndDropsControlCharacters(): void
    {
        $project = new Project();
        $collection = new Collection();

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);
        $collRepo = $this->createMock(CollectionRepository::class);
        $collRepo->method('findOneByProjectAndSlug')->willReturn($collection);

        $cs = new ColumnSetting();
        $csRepo = $this->createMock(ColumnSettingRepository::class);
        $csRepo->method('findOneByUserAndCollection')->willReturn($cs);

        $em = $this->createMock(EntityManagerInterface::class);

        $controller = new ColumnSettingController($projectRepo, $collRepo, $csRepo, $em, $this->createTranslator());
        $this->setupControllerSecurity($controller, $this->createMockUser());

        $req = new Request([], [], [], [], [], [], json_encode(['visible_columns' => ['col1', '<script>alert(1)</script>col2', "bad\x00col"]]));

        $res = $controller->update('00000000-0000-0000-0000-000000000001', 'articles', $req);
        $this->assertSame(200, $res->getStatusCode());
        $body = json_decode($res->getContent(), true);
        $this->assertContains('col1', $body['visibleColumns']);
        $this->assertContains('alert(1)col2', $body['visibleColumns']);
        $this->assertNotContains("bad\x00col", $body['visibleColumns']);
    }

    public function testColumnSettingUpdateAcceptsFlatAndStructuredColumns(): void
    {
        $project = new Project();
        $collection = new Collection();

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);
        $collRepo = $this->createMock(CollectionRepository::class);
        $collRepo->method('findOneByProjectAndSlug')->willReturn($collection);

        $cs = new ColumnSetting();
        $csRepo = $this->createMock(ColumnSettingRepository::class);
        $csRepo->method('findOneByUserAndCollection')->willReturn($cs);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $controller = new ColumnSettingController($projectRepo, $collRepo, $csRepo, $em, $this->createTranslator());
        $this->setupControllerSecurity($controller, $this->createMockUser());

        $payload = [
            'columns' => [
                'title',
                ['id' => 'created_at', 'visible' => true, 'width' => 150, 'order' => 1],
            ],
        ];
        $req = new Request([], [], [], [], [], [], json_encode($payload));

        $res = $controller->update('00000000-0000-0000-0000-000000000001', 'articles', $req);
        $this->assertSame(200, $res->getStatusCode());
        $body = json_decode($res->getContent(), true);
        $this->assertCount(2, $body['visibleColumns']);
        $this->assertSame('title', $body['visibleColumns'][0]);
        $this->assertSame('created_at', $body['visibleColumns'][1]['id']);
    }

    public function testColumnSettingResetDeletesSetting(): void
    {
        $project = new Project();
        $collection = new Collection();

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);
        $collRepo = $this->createMock(CollectionRepository::class);
        $collRepo->method('findOneByProjectAndSlug')->willReturn($collection);

        $cs = new ColumnSetting();
        $csRepo = $this->createMock(ColumnSettingRepository::class);
        $csRepo->method('findOneByUserAndCollection')->willReturn($cs);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('remove')->with($cs);
        $em->expects($this->once())->method('flush');

        $controller = new ColumnSettingController($projectRepo, $collRepo, $csRepo, $em, $this->createTranslator());
        $this->setupControllerSecurity($controller, $this->createMockUser());

        $res = $controller->reset('00000000-0000-0000-0000-000000000001', 'articles');
        $this->assertSame(200, $res->getStatusCode());
        $body = json_decode($res->getContent(), true);
        $this->assertSame('reset', $body['status']);
        $this->assertSame([], $body['visibleColumns']);
    }

    // ════════════════════ 4. ProjectPluginController Tests ════════════════════

    public function testProjectPluginControllerListReturnsPluginsAndQuota(): void
    {
        $pm = $this->createPluginManager();
        $pm->registerPlugin([
            'id' => 'analytics-plugin',
            'name' => 'Analytics Plugin',
            'version' => '1.0.0',
            'enabled' => true,
        ]);

        $project = new Project();
        $project->settings = ['tier' => 'starter']; // limit 10

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $em = $this->createMock(EntityManagerInterface::class);

        $controller = new ProjectPluginController($projectRepo, $pm, $em, null, null, $this->createTranslator());
        $this->setupControllerSecurity($controller, $this->createMockUser());

        $res = $controller->list('00000000-0000-0000-0000-000000000001');
        $this->assertSame(200, $res->getStatusCode());

        $body = json_decode($res->getContent(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('quota', $body);
        $this->assertSame(10, $body['quota']['limit']);
        $this->assertSame(0, $body['quota']['used']);
        $this->assertSame(10, $body['quota']['remaining']);
    }

    public function testProjectPluginControllerToggleEnforcesQuota(): void
    {
        $pm = $this->createPluginManager();
        $pm->registerPlugin([
            'id' => 'seo-plugin',
            'name' => 'SEO Plugin',
            'version' => '1.0.0',
            'enabled' => true,
        ]);

        $project = new Project();
        $project->settings = ['max_plugins' => 1, 'plugins' => ['active' => ['seo-plugin']]];

        $pm->registerPlugin([
            'id' => 'backup-plugin',
            'name' => 'Backup Plugin',
            'version' => '1.0.0',
            'enabled' => true,
        ]);

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $em = $this->createMock(EntityManagerInterface::class);

        $controller = new ProjectPluginController($projectRepo, $pm, $em, null, null, $this->createTranslator());
        $this->setupControllerSecurity($controller, $this->createMockUser());

        // Attempting to activate backup-plugin when quota limit is 1 and 1 is already used
        $req = new Request([], [], [], [], [], [], json_encode(['enabled' => true]));
        $res = $controller->toggle('00000000-0000-0000-0000-000000000001', 'backup-plugin', $req);

        $this->assertSame(422, $res->getStatusCode());
        $body = json_decode($res->getContent(), true);
        $this->assertSame('QUOTA_EXCEEDED', $body['code']);
    }
}
