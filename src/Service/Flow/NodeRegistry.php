<?php

namespace App\Service\Flow;

class NodeRegistry
{
    /** @var array<string, FlowNodeHandler> */
    private array $handlers = [];

    public function addHandler(FlowNodeHandler $handler): void
    {
        $this->handlers[$handler::getFullType()] = $handler;
    }

    public function resolve(string $type): ?FlowNodeHandler
    {
        return $this->handlers[$type] ?? null;
    }

    /** @return FlowNodeHandler[] */
    public function all(): array
    {
        return array_values($this->handlers);
    }

    /** Retourne le catalogue pour l'API node-catalog */
    public function getCatalog(): array
    {
        $categories = [];
        foreach ($this->handlers as $handler) {
            $cat = $handler::getCategory();
            $categories[$cat]['key'] ??= $cat;
            $categories[$cat]['label'] ??= $this->categoryLabel($cat);
            $categories[$cat]['color'] ??= $this->categoryColor($cat);
            $categories[$cat]['nodes'][] = [
                'type' => $handler::getFullType(),
                'label' => $handler::getLabel(),
                'description' => $handler::getDescription(),
                'icon' => $handler::getIcon(),
                'configSchema' => $handler::getConfigSchema(),
                'outputPorts' => $handler::getOutputPorts(),
            ];
        }
        return array_values($categories);
    }

    private function categoryLabel(string $cat): string
    {
        return match ($cat) {
            'trigger' => 'Déclencheurs',
            'logic' => 'Logique',
            'action' => 'Actions',
            'http' => 'HTTP',
            'ai' => 'IA',
            'db' => 'Base de données',
            'file' => 'Fichiers',
            'transform' => 'Transformation',
            'util' => 'Utilitaires',
            default => $cat,
        };
    }

    private function categoryColor(string $cat): string
    {
        return match ($cat) {
            'trigger' => '#3b82f6',
            'logic' => '#f97316',
            'action' => '#8b5cf6',
            'http' => '#22c55e',
            'ai' => '#ec4899',
            'db' => '#eab308',
            'file' => '#6b7280',
            'transform' => '#06b6d4',
            'util' => '#475569',
            default => '#94a3b8',
        };
    }
}
