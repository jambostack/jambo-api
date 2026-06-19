<?php

namespace App\Service\Flow\Handlers\Transform;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class PickHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        $inputData = array_values($input)[0]->data ?? [];
        $keys = $config['keys'] ?? [];

        if (!is_array($keys)) {
            $keys = [];
        }

        if (is_array($inputData)) {
            $result = array_intersect_key($inputData, array_flip($keys));
        } else {
            $result = [];
        }

        return new NodeOutput(data: $result);
    }

    public static function getCategory(): string { return 'transform'; }
    public static function getType(): string { return 'pick'; }
    public static function getFullType(): string { return 'transform.pick'; }
    public static function getLabel(): string { return 'Extraire'; }
    public static function getDescription(): string { return 'Extrait des cles specifiques d un objet'; }
    public static function getIcon(): string { return 'ArrowUpFromLine'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['keys'],
            'properties' => [
                'keys' => ['type' => 'array', 'title' => 'Cles a extraire', 'items' => ['type' => 'string']],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
