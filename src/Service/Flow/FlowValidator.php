<?php

namespace App\Service\Flow;

class FlowValidator
{
    /** @return array{valid: bool, errors: string[]} */
    public function validate(array $flowGraph, NodeRegistry $registry): array
    {
        $errors = [];
        $nodes = $flowGraph['nodes'] ?? [];
        $edges = $flowGraph['edges'] ?? [];

        // 1. Structure
        if (empty($nodes)) $errors[] = 'Le graphe doit contenir au moins un node';
        if (!isset($flowGraph['edges'])) $errors[] = 'Le champ edges est requis';

        // 2. Types valides
        $nodeIds = [];
        foreach ($nodes as $node) {
            if (empty($node['id'])) { $errors[] = 'Un node sans id'; continue; }
            if (empty($node['type'])) { $errors[] = "Node {$node['id']}: type requis"; continue; }
            if (!$registry->resolve($node['type'])) {
                $errors[] = "Node {$node['id']}: type '{$node['type']}' inconnu";
            }
            $nodeIds[] = $node['id'];
        }

        foreach ($edges as $edge) {
            if (!in_array($edge['source'] ?? '', $nodeIds, true)) {
                $errors[] = "Edge {$edge['id']}: source '{$edge['source']}' introuvable";
            }
            if (!in_array($edge['target'] ?? '', $nodeIds, true)) {
                $errors[] = "Edge {$edge['id']}: target '{$edge['target']}' introuvable";
            }
        }

        // 3. DAG check (algorithme de Kahn)
        if (empty($errors)) {
            if (!$this->isDAG($nodes, $edges)) {
                $errors[] = 'Le graphe contient un cycle — les flows doivent être acycliques';
            }
        }

        // 4. Au moins un trigger
        $hasTrigger = false;
        foreach ($nodes as $node) {
            if (str_starts_with($node['type'] ?? '', 'trigger.')) {
                $hasTrigger = true;
                break;
            }
        }
        if (!$hasTrigger) $errors[] = 'Le graphe doit contenir au moins un trigger';

        // 5. Pas de node orphelin (sauf triggers qui sont les racines)
        $targetIds = array_map(fn($e) => $e['target'], $edges);
        foreach ($nodes as $node) {
            $nid = $node['id'];
            $isTrigger = str_starts_with($node['type'], 'trigger.');
            if (!$isTrigger && !in_array($nid, $targetIds, true)) {
                $errors[] = "Node {$nid}: orphelin — aucune entrée et n'est pas un trigger";
            }
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    private function isDAG(array $nodes, array $edges): bool
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

        $visited = 0;
        while (!empty($queue)) {
            $current = array_shift($queue);
            $visited++;
            foreach ($adj[$current] as $neighbor) {
                $inDegree[$neighbor]--;
                if ($inDegree[$neighbor] === 0) $queue[] = $neighbor;
            }
        }

        return $visited === count($nodes);
    }
}
