<?php

namespace App\Service\Flow\Handlers\Transform;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class ReduceHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        $inputData = array_values($input)[0]->data ?? [];
        $items = $inputData['items'] ?? $inputData;
        $initialValue = $config['initial_value'] ?? 0;
        $key = $config['key'] ?? '';

        if (!is_array($items)) {
            $items = [$items];
        }

        $reduced = array_reduce($items, function ($carry, $item) use ($key) {
            if ($key !== '' && is_array($item)) {
                return $carry + ($item[$key] ?? 0);
            }
            if (is_numeric($item)) {
                return $carry + $item;
            }
            return $carry;
        }, $initialValue);

        return new NodeOutput(data: ['result' => $reduced]);
    }

    public static function getCategory(): string { return 'transform'; }
    public static function getType(): string { return 'reduce'; }
    public static function getFullType(): string { return 'transform.reduce'; }
    public static function getLabel(): string { return 'Reduire'; }
    public static function getDescription(): string { return 'Reduit un tableau a une valeur unique'; }
    public static function getIcon(): string { return 'Sigma'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'key' => ['type' => 'string', 'title' => 'Cle a additionner', 'template' => true],
                'initial_value' => ['type' => 'number', 'title' => 'Valeur initiale', 'default' => 0],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
