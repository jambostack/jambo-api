<?php

declare(strict_types=1);

namespace App\Tests\Service\Automation;

use App\Entity\Automation;
use App\Entity\Project;
use App\Repository\AutomationRepository;
use App\Service\Automation\AutomationExportImportService;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\FlowValidator;
use App\Service\Flow\NodeRegistry;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class AutomationExportImportServiceTest extends TestCase
{
    private EntityManagerInterface $em;
    private AutomationRepository $repo;
    private FlowValidator $validator;
    private NodeRegistry $registry;
    private AutomationExportImportService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->repo = $this->createMock(AutomationRepository::class);
        $this->validator = new FlowValidator();
        $this->registry = $this->createMock(NodeRegistry::class);

        $handler = $this->createMock(FlowNodeHandler::class);
        $this->registry->method('resolve')->willReturn($handler);

        $this->service = new AutomationExportImportService(
            $this->em,
            $this->repo,
            $this->validator,
            $this->registry,
        );
    }

    private function validFlowGraph(): array
    {
        return [
            'nodes' => [
                ['id' => 't1', 'type' => 'trigger.content_created', 'data' => []],
                ['id' => 'a1', 'type' => 'action.send_email', 'data' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 't1', 'target' => 'a1'],
            ],
        ];
    }

    public function testExportAutomationGeneratesChecksumAndFormat(): void
    {
        $automation = new Automation();
        $automation->name = 'Test Automation';
        $automation->isActive = true;
        $automation->debugMode = false;
        $automation->flowGraph = $this->validFlowGraph();

        $exported = $this->service->exportAutomation($automation);

        $this->assertSame('1.0', $exported['schema_version']);
        $this->assertSame('single_automation', $exported['type']);
        $this->assertStringStartsWith('sha256:', $exported['checksum']);
        $this->assertSame('Test Automation', $exported['automation']['name']);
        $this->assertTrue($exported['automation']['is_active']);
    }

    public function testImportValidAutomationPersistsAndBindsToProject(): void
    {
        $project = new Project();
        $project->name = 'Target Project';

        $exportPayload = [
            'schema_version' => '1.0',
            'automation' => [
                'name' => 'Imported Flow',
                'is_active' => true,
                'debug_mode' => true,
                'flow_graph' => $this->validFlowGraph(),
            ],
        ];

        // Add valid checksum
        $canonical = json_encode($exportPayload['automation'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $exportPayload['checksum'] = 'sha256:' . hash('sha256', $canonical);

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $imported = $this->service->importAutomation($project, $exportPayload);

        $this->assertSame('Imported Flow', $imported->name);
        $this->assertSame($project, $imported->project);
        $this->assertNotNull($imported->uuid);
    }

    public function testImportRejectsChecksumMismatch(): void
    {
        $project = new Project();

        $exportPayload = [
            'schema_version' => '1.0',
            'checksum' => 'sha256:0000000000000000000000000000000000000000000000000000000000000000',
            'automation' => [
                'name' => 'Tampered Flow',
                'flow_graph' => $this->validFlowGraph(),
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Checksum mismatch');

        $this->service->importAutomation($project, $exportPayload);
    }

    public function testImportRejectsInvalidFlowGraph(): void
    {
        $project = new Project();

        // Invalid cyclic graph
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

        $exportPayload = [
            'schema_version' => '1.0',
            'automation' => [
                'name' => 'Cyclic Flow',
                'flow_graph' => $cyclicGraph,
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid flow graph');

        $this->service->importAutomation($project, $exportPayload);
    }

    public function testExportAndImportBundle(): void
    {
        $project = new Project();
        $project->name = 'Source Project';

        $a1 = new Automation();
        $a1->name = 'Flow 1';
        $a1->flowGraph = $this->validFlowGraph();

        $this->repo->method('findByProject')->willReturn([$a1]);

        $bundle = $this->service->exportProjectAutomations($project);
        $this->assertSame('project_automations_bundle', $bundle['type']);
        $this->assertSame(1, $bundle['count']);

        $targetProject = new Project();
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $imported = $this->service->importProjectAutomations($targetProject, $bundle);
        $this->assertCount(1, $imported);
        $this->assertSame('Flow 1', $imported[0]->name);
    }
}
