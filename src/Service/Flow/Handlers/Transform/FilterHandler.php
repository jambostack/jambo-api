<?php

namespace App\Service\Flow\Handlers\Transform;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class FilterHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        $inputData = array_values($input)[0]->data ?? [];
        $items = $inputData['items'] ?? $inputData;
        $conditionKey = $config['condition_key'] ?? '';
        $conditionValue = $config['condition_value'] ?? '';

        if (!is_array($items)) {
            $items = [$items];
        }

        $filtered = array_filter($items, function ($item) use ($conditionKey, $conditionValue) {
            if ($conditionKey === '' && $conditionValue === '') {
                return true;
            }
            if (is_array($item) && $conditionKey !== '') {
                return ($item[$conditionKey] ?? null) === $conditionValue;
            }
            return $item === $conditionValue;
        });

        return new NodeOutput(data: ['items' => array_values($filtered), 'count' => count($filtered)]);
    }

    public static function getCategory(): string { return 'transform'; }
    public static function getType(): string { return 'filter'; }
    public static function getFullType(): string { return 'transform.filter'; }
    public static function getLabel(): string { return 'Filtrer'; }
    public static function getDescription(): string { return 'Filtre un tableau avec une condition'; }
    public static function getIcon(): string { return 'Filter'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'condition_key' => ['type' => 'string', 'title' => 'Cle', 'template' => true],
                'condition_value' => ['type' => 'string', 'title' => 'Valeur', 'template' => true],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
