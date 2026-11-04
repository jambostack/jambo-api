<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Automation;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AutomationExportImportSecurityTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Project $project;
    private User $adminUser;
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get(EntityManagerInterface::class);

        $this->project = new Project();
        $this->project->name = 'Auto Security ' . bin2hex(random_bytes(4));
        $this->em->persist($this->project);

        $this->adminUser = new User();
        $this->adminUser->email = 'admin_' . bin2hex(random_bytes(4)) . '@test.com';
        $this->adminUser->name = 'Admin';
        $this->adminUser->roles = ['ROLE_SUPER_ADMIN'];
        $this->adminUser->password = 'hashed_pass';
        $this->em->persist($this->adminUser);

        $this->em->flush();
    }

    private function validFlowGraph(): array
    {
        return [
            'nodes' => [
                ['id' => 't1', 'type' => 'trigger.content.created', 'data' => []],
                ['id' => 'a1', 'type' => 'action.send_email', 'data' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 't1', 'target' => 'a1'],
            ],
        ];
    }

    public function testExportAndImportAutomationEndpoints(): void
    {
        $client = $this->client;
        $client->loginUser($this->adminUser);

        $automation = new Automation();
        $automation->project = $this->project;
        $automation->name = 'Sample Flow';
        $automation->isActive = true;
        $automation->flowGraph = $this->validFlowGraph();
        $this->em->persist($automation);
        $this->em->flush();

        $projectUuid = $this->project->uuid->toRfc4122();

        // 1. Export single
        $client->request('GET', "/api/projects/{$projectUuid}/automations/{$automation->id}/export");
        $this->assertResponseIsSuccessful();
        $exportData = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('1.0', $exportData['schema_version']);
        $this->assertStringStartsWith('sha256:', $exportData['checksum']);

        // 2. Export all
        $client->request('GET', "/api/projects/{$projectUuid}/automations/export-all");
        $this->assertResponseIsSuccessful();
        $bundleData = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('project_automations_bundle', $bundleData['type']);
        $this->assertSame(1, $bundleData['count']);

        // 3. Import valid
        $client->request('POST', "/api/projects/{$projectUuid}/automations/import", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($exportData));
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        // 4. Import with tampered checksum
        $tampered = $exportData;
        $tampered['checksum'] = 'sha256:badhash1234567890';
        $client->request('POST', "/api/projects/{$projectUuid}/automations/import", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($tampered));
        $this->assertSame(422, $client->getResponse()->getStatusCode());

        // 5. Store with invalid cyclic graph rejected
        $cyclicGraph = [
            'nodes' => [
                ['id' => 't1', 'type' => 'trigger.content_created', 'data' => []],
                ['id' => 'a1', 'type' => 'action.send_email', 'data' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 't1', 'target' => 'a1'],
                ['id' => 'e2', 'source' => 'a1', 'target' => 't1'],
            ],
        ];

        $client->request('POST', "/api/projects/{$projectUuid}/automations", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'name' => 'Bad Cyclic Automation',
            'flow_graph' => $cyclicGraph,
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
    }
}
