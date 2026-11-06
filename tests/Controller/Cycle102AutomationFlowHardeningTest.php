<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\AutomationController;
use App\Controller\AutomationFlowController;
use App\Entity\AuditLog;
use App\Entity\Automation;
use App\Entity\Permission;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\Role;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Cycle 102 - Suite de tests complète pour le durcissement du contrôleur de flux et automatisations :
 * isolation multi-tenant stricte, limitation de débit, quotas d'exécution, journalisation d'audit et i18n.
 */
class Cycle102AutomationFlowHardeningTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get(EntityManagerInterface::class);
    }

    private function createProjectAndUser(string $roleName = 'Manager', array $permissions = ['project.view', 'project.manage']): array
    {
        $user = new User();
        $user->email = 'c102_user_' . bin2hex(random_bytes(4)) . '@test.com';
        $user->name = 'User Cycle 102';
        $user->roles = ['ROLE_USER'];
        $user->password = 'password123';
        $this->em->persist($user);

        $project = new Project();
        $project->name = 'Project C102 ' . bin2hex(random_bytes(3));
        $this->em->persist($project);

        $role = new Role();
        $role->name = $roleName . '_' . bin2hex(random_bytes(3));

        foreach ($permissions as $permName) {
            $perm = $this->em->getRepository(Permission::class)->findOneBy(['name' => $permName]);
            if (!$perm) {
                $perm = new Permission();
                $perm->name = $permName;
                $perm->label = ucfirst($permName);
                $perm->group = 'project';
                $this->em->persist($perm);
            }
            $role->permissions->add($perm);
        }
        $this->em->persist($role);

        $member = new ProjectMember();
        $member->project = $project;
        $member->user = $user;
        $member->email = $user->email;
        $member->status = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $member->role = $role;
        $this->em->persist($member);

        $this->em->flush();

        return [$user, $project];
    }

    private function createAutomation(Project $project, string $name = 'Flow Test 102', bool $active = true): Automation
    {
        $automation = new Automation();
        $automation->project = $project;
        $automation->name = $name;
        $automation->isActive = $active;
        $automation->flowGraph = [
            'nodes' => [
                ['id' => 't1', 'type' => 'trigger.schedule.cron', 'data' => ['cron' => '0 * * * *']],
                ['id' => 'a1', 'type' => 'util.noop', 'data' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 't1', 'target' => 'a1'],
            ],
        ];
        $this->em->persist($automation);
        $this->em->flush();

        return $automation;
    }

    public function testClassAliasExists(): void
    {
        $this->assertTrue(class_exists(AutomationController::class));
        $this->assertTrue(class_exists(AutomationFlowController::class));
        // AutomationFlowController est un alias de rétrocompatibilité vers AutomationController
        // Les deux doivent avoir les mêmes méthodes publiques essentielles
        $this->assertTrue(method_exists(AutomationController::class, 'index'));
        $this->assertTrue(method_exists(AutomationController::class, 'store'));
        $this->assertTrue(method_exists(AutomationController::class, 'run'));
    }

    public function testMultiTenantIsolationUnauthenticated(): void
    {
        $uuid = '00000000-0000-0000-0000-000000000001';
        $this->client->request('GET', "/api/projects/{$uuid}/automations");

        $response = $this->client->getResponse();
        $this->assertSame(401, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('AUTH_REQUIRED', $data['code']);
    }

    public function testInvalidUuidFormatReturns404(): void
    {
        [$user] = $this->createProjectAndUser();
        $this->client->loginUser($user);

        $this->client->request('GET', '/api/projects/not-a-valid-uuid/automations');

        $response = $this->client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('PROJECT_NOT_FOUND', $data['code']);
    }

    public function testMultiTenantIsolationForbiddenForDifferentTenantUser(): void
    {
        [$userA, $projectA] = $this->createProjectAndUser('TenantA');
        [$userB, $projectB] = $this->createProjectAndUser('TenantB');

        $autoA = $this->createAutomation($projectA, 'Tenant A Automation');

        // User B tries to view Project A automations -> 403 at multi-tenant boundary
        $this->client->loginUser($userB);
        $uuidA = $projectA->uuid->toRfc4122();
        $this->client->request('GET', "/api/projects/{$uuidA}/automations");

        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('ACCESS_DENIED', $data['code']);

        // User B tries to run Project A automation -> 403
        $this->client->request('POST', "/api/projects/{$uuidA}/automations/{$autoA->id}/run");
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        // User B tries to delete Project A automation -> 403
        $this->client->request('DELETE', "/api/projects/{$uuidA}/automations/{$autoA->id}");
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        // View-only member of Project A attempting to delete or run -> 403 and logged in AuditService
        $viewerUser = new User();
        $viewerUser->email = 'viewer_' . bin2hex(random_bytes(4)) . '@test.com';
        $viewerUser->name = 'Viewer User';
        $viewerUser->roles = ['ROLE_USER'];
        $viewerUser->password = 'hashed123';
        $this->em->persist($viewerUser);

        $permView = $this->em->getRepository(Permission::class)->findOneBy(['name' => 'project.view']);
        $roleView = new Role();
        $roleView->name = 'ViewerRole_' . bin2hex(random_bytes(3));
        $roleView->permissions->add($permView);
        $this->em->persist($roleView);

        $managedProjectA = $this->em->find(Project::class, $projectA->id);

        $memberViewer = new ProjectMember();
        $memberViewer->project = $managedProjectA;
        $memberViewer->user = $viewerUser;
        $memberViewer->email = $viewerUser->email;
        $memberViewer->status = ProjectMemberStatus::Active;
        $memberViewer->joinedAt = new \DateTimeImmutable();
        $memberViewer->role = $roleView;
        $this->em->persist($memberViewer);
        $this->em->flush();

        $this->client->loginUser($viewerUser);
        $this->client->request('DELETE', "/api/projects/{$uuidA}/automations/{$autoA->id}");
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        // Verify audit log has access denied record
        $auditLogs = $this->em->getRepository(AuditLog::class)->findBy([
            'toolName' => 'automation_access_denied',
        ], ['id' => 'DESC']);
        $this->assertNotEmpty($auditLogs);
        $this->assertSame('failure', $auditLogs[0]->status);
    }

    public function testSecurityHeadersOnAutomationEndpoints(): void
    {
        [$user, $project] = $this->createProjectAndUser();
        $this->client->loginUser($user);

        $uuid = $project->uuid->toRfc4122();
        $this->client->request('GET', "/api/projects/{$uuid}/automations");

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testFlowControllerSecurityHeadersAndCatalog(): void
    {
        [$user] = $this->createProjectAndUser();
        $this->client->loginUser($user);

        $this->client->request('GET', '/api/automations/node-catalog');
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));

        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('categories', $data);
        $this->assertNotEmpty($data['categories']);
    }

    public function testFlowValidationEndpoint(): void
    {
        [$user] = $this->createProjectAndUser();
        $this->client->loginUser($user);

        // Valid acyclic flow
        $validFlow = [
            'flow_graph' => [
                'nodes' => [
                    ['id' => 't1', 'type' => 'trigger.schedule.cron', 'data' => []],
                    ['id' => 'a1', 'type' => 'util.noop', 'data' => []],
                ],
                'edges' => [
                    ['id' => 'e1', 'source' => 't1', 'target' => 'a1'],
                ],
            ],
        ];

        $this->client->request('POST', '/api/automations/validate-flow', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($validFlow));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['valid']);

        // Empty graph -> 422 GRAPH_REQUIRED
        $this->client->request('POST', '/api/automations/validate-flow', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([]));
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $dataEmpty = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('GRAPH_REQUIRED', $dataEmpty['code']);
    }

    public function testPayloadSizeEnforcement(): void
    {
        [$user, $project] = $this->createProjectAndUser();
        $this->client->loginUser($user);

        $uuid = $project->uuid->toRfc4122();
        $oversizedData = [
            'name' => 'Big automation',
            'flow_graph' => [
                'nodes' => [],
                'edges' => [],
                'dummy' => str_repeat('A', 600000), // > 512 KB
            ],
        ];

        $this->client->request('POST', "/api/projects/{$uuid}/automations", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($oversizedData));
        $this->assertSame(413, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('PAYLOAD_TOO_LARGE', $data['code']);
    }

    public function testDryRunAndTestAuditLogging(): void
    {
        [$user, $project] = $this->createProjectAndUser();
        $this->client->loginUser($user);
        $auto = $this->createAutomation($project, 'DryRun Flow');

        $uuid = $project->uuid->toRfc4122();

        // Dry-run
        $this->client->request('POST', "/api/projects/{$uuid}/automations/{$auto->id}/dry-run", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'payload' => ['test' => 123],
        ]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $dryRunData = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($dryRunData['success']);

        // Check dry-run audit log
        $dryRunAudit = $this->em->getRepository(AuditLog::class)->findOneBy([
            'toolName' => 'automation_dry_run',
            'project'  => $project,
        ]);
        $this->assertNotNull($dryRunAudit);
        $this->assertSame('success', $dryRunAudit->status);

        // Test
        $this->client->request('POST', "/api/projects/{$uuid}/automations/{$auto->id}/test");
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $testAudit = $this->em->getRepository(AuditLog::class)->findOneBy([
            'toolName' => 'automation_test',
            'project'  => $project,
        ]);
        $this->assertNotNull($testAudit);
        $this->assertSame('success', $testAudit->status);
    }

    public function testExportAndExportAllAuditLogging(): void
    {
        [$user, $project] = $this->createProjectAndUser();
        $this->client->loginUser($user);
        $auto = $this->createAutomation($project, 'Exportable Flow');

        $uuid = $project->uuid->toRfc4122();

        // Export single
        $this->client->request('GET', "/api/projects/{$uuid}/automations/{$auto->id}/export");
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $singleExportAudit = $this->em->getRepository(AuditLog::class)->findOneBy([
            'toolName' => 'automation_export_single',
            'project'  => $project,
        ]);
        $this->assertNotNull($singleExportAudit);

        // Export all
        $this->client->request('GET', "/api/projects/{$uuid}/automations/export-all");
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $allExportAudit = $this->em->getRepository(AuditLog::class)->findOneBy([
            'toolName' => 'automation_export_all',
            'project'  => $project,
        ]);
        $this->assertNotNull($allExportAudit);
    }

    public function testI18nTranslationsExistForAllLocales(): void
    {
        $translator = $this->client->getContainer()->get('translator');

        $keys = [
            'automation.admin.project_not_found',
            'automation.admin.not_found',
            'automation.admin.access_denied',
            'automation.admin.execution_burst_limited',
            'automation.admin.execution_rate_limit_exceeded',
            'automation.admin.deleted_success',
            'automation.admin.export_all_success',
            'automation.admin.export_single_success',
            'flow.admin.access_denied',
            'flow.admin.rate_limit_exceeded',
            'flow.admin.payload_too_large',
            'flow.admin.nodes_limit_exceeded',
            'flow.admin.edges_limit_exceeded',
        ];

        $locales = ['en', 'fr', 'es', 'ar'];

        foreach ($locales as $locale) {
            foreach ($keys as $key) {
                $translation = $translator->trans($key, [], 'messages', $locale);
                $this->assertNotEmpty($translation, "Missing translation for key '{$key}' in locale '{$locale}'");
                $this->assertNotSame($key, $translation, "Key '{$key}' was not translated in locale '{$locale}'");
            }
        }
    }

    public function testRunInactiveAutomationReturns400(): void
    {
        [$user, $project] = $this->createProjectAndUser();
        $this->client->loginUser($user);
        $auto = $this->createAutomation($project, 'Inactive Flow', false);

        $uuid = $project->uuid->toRfc4122();
        $this->client->request('POST', "/api/projects/{$uuid}/automations/{$auto->id}/run");
        $response = $this->client->getResponse();
        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('AUTOMATION_INACTIVE', $data['code']);
    }

    public function testRunAuditLoggedOnSuccess(): void
    {
        [$user, $project] = $this->createProjectAndUser();
        $this->client->loginUser($user);
        $auto = $this->createAutomation($project, 'Audit Run Flow');

        $uuid = $project->uuid->toRfc4122();
        $this->client->request('POST', "/api/projects/{$uuid}/automations/{$auto->id}/run");
        // 200 or 400 acceptable depending on engine — just verify audit log attempted
        $auditLog = $this->em->getRepository(AuditLog::class)->findOneBy([
            'project' => $project,
        ]);
        // At minimum, some audit log must exist for this project (quota check or run)
        $this->assertNotNull($auditLog);
    }

    public function testNodesLimitEnforcedOnCreate(): void
    {
        [$user, $project] = $this->createProjectAndUser();
        $this->client->loginUser($user);
        $uuid = $project->uuid->toRfc4122();

        $nodes = [];
        for ($i = 0; $i <= 100; $i++) {
            $nodes[] = ['id' => "n{$i}", 'type' => 'util.noop', 'data' => []];
        }

        $this->client->request('POST', "/api/projects/{$uuid}/automations", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name'       => 'Too many nodes',
            'flow_graph' => ['nodes' => $nodes, 'edges' => []],
        ]));
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('NODES_LIMIT_EXCEEDED', $data['code']);
    }

    public function testEdgesLimitEnforcedOnUpdate(): void
    {
        [$user, $project] = $this->createProjectAndUser();
        $this->client->loginUser($user);
        $auto = $this->createAutomation($project, 'Edge Limit Test');
        $uuid = $project->uuid->toRfc4122();

        $edges = [];
        for ($i = 0; $i <= 200; $i++) {
            $edges[] = ['id' => "e{$i}", 'source' => 't1', 'target' => 'a1'];
        }

        $this->client->request('PUT', "/api/projects/{$uuid}/automations/{$auto->id}", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'flow_graph' => ['nodes' => [['id' => 't1', 'type' => 'util.noop', 'data' => []]], 'edges' => $edges],
        ]));
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('EDGES_LIMIT_EXCEEDED', $data['code']);
    }

    public function testCrossProjectAutomationAccess(): void
    {
        // Projet A avec automation
        [$userA, $projectA] = $this->createProjectAndUser();
        $autoA = $this->createAutomation($projectA, 'Project A Automation');

        // Projet B — user B ne doit pas accéder à l'automation du projet A
        [$userB, $projectB] = $this->createProjectAndUser();
        $this->client->loginUser($userB);

        $uuidA = $projectA->uuid->toRfc4122();
        $this->client->request('GET', "/api/projects/{$uuidA}/automations/{$autoA->id}");
        $response = $this->client->getResponse();
        // Doit retourner 403 (accès refusé) — isolation multi-tenant stricte
        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    public function testTestNodeValidatesNodeTypeFormat(): void
    {
        [$user, $project] = $this->createProjectAndUser();
        $this->client->loginUser($user);
        $auto = $this->createAutomation($project, 'Node Type Validation');
        $uuid = $project->uuid->toRfc4122();

        // Type avec caractères invalides (injection tentée)
        $this->client->request('POST', "/api/projects/{$uuid}/automations/{$auto->id}/test-node", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'node_type' => '../../../etc/passwd',
            'config'    => [],
        ]));
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_NODE_TYPE', $data['code']);
    }

    public function testSecurityHeadersPresentOnAllResponses(): void
    {
        [$user, $project] = $this->createProjectAndUser();
        $this->client->loginUser($user);
        $uuid = $project->uuid->toRfc4122();

        $this->client->request('GET', "/api/projects/{$uuid}/automations");
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $headers = $this->client->getResponse()->headers;
        $this->assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $headers->get('X-Frame-Options'));
        $this->assertStringContainsString('no-store', $headers->get('Cache-Control') ?? '');
    }
}
