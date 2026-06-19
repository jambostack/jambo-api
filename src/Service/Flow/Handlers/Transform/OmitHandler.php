<?php

namespace App\Service\Flow\Handlers\Transform;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class OmitHandler implements FlowNodeHandler
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
            $result = array_diff_key($inputData, array_flip($keys));
        } else {
            $result = $inputData;
        }

        return new NodeOutput(data: $result);
    }

    public static function getCategory(): string { return 'transform'; }
    public static function getType(): string { return 'omit'; }
    public static function getFullType(): string { return 'transform.omit'; }
    public static function getLabel(): string { return 'Omettre'; }
    public static function getDescription(): string { return 'Supprime des cles d un objet'; }
    public static function getIcon(): string { return 'ArrowDownToLine'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['keys'],
            'properties' => [
                'keys' => ['type' => 'array', 'title' => 'Cles a supprimer', 'items' => ['type' => 'string']],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
