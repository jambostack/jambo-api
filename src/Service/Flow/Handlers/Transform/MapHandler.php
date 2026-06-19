<?php

namespace App\Service\Flow\Handlers\Transform;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class MapHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        $inputData = array_values($input)[0]->data ?? [];
        $items = $inputData['items'] ?? $inputData;
        $expression = $config['expression'] ?? '';
        $targetKey = $config['target_key'] ?? '';

        if (!is_array($items)) {
            $items = [$items];
        }

        $mapped = array_map(function ($item) use ($expression, $targetKey) {
            if ($expression !== '' && $targetKey !== '') {
                if (is_array($item)) {
                    $item[$targetKey] = $this->applyExpression($item, $expression);
                }
            }
            return $item;
        }, $items);

        return new NodeOutput(data: ['items' => $mapped, 'count' => count($mapped)]);
    }

    private function applyExpression(array $item, string $expression): mixed
    {
        return $expression;
    }

    public static function getCategory(): string { return 'transform'; }
    public static function getType(): string { return 'map'; }
    public static function getFullType(): string { return 'transform.map'; }
    public static function getLabel(): string { return 'Mapper'; }
    public static function getDescription(): string { return 'Applique une transformation a chaque element d un tableau'; }
    public static function getIcon(): string { return 'ArrowRightLeft'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'expression' => ['type' => 'string', 'format' => 'textarea', 'title' => 'Expression', 'template' => true],
                'target_key' => ['type' => 'string', 'title' => 'Cle cible', 'template' => true],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
