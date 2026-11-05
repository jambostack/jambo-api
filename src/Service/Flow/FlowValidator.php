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

        // 1c. Cloisonnement strict des variables de flow (prévention d'échappement global)
        if (isset($flowGraph['variables'])) {
            if (!is_array($flowGraph['variables'])) {
                $errors[] = 'Le champ variables doit être un objet clé-valeur';
                return ['valid' => false, 'errors' => $errors];
            }
            if (count($flowGraph['variables']) > 50) {
                $errors[] = 'Le nombre de variables de flow ne peut excéder 50';
                return ['valid' => false, 'errors' => $errors];
            }
            foreach ($flowGraph['variables'] as $varKey => $varValue) {
                if (!is_string($varKey) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/', $varKey)) {
                    $errors[] = sprintf("Variable '%s' invalide : doit débuter par une lettre et comporter uniquement alphanumériques et underscores (max 64 car)", (string) $varKey);
                    continue;
                }
                foreach (FlowContext::FORBIDDEN_VARIABLE_PATTERNS as $pattern) {
                    if (preg_match($pattern, $varKey)) {
                        $errors[] = sprintf("Variable '%s' interdite : nom réservé ou sensible", $varKey);
                        break;
                    }
                }
                if (!is_scalar($varValue) && !is_array($varValue) && $varValue !== null) {
                    $errors[] = sprintf("Variable '%s' : type de valeur invalide (seuls scalaires et tableaux autorisés)", $varKey);
                }
            }
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
        } else {
            // 3b. Limite de profondeur du graphe
            $maxDepth = $this->calculateMaxDepth($nodes, $edges);
            if ($maxDepth > self::MAX_GRAPH_DEPTH) {
                $errors[] = sprintf('Le graphe dépasse la profondeur maximale de %d niveaux (profondeur: %d)', self::MAX_GRAPH_DEPTH, $maxDepth);
            }
        }

        // 4. Au moins un trigger
        $triggerCount = 0;
        foreach ($nodes as $node) {
            if (str_starts_with((string) ($node['type'] ?? ''), 'trigger.')) {
                $triggerCount++;
            }
        }
        if ($triggerCount === 0) {
            $errors[] = 'Le graphe doit contenir au moins un trigger';
        } elseif ($triggerCount > 10) {
            $errors[] = 'Le graphe ne peut pas contenir plus de 10 triggers';
        }

        // 5. Validation des entrées/sorties des nodes et anti-SSRF sur URLs statiques
        $targetIds = array_map(fn($e) => (string) ($e['target'] ?? ''), $edges);
        foreach ($nodes as $node) {
            $nid = (string) ($node['id'] ?? '');
            $type = (string) ($node['type'] ?? '');
            $isTrigger = str_starts_with($type, 'trigger.');

            if ($isTrigger && in_array($nid, $targetIds, true)) {
                $errors[] = "Node {$nid}: un trigger ne peut pas recevoir d'arêtes entrantes";
            }
            if (!$isTrigger && !in_array($nid, $targetIds, true)) {
                $errors[] = "Node {$nid}: orphelin — aucune entrée et n'est pas un trigger";
            }

            // Contrôle de taille du payload config (anti-DoS mémoire)
            $config = $node['data']['config'] ?? null;
            if (is_array($config)) {
                $encodedConfig = json_encode($config);
                if ($encodedConfig !== false && strlen($encodedConfig) > 65536) {
                    $errors[] = "Node {$nid}: configuration trop volumineuse (max 64 Ko)";
                }

                // Anti-SSRF préventif sur les URLs statiques d'actions HTTP / Webhook
                if (isset($config['url']) && is_string($config['url'])) {
                    $url = trim($config['url']);
                    if ($url !== '' && !str_contains($url, '{{')) {
                        $parsed = parse_url($url);
                        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
                            $errors[] = "Node {$nid}: URL invalide ('{$url}')";
                        } elseif (!in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
                            $errors[] = "Node {$nid}: protocole non autorisé ('{$parsed['scheme']}'). Seuls HTTP et HTTPS sont supportés.";
                        } else {
                            $host = strtolower($parsed['host']);
                            if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0', '169.254.169.254', 'metadata.google.internal', 'instance-data'], true)
                                || str_ends_with($host, '.localhost')
                                || str_ends_with($host, '.internal')
                                || str_ends_with($host, '.local')
                            ) {
                                $errors[] = "Node {$nid}: hôte interdit ('{$host}') par la politique de sécurité anti-SSRF.";
                            }
                        }
                    }
                }
            }
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    private function calculateMaxDepth(array $nodes, array $edges): int
    {
        $adj = [];
        $inDegree = [];
        foreach ($nodes as $n) {
            $nid = (string) ($n['id'] ?? '');
            $adj[$nid] = [];
            $inDegree[$nid] = 0;
        }

        foreach ($edges as $e) {
            $source = (string) ($e['source'] ?? '');
            $target = (string) ($e['target'] ?? '');
            if (isset($adj[$source], $inDegree[$target])) {
                $adj[$source][] = $target;
                $inDegree[$target]++;
            }
        }

        $queue = [];
        $depth = [];
        foreach ($inDegree as $id => $deg) {
            $depth[$id] = 1;
            if ($deg === 0) {
                $queue[] = $id;
            }
        }

        while (!empty($queue)) {
            $curr = array_shift($queue);
            foreach ($adj[$curr] as $nxt) {
                if (($depth[$curr] ?? 1) + 1 > ($depth[$nxt] ?? 1)) {
                    $depth[$nxt] = ($depth[$curr] ?? 1) + 1;
                }
                $inDegree[$nxt]--;
                if ($inDegree[$nxt] === 0) {
                    $queue[] = $nxt;
                }
            }
        }

        return empty($depth) ? 0 : max($depth);
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
