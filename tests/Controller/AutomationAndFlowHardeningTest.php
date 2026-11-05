<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Automation;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AutomationAndFlowHardeningTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Project $project;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get(EntityManagerInterface::class);

        $this->project = new Project();
        $this->project->name = 'FlowHardening Project ' . bin2hex(random_bytes(4));
        $this->em->persist($this->project);

        $this->adminUser = new User();
        $this->adminUser->email = 'flow_admin_' . bin2hex(random_bytes(4)) . '@test.com';
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

    public function testNodeCatalogEndpoint(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('GET', '/api/automations/node-catalog');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('categories', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertNotEmpty($data['categories']);
    }

    public function testValidateFlowValidGraph(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('POST', '/api/automations/validate-flow', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'flow_graph' => $this->validFlowGraph(),
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['valid']);
        $this->assertStringContainsString('valid', $data['message']);
    }

    public function testValidateFlowEmptyGraphRejected(): void
    {
        $this->client->loginUser($this->adminUser);
        $this->client->request('POST', '/api/automations/validate-flow', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'flow_graph' => [],
        ]));

        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testValidateFlowSsrfStaticUrlRejected(): void
    {
        $this->client->loginUser($this->adminUser);

        $ssrfGraph = [
            'nodes' => [
                ['id' => 't1', 'type' => 'trigger.content.created', 'data' => []],
                [
                    'id' => 'a1',
                    'type' => 'action.call_webhook',
                    'data' => [
                        'config' => [
                            'url' => 'http://169.254.169.254/latest/meta-data',
                        ],
                    ],
                ],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 't1', 'target' => 'a1'],
            ],
        ];

        $this->client->request('POST', '/api/automations/validate-flow', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'flow_graph' => $ssrfGraph,
        ]));

        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['valid']);
        $this->assertStringContainsString('anti-SSRF', implode(' ', $data['details']));
    }

    public function testValidateFlowTriggerWithIncomingEdgeRejected(): void
    {
        $this->client->loginUser($this->adminUser);

        $badTriggerGraph = [
            'nodes' => [
                ['id' => 't1', 'type' => 'trigger.content.created', 'data' => []],
                ['id' => 'a1', 'type' => 'action.send_email', 'data' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'a1', 'target' => 't1'],
            ],
        ];

        $this->client->request('POST', '/api/automations/validate-flow', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'flow_graph' => $badTriggerGraph,
        ]));

        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['valid']);
        $details = implode(' ', $data['details']);
        $this->assertTrue(str_contains($details, "trigger ne peut pas recevoir d'arêtes entrantes") || str_contains($details, 'a trigger cannot receive incoming edges'));
    }

    public function testStoreAutomationValidatesNameBounds(): void
    {
        $this->client->loginUser($this->adminUser);
        $projectUuid = $this->project->uuid->toRfc4122();

        $oversizedName = str_repeat('A', 256);

        $this->client->request('POST', "/api/projects/{$projectUuid}/automations", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'name' => $oversizedName,
            'flow_graph' => $this->validFlowGraph(),
        ]));

        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertStringContainsKeyOrString('255', $data['error']);
    }

    public function testDryRunValidatesPayloadFormat(): void
    {
        $this->client->loginUser($this->adminUser);
        $projectUuid = $this->project->uuid->toRfc4122();

        $automation = new Automation();
        $automation->project = $this->project;
        $automation->name = 'DryRun Test Flow';
        $automation->isActive = true;
        $automation->flowGraph = $this->validFlowGraph();
        $this->em->persist($automation);
        $this->em->flush();

        // Non-array payload must be rejected
        $this->client->request('POST', "/api/projects/{$projectUuid}/automations/{$automation->id}/dry-run", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'payload' => 'not-an-array',
        ]));

        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testTestNodeValidatesRequiredNodeType(): void
    {
        $this->client->loginUser($this->adminUser);
        $projectUuid = $this->project->uuid->toRfc4122();

        $automation = new Automation();
        $automation->project = $this->project;
        $automation->name = 'TestNode Flow';
        $automation->isActive = true;
        $automation->flowGraph = $this->validFlowGraph();
        $this->em->persist($automation);
        $this->em->flush();

        // Empty node_type must be rejected with 422
        $this->client->request('POST', "/api/projects/{$projectUuid}/automations/{$automation->id}/test-node", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'node_type' => '',
        ]));

        $this->assertSame(422, $this->client->getResponse()->getStatusCode());

        // Unknown node_type must return 404
        $this->client->request('POST', "/api/projects/{$projectUuid}/automations/{$automation->id}/test-node", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'node_type' => 'non_existent_type_xyz_123',
        ]));

        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testAutomationLocalizationFrSupport(): void
    {
        $this->adminUser->locale = 'fr';
        $this->em->flush();
        $this->client->loginUser($this->adminUser);
        $projectUuid = $this->project->uuid->toRfc4122();

        // Request non-existent automation with user locale: fr
        $this->client->request('GET', "/api/projects/{$projectUuid}/automations/999999");

        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('Automatisation introuvable.', $data['error']);
    }

    public function testAutomationLocalizationEsSupport(): void
    {
        $this->adminUser->locale = 'es';
        $this->em->flush();
        $this->client->loginUser($this->adminUser);
        $projectUuid = $this->project->uuid->toRfc4122();

        $this->client->request('GET', "/api/projects/{$projectUuid}/automations/999999");

        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('Automatización no encontrada.', $data['error']);
    }

    public function testAutomationLocalizationArSupport(): void
    {
        $this->adminUser->locale = 'ar';
        $this->em->flush();
        $this->client->loginUser($this->adminUser);
        $projectUuid = $this->project->uuid->toRfc4122();

        $this->client->request('GET', "/api/projects/{$projectUuid}/automations/999999");

        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('الأتمتة غير موجودة.', $data['error']);
    }

    public function testValidateFlowCyclicGraph(): void
    {
        $this->client->loginUser($this->adminUser);

        $cyclicGraph = [
            'nodes' => [
                ['id' => 't1', 'type' => 'trigger.content.created', 'data' => []],
                ['id' => 'a1', 'type' => 'action.send_email', 'data' => []],
                ['id' => 'a2', 'type' => 'action.send_notification', 'data' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 't1', 'target' => 'a1'],
                ['id' => 'e2', 'source' => 'a1', 'target' => 'a2'],
                ['id' => 'e3', 'source' => 'a2', 'target' => 'a1'],
            ],
        ];

        $this->client->request('POST', '/api/automations/validate-flow', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'flow_graph' => $cyclicGraph,
        ]));

        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['valid']);
        $this->assertStringContainsString('cycle', implode(' ', $data['details']));
    }

    public function testValidateFlowMaxDepthExceeded(): void
    {
        $this->client->loginUser($this->adminUser);

        // Build a chain with depth > 50
        $nodes = [
            ['id' => 't1', 'type' => 'trigger.content.created', 'data' => []],
        ];
        $edges = [];
        $prev = 't1';
        for ($i = 1; $i <= 52; $i++) {
            $curr = 'n' . $i;
            $nodes[] = ['id' => $curr, 'type' => 'action.send_email', 'data' => []];
            $edges[] = ['id' => 'e' . $i, 'source' => $prev, 'target' => $curr];
            $prev = $curr;
        }

        $deepGraph = [
            'nodes' => $nodes,
            'edges' => $edges,
        ];

        $this->client->request('POST', '/api/automations/validate-flow', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'flow_graph' => $deepGraph,
        ]));

        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertFalse($data['valid']);
        $details = implode(' ', $data['details']);
        $this->assertTrue(str_contains($details, 'profondeur') || str_contains($details, 'depth'));
    }

    public function testStoreAutomationRejectsOversizedPayload(): void
    {
        $this->client->loginUser($this->adminUser);
        $projectUuid = $this->project->uuid->toRfc4122();

        $oversizedBody = json_encode([
            'name' => 'Big flow',
            'flow_graph' => $this->validFlowGraph(),
            'junk' => str_repeat('X', 525000),
        ]);

        $this->client->request('POST', "/api/projects/{$projectUuid}/automations", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $oversizedBody);

        $this->assertSame(413, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('PAYLOAD_TOO_LARGE', $data['code'] ?? null);
    }

    public function testTestNodeValidatesNodeIdAndTypeLimits(): void
    {
        $this->client->loginUser($this->adminUser);
        $projectUuid = $this->project->uuid->toRfc4122();

        $automation = new Automation();
        $automation->project = $this->project;
        $automation->name = 'Node Limit Flow';
        $automation->isActive = true;
        $automation->flowGraph = $this->validFlowGraph();
        $this->em->persist($automation);
        $this->em->flush();

        // Invalid nodeId format
        $this->client->request('POST', "/api/projects/{$projectUuid}/automations/{$automation->id}/test-node", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'node_id' => 'bad!node@id#',
            'node_type' => 'util.noop',
        ]));
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());

        // Invalid nodeType format
        $this->client->request('POST', "/api/projects/{$projectUuid}/automations/{$automation->id}/test-node", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'node_id' => 'node_1',
            'node_type' => 'bad type with spaces & symbols!',
        ]));
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());

        // Oversized config
        $this->client->request('POST', "/api/projects/{$projectUuid}/automations/{$automation->id}/test-node", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'node_id' => 'node_1',
            'node_type' => 'util.noop',
            'config' => ['huge' => str_repeat('C', 70000)],
        ]));
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testImportBundleRejectsOverMaxItems(): void
    {
        $this->client->loginUser($this->adminUser);
        $projectUuid = $this->project->uuid->toRfc4122();

        $automations = [];
        for ($i = 0; $i < 55; $i++) {
            $automations[] = [
                'name' => "Auto {$i}",
                'flow_graph' => $this->validFlowGraph(),
            ];
        }

        $this->client->request('POST', "/api/projects/{$projectUuid}/automations/import", [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'schema_version' => '1.0',
            'automations' => $automations,
        ]));

        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertStringContainsString('too many automations', $data['error']);
    }

    private function assertStringContainsKeyOrString(string $needle, string $haystack): void
    {
        $this->assertTrue(str_contains($haystack, $needle) || str_contains($haystack, 'name_too_long'), "String '$haystack' does not contain '$needle'");
    }
}
