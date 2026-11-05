<?php

namespace App\Service\Flow;

use App\Service\Security\SsrfProtectionService;
use Symfony\Contracts\Translation\TranslatorInterface;

class FlowValidator
{
    public const MAX_NODES = 100;
    public const MAX_EDGES = 250;
    public const MAX_GRAPH_DEPTH = 50;
    public const MAX_ID_LENGTH = 64;
    public const MAX_URL_LENGTH = 2048;

    private SsrfProtectionService $ssrfProtection;

    public function __construct(
        ?SsrfProtectionService $ssrfProtection = null,
        private readonly ?TranslatorInterface $translator = null,
    ) {
        $this->ssrfProtection = $ssrfProtection ?? new SsrfProtectionService();
    }

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            $translated = $this->translator->trans($id, $parameters, 'messages');
            if ($translated !== $id) {
                return $translated;
            }
        }
        if ($default !== null) {
            return strtr($default, $parameters);
        }
        return $id;
    }

    /** @return array{valid: bool, errors: string[]} */
    public function validate(array $flowGraph, NodeRegistry $registry): array
    {
        $errors = [];
        $nodes = $flowGraph['nodes'] ?? null;
        $edges = $flowGraph['edges'] ?? null;

        // 1. Structure
        if (!is_array($nodes) || empty($nodes)) {
            $errors[] = $this->trans('flow.validator.at_least_one_node', [], 'Le graphe doit contenir au moins un node');
            return ['valid' => false, 'errors' => $errors];
        }

        if (!isset($flowGraph['edges']) || !is_array($edges)) {
            $errors[] = $this->trans('flow.validator.edges_required', [], 'Le champ edges est requis');
            return ['valid' => false, 'errors' => $errors];
        }

        // 1b. Limites anti-DoS
        if (count($nodes) > self::MAX_NODES) {
            $errors[] = $this->trans('flow.validator.nodes_limit_exceeded', ['%max%' => (string) self::MAX_NODES, '%count%' => (string) count($nodes)], sprintf('Le graphe dépasse la limite de %d nodes (reçu: %d)', self::MAX_NODES, count($nodes)));
            return ['valid' => false, 'errors' => $errors];
        }

        if (count($edges) > self::MAX_EDGES) {
            $errors[] = $this->trans('flow.validator.edges_limit_exceeded', ['%max%' => (string) self::MAX_EDGES, '%count%' => (string) count($edges)], sprintf('Le graphe dépasse la limite de %d edges (reçu: %d)', self::MAX_EDGES, count($edges)));
            return ['valid' => false, 'errors' => $errors];
        }

        // 1c. Cloisonnement strict des variables de flow (prévention d'échappement global)
        if (isset($flowGraph['variables'])) {
            if (!is_array($flowGraph['variables'])) {
                $errors[] = $this->trans('flow.validator.variables_must_be_object', [], 'Le champ variables doit être un objet clé-valeur');
                return ['valid' => false, 'errors' => $errors];
            }
            if (count($flowGraph['variables']) > 50) {
                $errors[] = $this->trans('flow.validator.variables_max_count', ['%max%' => '50'], 'Le nombre de variables de flow ne peut excéder 50');
                return ['valid' => false, 'errors' => $errors];
            }
            foreach ($flowGraph['variables'] as $varKey => $varValue) {
                if (!is_string($varKey) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/', $varKey)) {
                    $errors[] = $this->trans('flow.validator.variable_invalid_format', ['%var%' => (string) $varKey], sprintf("Variable '%s' invalide : doit débuter par une lettre et comporter uniquement alphanumériques et underscores (max 64 car)", (string) $varKey));
                    continue;
                }
                foreach (FlowContext::FORBIDDEN_VARIABLE_PATTERNS as $pattern) {
                    if (preg_match($pattern, $varKey)) {
                        $errors[] = $this->trans('flow.validator.variable_forbidden', ['%var%' => $varKey], sprintf("Variable '%s' interdite : nom réservé ou sensible", $varKey));
                        break;
                    }
                }
                if (!is_scalar($varValue) && !is_array($varValue) && $varValue !== null) {
                    $errors[] = $this->trans('flow.validator.variable_invalid_type', ['%var%' => $varKey], sprintf("Variable '%s' : type de valeur invalide (seuls scalaires et tableaux autorisés)", $varKey));
                }
            }
        }

        // 2. Types valides et intégrité des nodes
        $nodeIds = [];
        foreach ($nodes as $index => $node) {
            if (!is_array($node)) {
                $errors[] = $this->trans('flow.validator.node_invalid_object', ['%index%' => (string) $index], "Node à l'index {$index} invalide (doit être un objet)");
                continue;
            }

            $id = $node['id'] ?? null;
            if (!is_string($id) || trim($id) === '') {
                $errors[] = $this->trans('flow.validator.node_missing_id', [], 'Un node sans id');
                continue;
            }

            if (strlen($id) > self::MAX_ID_LENGTH || !preg_match('/^[a-zA-Z0-9_\-]+$/', $id)) {
                $errors[] = $this->trans('flow.validator.node_invalid_id', ['%id%' => $id, '%max%' => (string) self::MAX_ID_LENGTH], "Node '{$id}': id invalide (doit être alphanumérique, _ ou -, max " . self::MAX_ID_LENGTH . " caractères)");
                continue;
            }

            if (in_array($id, $nodeIds, true)) {
                $errors[] = $this->trans('flow.validator.node_duplicate_id', ['%id%' => $id], "Node id en doublon : '{$id}'");
                continue;
            }

            $type = $node['type'] ?? null;
            if (!is_string($type) || trim($type) === '') {
                $errors[] = $this->trans('flow.validator.node_type_required', ['%id%' => $id], "Node {$id}: type requis");
                continue;
            }

            if (!$registry->resolve($type)) {
                $errors[] = $this->trans('flow.validator.node_unknown_type', ['%id%' => $id, '%type%' => $type], "Node {$id}: type '{$type}' inconnu");
            }

            $nodeIds[] = $id;
        }

        foreach ($edges as $edge) {
            if (!is_array($edge)) {
                $errors[] = $this->trans('flow.validator.edge_invalid_object', [], 'Edge invalide (doit être un objet)');
                continue;
            }

            $edgeId = (string) ($edge['id'] ?? 'unknown');
            $source = (string) ($edge['source'] ?? '');
            $target = (string) ($edge['target'] ?? '');

            if (!in_array($source, $nodeIds, true)) {
                $errors[] = $this->trans('flow.validator.edge_source_not_found', ['%edge%' => $edgeId, '%source%' => $source], "Edge {$edgeId}: source '{$source}' introuvable");
            }
            if (!in_array($target, $nodeIds, true)) {
                $errors[] = $this->trans('flow.validator.edge_target_not_found', ['%edge%' => $edgeId, '%target%' => $target], "Edge {$edgeId}: target '{$target}' introuvable");
            }
        }

        // Si des erreurs structurelles existent, retour immédiat
        if (!empty($errors)) {
            return ['valid' => false, 'errors' => $errors];
        }

        // 3. DAG check (algorithme de Kahn)
        if (!$this->isDAG($nodes, $edges)) {
            $errors[] = $this->trans('flow.validator.cycle_detected', [], 'Le graphe contient un cycle — les flows doivent être acycliques');
        } else {
            // 3b. Limite de profondeur du graphe
            $maxDepth = $this->calculateMaxDepth($nodes, $edges);
            if ($maxDepth > self::MAX_GRAPH_DEPTH) {
                $errors[] = $this->trans('flow.validator.max_depth_exceeded', ['%max%' => (string) self::MAX_GRAPH_DEPTH, '%depth%' => (string) $maxDepth], sprintf('Le graphe dépasse la profondeur maximale de %d niveaux (profondeur: %d)', self::MAX_GRAPH_DEPTH, $maxDepth));
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
            $errors[] = $this->trans('flow.validator.trigger_required', [], 'Le graphe doit contenir au moins un trigger');
        } elseif ($triggerCount > 10) {
            $errors[] = $this->trans('flow.validator.max_triggers_exceeded', ['%max%' => '10'], 'Le graphe ne peut pas contenir plus de 10 triggers');
        }

        // 5. Validation des entrées/sorties des nodes et anti-SSRF sur URLs statiques
        $targetIds = array_map(fn($e) => (string) ($e['target'] ?? ''), $edges);
        foreach ($nodes as $node) {
            $nid = (string) ($node['id'] ?? '');
            $type = (string) ($node['type'] ?? '');
            $isTrigger = str_starts_with($type, 'trigger.');

            if ($isTrigger && in_array($nid, $targetIds, true)) {
                $errors[] = $this->trans('flow.validator.trigger_incoming_edge', ['%node%' => $nid], "Node {$nid}: un trigger ne peut pas recevoir d'arêtes entrantes");
            }
            if (!$isTrigger && !in_array($nid, $targetIds, true)) {
                $errors[] = $this->trans('flow.validator.orphan_node', ['%node%' => $nid], "Node {$nid}: orphelin — aucune entrée et n'est pas un trigger");
            }

            // Contrôle de taille du payload config (anti-DoS mémoire)
            $config = $node['data']['config'] ?? null;
            if (is_array($config)) {
                $encodedConfig = json_encode($config);
                if ($encodedConfig !== false && strlen($encodedConfig) > 65536) {
                    $errors[] = $this->trans('flow.validator.config_too_large', ['%node%' => $nid, '%max%' => '64'], "Node {$nid}: configuration trop volumineuse (max 64 Ko)");
                }

                // Anti-SSRF préventif sur les URLs statiques et semi-statiques d'actions HTTP / Webhook
                if (isset($config['url']) && is_string($config['url'])) {
                    $url = trim($config['url']);
                    if ($url !== '') {
                        if (strlen($url) > self::MAX_URL_LENGTH) {
                            $errors[] = $this->trans('flow.validator.url_too_long', ['%node%' => $nid, '%max%' => (string) self::MAX_URL_LENGTH], "Node {$nid}: URL length exceeds " . self::MAX_URL_LENGTH . " characters");
                        } elseif (preg_match('/[\r\n\x00-\x1F\x7F]/', $url)) {
                            $errors[] = $this->trans('flow.validator.url_control_chars', ['%node%' => $nid], "Node {$nid}: URL contains forbidden control characters");
                        } elseif (!str_contains($url, '{{')) {
                            if (!$this->ssrfProtection->isSafeUrl($url, false, $reason)) {
                                $detail = $reason ?? $this->trans('flow.validator.ssrf_host_forbidden', ['%node%' => $nid, '%host%' => $url], "Node {$nid}: URL rejected by anti-SSRF policy.");
                                $errors[] = "Node {$nid}: " . $detail . " (anti-SSRF)";
                            }
                        } else {
                            // Si l'URL contient un template, mais que son hôte est statique (ex: https://169.254.169.254/{{ path }})
                            $parsed = parse_url($url);
                            if (is_array($parsed) && isset($parsed['host']) && !str_contains($parsed['host'], '{{')) {
                                $host = strtolower($parsed['host']);
                                if (!$this->ssrfProtection->isSafeHost($host, $reason)) {
                                    $detail = $reason ?? $this->trans('flow.validator.ssrf_host_forbidden', ['%node%' => $nid, '%host%' => $host], "Node {$nid}: target host '{$host}' is blocked by anti-SSRF policy.");
                                    $errors[] = "Node {$nid}: " . $detail . " (anti-SSRF)";
                                }
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
