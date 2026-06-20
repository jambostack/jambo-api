<?php

namespace App\Service\Flow;

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

        // 3. Construit l'index des nœuds pour les recherches
        $nodeIndex = [];
        foreach ($nodes as $n) {
            $nodeIndex[$n['id']] = $n;
        }

        // 4. Topological sort (Kahn)
        $sorted = $this->topologicalSort($nodes, $edges);

        // 5. Exécute séquentiellement avec filtrage par branche
        $nodeOutputs = [];
        foreach ($sorted as $nodeId) {
            $node = $nodeIndex[$nodeId] ?? null;
            if (!$node) continue;

            $handler = $this->registry->resolve($node['type']);
            if (!$handler) {
                $ctx->logStep($nodeId, $node['type'], $node['data']['label'] ?? '', 'failed', 0, [], [], "Handler not found: {$node['type']}");
                continue;
            }

            // Collecte les inputs des edges entrants
            $input = $this->collectInput($nodeId, $edges, $nodeOutputs);

            // Si le nœud n'a pas d'input actif (branche non prise ou prédécesseur sauté)
            if ($input === null) {
                $ctx->logStep($nodeId, $node['type'], $node['data']['label'] ?? '', 'skipped', 0, [], [], 'Branch not taken');
                $nodeOutputs[$nodeId] = new NodeOutput(data: ['_skipped' => true], branch: 'skipped');
                continue;
            }

            // Ajoute le payload trigger pour le premier node (pas d'input)
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
                // Marque ce nœud comme erreur — les nœuds suivants seront sautés via collectInput
                $nodeOutputs[$nodeId] = new NodeOutput(data: ['error' => $e->getMessage()], branch: 'error');
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

    /**
     * Collecte les inputs pour un nœud cible en filtrant par branche.
     *
     * Chaque edge peut avoir une propriété `sourceBranch` qui indique
     * quelle branche de sortie du nœud source est attendue.
     *
     * Règles de filtrage :
     *  - sourceOutput->branch === 'skipped'  → cette connexion est ignorée
     *  - sourceOutput->branch === 'error'    → cette connexion est ignorée (le flow s'arrête)
     *  - edge spécifie `sourceBranch`        → seules les correspondances sont acceptées
     *  - sinon                               → la connexion est acceptée (compatibilité)
     *
     * @return array|null null si le nœud doit être sauté (branche non prise ou erreur en amont)
     */
    private function collectInput(string $targetId, array $edges, array $nodeOutputs): ?array
    {
        $connectedEdges = [];

        foreach ($edges as $edge) {
            if (($edge['target'] ?? '') === $targetId) {
                $connectedEdges[] = $edge;
            }
        }

        // Pas de connexion entrante = premier nœud (trigger), input vide
        if (empty($connectedEdges)) {
            return [];
        }

        $input = [];
        $hasActiveInput = false;

        foreach ($connectedEdges as $edge) {
            $sourceId = $edge['source'];
            if (!isset($nodeOutputs[$sourceId])) continue;

            $sourceOutput = $nodeOutputs[$sourceId];

            // Le nœud source a été sauté → cette connexion est inactive
            if ($sourceOutput->branch === 'skipped') continue;

            // Le nœud source est en erreur → propager l'arrêt
            if ($sourceOutput->branch === 'error') continue;

            // Filtrage par branche nommée si l'edge le spécifie
            $expectedBranch = $edge['sourceBranch'] ?? null;
            if ($expectedBranch !== null && $expectedBranch !== 'default') {
                if ($sourceOutput->branch !== $expectedBranch) {
                    continue;
                }
            }

            $hasActiveInput = true;
            $input[$sourceId] = $sourceOutput;
        }

        if (!$hasActiveInput) {
            return null;
        }

        return $input;
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
