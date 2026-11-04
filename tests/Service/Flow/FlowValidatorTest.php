<?php

namespace App\Tests\Service\Flow;

use App\Service\Flow\FlowValidator;
use App\Service\Flow\NodeRegistry;
use PHPUnit\Framework\TestCase;

class FlowValidatorTest extends TestCase
{
    private FlowValidator $validator;
    private NodeRegistry $registry;

    protected function setUp(): void
    {
        $this->validator = new FlowValidator();
        $this->registry = $this->createMock(NodeRegistry::class);
    }

    private function makeGraph(array $nodes, array $edges): array
    {
        return ['nodes' => $nodes, 'edges' => $edges];
    }

    private function node(string $id, string $type): array
    {
        return ['id' => $id, 'type' => $type, 'data' => ['label' => $id]];
    }

    private function edge(string $id, string $source, string $target): array
    {
        return ['id' => $id, 'source' => $source, 'target' => $target];
    }

    // ─── Structure ─────────────────────────────────────────────────

    public function testRejectsEmptyGraph(): void
    {
        $result = $this->validator->validate(['nodes' => [], 'edges' => []], $this->registry);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('au moins un node', $result['errors'][0]);
    }

    public function testRejectsMissingEdgesKey(): void
    {
        $result = $this->validator->validate(['nodes' => [$this->node('t1', 'trigger.content_created')]], $this->registry);
        $this->assertFalse($result['valid']);
    }

    // ─── Types valides ─────────────────────────────────────────────

    public function testRejectsUnknownNodeType(): void
    {
        $this->registry->method('resolve')->willReturn(null);

        $graph = $this->makeGraph(
            [$this->node('t1', 'trigger.unknown_type')],
            [],
        );
        $result = $this->validator->validate($graph, $this->registry);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('inconnu', $result['errors'][0]);
    }

    public function testRejectsEdgeWithMissingSource(): void
    {
        $this->registry->method('resolve')->willReturn($this->createMock(\App\Service\Flow\FlowNodeHandler::class));

        $graph = $this->makeGraph(
            [$this->node('t1', 'trigger.content_created'), $this->node('a1', 'action.send_email')],
            [$this->edge('e1', 'missing_source', 'a1')],
        );
        $result = $this->validator->validate($graph, $this->registry);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('introuvable', $result['errors'][0]);
    }

    // ─── DAG valide ────────────────────────────────────────────────

    public function testAcceptsValidDag(): void
    {
        $this->registry->method('resolve')->willReturn($this->createMock(\App\Service\Flow\FlowNodeHandler::class));

        $graph = $this->makeGraph(
            [$this->node('t1', 'trigger.content_created'), $this->node('a1', 'action.send_email')],
            [$this->edge('e1', 't1', 'a1')],
        );
        $result = $this->validator->validate($graph, $this->registry);
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    // ─── Cycle ─────────────────────────────────────────────────────

    public function testRejectsCyclicGraph(): void
    {
        $this->registry->method('resolve')->willReturn($this->createMock(\App\Service\Flow\FlowNodeHandler::class));

        $graph = $this->makeGraph(
            [$this->node('t1', 'trigger.content_created'), $this->node('a1', 'action.send_email'), $this->node('a2', 'action.send_notification')],
            [$this->edge('e1', 't1', 'a1'), $this->edge('e2', 'a1', 'a2'), $this->edge('e3', 'a2', 'a1')],
        );
        $result = $this->validator->validate($graph, $this->registry);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('cycle', $result['errors'][0]);
    }

    // ─── Trigger requis ────────────────────────────────────────────

    public function testRejectsGraphWithoutTrigger(): void
    {
        $this->registry->method('resolve')->willReturn($this->createMock(\App\Service\Flow\FlowNodeHandler::class));

        $graph = $this->makeGraph(
            [$this->node('a1', 'action.send_email'), $this->node('a2', 'action.send_notification')],
            [$this->edge('e1', 'a1', 'a2')],
        );
        $result = $this->validator->validate($graph, $this->registry);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('trigger', $result['errors'][0]);
    }

    // ─── Orphelin ──────────────────────────────────────────────────

    public function testRejectsOrphanNode(): void
    {
        $this->registry->method('resolve')->willReturn($this->createMock(\App\Service\Flow\FlowNodeHandler::class));

        $graph = $this->makeGraph(
            [$this->node('t1', 'trigger.content_created'), $this->node('o1', 'action.send_email')],
            [],
        );
        $result = $this->validator->validate($graph, $this->registry);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('orphelin', $result['errors'][0]);
    }

    public function testRejectsExceedingMaxNodes(): void
    {
        $this->registry->method('resolve')->willReturn($this->createMock(\App\Service\Flow\FlowNodeHandler::class));

        $nodes = [$this->node('t1', 'trigger.content_created')];
        for ($i = 1; $i <= FlowValidator::MAX_NODES + 1; $i++) {
            $nodes[] = $this->node('node_' . $i, 'action.send_email');
        }

        $result = $this->validator->validate(['nodes' => $nodes, 'edges' => []], $this->registry);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('dépasse la limite de', $result['errors'][0]);
    }

    public function testRejectsInvalidNodeIdFormat(): void
    {
        $this->registry->method('resolve')->willReturn($this->createMock(\App\Service\Flow\FlowNodeHandler::class));

        $graph = $this->makeGraph(
            [$this->node('t1<script>', 'trigger.content_created')],
            [],
        );
        $result = $this->validator->validate($graph, $this->registry);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('id invalide', $result['errors'][0]);
    }

    public function testRejectsDuplicateNodeIds(): void
    {
        $this->registry->method('resolve')->willReturn($this->createMock(\App\Service\Flow\FlowNodeHandler::class));

        $graph = $this->makeGraph(
            [$this->node('t1', 'trigger.content_created'), $this->node('t1', 'action.send_email')],
            [],
        );
        $result = $this->validator->validate($graph, $this->registry);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('doublon', $result['errors'][0]);
    }
}
