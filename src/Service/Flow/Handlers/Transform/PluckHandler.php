<?php

namespace App\Service\Flow\Handlers\Transform;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class PluckHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        $inputData = array_values($input)[0]->data ?? [];
        $items = $inputData['items'] ?? $inputData;
        $key = $config['key'] ?? '';

        if (!is_array($items)) {
            $items = [$items];
        }

        $plucked = array_map(function ($item) use ($key) {
            if (is_array($item)) {
                return $item[$key] ?? null;
            }
            return null;
        }, $items);

        return new NodeOutput(data: ['items' => $plucked, 'count' => count($plucked)]);
    }

    public static function getCategory(): string { return 'transform'; }
    public static function getType(): string { return 'pluck'; }
    public static function getFullType(): string { return 'transform.pluck'; }
    public static function getLabel(): string { return 'Pluck'; }
    public static function getDescription(): string { return 'Extrait une colonne d un tableau d objets'; }
    public static function getIcon(): string { return 'Columns2'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['key'],
            'properties' => [
                'key' => ['type' => 'string', 'title' => 'Cle a extraire', 'template' => true],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
