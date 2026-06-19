<?php

namespace App\Service\Flow;

use App\Message\ExecuteSubFlowMessage;
use Symfony\Component\Messenger\MessageBusInterface;

class FlowInterpreter
{
    public function __construct(
        private readonly NodeRegistry $registry,
        private readonly FlowValidator $validator,
        private readonly MessageBusInterface $bus,
    ) {}

    public function executeFlow(array $graph, array $triggerPayload, FlowContext $ctx): FlowResult
    {
        $startTime = microtime(true);

        // 1. Valide
        $validation = $this->validator->validate($graph, $this->registry);
        if (!$validation['valid']) {
            return new FlowResult(status: 'failed', error: implode('; ', $validation['errors']));
        }

        $nodes = $graph['nodes'];
        $edges = $graph['edges'];

        // 2. Initialise les variables du flow
        $ctx->variables = array_merge($ctx->variables, $graph['variables'] ?? []);

        // 3. Topological sort (Kahn)
        $sorted = $this->topologicalSort($nodes, $edges);

        // 4. Exécute séquentiellement
        $nodeOutputs = [];
        foreach ($sorted as $nodeId) {
            $node = $this->findNode($nodes, $nodeId);
            if (!$node) continue;

            $handler = $this->registry->resolve($node['type']);
            if (!$handler) {
                $ctx->logStep($nodeId, $node['type'], $node['data']['label'] ?? '', 'failed', 0, [], [], "Handler not found: {$node['type']}");
                continue;
            }

            // Collecte les inputs des edges entrants
            $input = $this->collectInput($nodeId, $edges, $nodeOutputs);

            // Ajoute le payload trigger pour le premier node
            if (empty($input)) {
                $input = ['_trigger' => new NodeOutput(data: $triggerPayload)];
            }

            // Injecte la config du node dans le contexte
            $ctx->variables['_node_config'] = $node['data']['config'] ?? [];

            $nodeStart = microtime(true);
            try {
                $output = $handler->execute($input, $ctx);
                $nodeMs = (int)((microtime(true) - $nodeStart) * 1000);
                $ctx->logStep($nodeId, $node['type'], $node['data']['label'] ?? '', 'success', $nodeMs, $this->summarizeInput($input), $output->data);
                $nodeOutputs[$nodeId] = $output;
            } catch (\Throwable $e) {
                $nodeMs = (int)((microtime(true) - $nodeStart) * 1000);
                $ctx->logStep($nodeId, $node['type'], $node['data']['label'] ?? '', 'failed', $nodeMs, $this->summarizeInput($input), [], $e->getMessage());
                $nodeOutputs[$nodeId] = new NodeOutput(data: ['error' => $e->getMessage()]);
            }
        }

        $totalMs = (int)((microtime(true) - $startTime) * 1000);
        return FlowResult::fromContext($ctx, $totalMs);
    }

    /** Exécute un sous-graphe (appelé par ExecuteSubFlowMessageHandler) */
    public function executeSubFlow(array $subGraph, array $triggerPayload, FlowContext $ctx): FlowResult
    {
        return $this->executeFlow($subGraph, $triggerPayload, $ctx);
    }

    // ─── Private ─────────────────────────────────────────────────────

    /** @return string[] node ids dans l'ordre topologique */
    private function topologicalSort(array $nodes, array $edges): array
    {
        $inDegree = [];
        $adj = [];
        foreach ($nodes as $n) {
            $inDegree[$n['id']] = 0;
            $adj[$n['id']] = [];
        }

        foreach ($edges as $e) {
            $adj[$e['source']][] = $e['target'];
            $inDegree[$e['target']] = ($inDegree[$e['target']] ?? 0) + 1;
        }

        $queue = [];
        foreach ($inDegree as $id => $deg) {
            if ($deg === 0) $queue[] = $id;
        }

        $sorted = [];
        while (!empty($queue)) {
            $current = array_shift($queue);
            $sorted[] = $current;
            foreach ($adj[$current] as $neighbor) {
                $inDegree[$neighbor]--;
                if ($inDegree[$neighbor] === 0) $queue[] = $neighbor;
            }
        }

        return $sorted;
    }

    /** @return array<string, NodeOutput> nodeId -> output */
    private function collectInput(string $targetId, array $edges, array $nodeOutputs): array
    {
        $input = [];
        foreach ($edges as $edge) {
            if (($edge['target'] ?? '') === $targetId) {
                $sourceId = $edge['source'];
                if (isset($nodeOutputs[$sourceId])) {
                    $input[$sourceId] = $nodeOutputs[$sourceId];
                }
            }
        }
        return $input;
    }

    private function findNode(array $nodes, string $id): ?array
    {
        foreach ($nodes as $n) {
            if (($n['id'] ?? '') === $id) return $n;
        }
        return null;
    }

    private function summarizeInput(array $input): array
    {
        $summary = [];
        foreach ($input as $nodeId => $output) {
            if ($output instanceof NodeOutput) {
                $summary[$nodeId] = array_keys($output->data);
            }
        }
        return $summary;
    }
}
