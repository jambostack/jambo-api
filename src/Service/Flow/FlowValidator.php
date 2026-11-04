<?php

namespace App\Service\Flow;

class FlowValidator
{
    public const MAX_NODES = 100;
    public const MAX_EDGES = 250;
    public const MAX_GRAPH_DEPTH = 50;
    public const MAX_ID_LENGTH = 64;

    /** @return array{valid: bool, errors: string[]} */
    public function validate(array $flowGraph, NodeRegistry $registry): array
    {
        $errors = [];
        $nodes = $flowGraph['nodes'] ?? null;
        $edges = $flowGraph['edges'] ?? null;

        // 1. Structure
        if (!is_array($nodes) || empty($nodes)) {
            $errors[] = 'Le graphe doit contenir au moins un node';
            return ['valid' => false, 'errors' => $errors];
        }

        if (!isset($flowGraph['edges']) || !is_array($edges)) {
            $errors[] = 'Le champ edges est requis';
            return ['valid' => false, 'errors' => $errors];
        }

        // 1b. Limites anti-DoS
        if (count($nodes) > self::MAX_NODES) {
            $errors[] = sprintf('Le graphe dépasse la limite de %d nodes (reçu: %d)', self::MAX_NODES, count($nodes));
            return ['valid' => false, 'errors' => $errors];
        }

        if (count($edges) > self::MAX_EDGES) {
            $errors[] = sprintf('Le graphe dépasse la limite de %d edges (reçu: %d)', self::MAX_EDGES, count($edges));
            return ['valid' => false, 'errors' => $errors];
        }

        // 2. Types valides et intégrité des nodes
        $nodeIds = [];
        foreach ($nodes as $index => $node) {
            if (!is_array($node)) {
                $errors[] = "Node à l'index {$index} invalide (doit être un objet)";
                continue;
            }

            $id = $node['id'] ?? null;
            if (!is_string($id) || trim($id) === '') {
                $errors[] = 'Un node sans id';
                continue;
            }

            if (strlen($id) > self::MAX_ID_LENGTH || !preg_match('/^[a-zA-Z0-9_\-]+$/', $id)) {
                $errors[] = "Node '{$id}': id invalide (doit être alphanumérique, _ ou -, max " . self::MAX_ID_LENGTH . " caractères)";
                continue;
            }

            if (in_array($id, $nodeIds, true)) {
                $errors[] = "Node id en doublon : '{$id}'";
                continue;
            }

            $type = $node['type'] ?? null;
            if (!is_string($type) || trim($type) === '') {
                $errors[] = "Node {$id}: type requis";
                continue;
            }

            if (!$registry->resolve($type)) {
                $errors[] = "Node {$id}: type '{$type}' inconnu";
            }

            $nodeIds[] = $id;
        }

        foreach ($edges as $edge) {
            if (!is_array($edge)) {
                $errors[] = "Edge invalide (doit être un objet)";
                continue;
            }

            $edgeId = (string) ($edge['id'] ?? 'unknown');
            $source = (string) ($edge['source'] ?? '');
            $target = (string) ($edge['target'] ?? '');

            if (!in_array($source, $nodeIds, true)) {
                $errors[] = "Edge {$edgeId}: source '{$source}' introuvable";
            }
            if (!in_array($target, $nodeIds, true)) {
                $errors[] = "Edge {$edgeId}: target '{$target}' introuvable";
            }
        }

        // Si des erreurs structurelles existent, retour immédiat
        if (!empty($errors)) {
            return ['valid' => false, 'errors' => $errors];
        }

        // 3. DAG check (algorithme de Kahn)
        if (!$this->isDAG($nodes, $edges)) {
            $errors[] = 'Le graphe contient un cycle — les flows doivent être acycliques';
        }

        // 4. Au moins un trigger
        $hasTrigger = false;
        foreach ($nodes as $node) {
            if (str_starts_with((string) ($node['type'] ?? ''), 'trigger.')) {
                $hasTrigger = true;
                break;
            }
        }
        if (!$hasTrigger) {
            $errors[] = 'Le graphe doit contenir au moins un trigger';
        }

        // 5. Pas de node orphelin (sauf triggers qui sont les racines)
        $targetIds = array_map(fn($e) => (string) ($e['target'] ?? ''), $edges);
        foreach ($nodes as $node) {
            $nid = (string) ($node['id'] ?? '');
            $isTrigger = str_starts_with((string) ($node['type'] ?? ''), 'trigger.');
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
            $nid = (string) ($n['id'] ?? '');
            $inDegree[$nid] = 0;
            $adj[$nid] = [];
        }

        foreach ($edges as $e) {
            $source = (string) ($e['source'] ?? '');
            $target = (string) ($e['target'] ?? '');
            if (!isset($adj[$source]) || !isset($inDegree[$target])) {
                continue;
            }
            $adj[$source][] = $target;
            $inDegree[$target] = ($inDegree[$target] ?? 0) + 1;
        }

        $queue = [];
        foreach ($inDegree as $id => $deg) {
            if ($deg === 0) {
                $queue[] = $id;
            }
        }

        $visited = 0;
        while (!empty($queue)) {
            $current = array_shift($queue);
            $visited++;
            foreach ($adj[$current] as $neighbor) {
                $inDegree[$neighbor]--;
                if ($inDegree[$neighbor] === 0) {
                    $queue[] = $neighbor;
                }
            }
        }

        return $visited === count($nodes);
    }
}
