<?php

namespace App\Service\Flow\Handlers\Transform;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class SetHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        $key = $config['key'] ?? '';
        $value = $config['value'] ?? '';

        if ($key !== '') {
            $ctx->variables[$key] = $value;
        }

        return new NodeOutput(data: $input);
    }

    public static function getCategory(): string { return 'transform'; }
    public static function getType(): string { return 'set'; }
    public static function getFullType(): string { return 'transform.set'; }
    public static function getLabel(): string { return 'Definir variable'; }
    public static function getDescription(): string { return 'Ecrit une valeur dans les variables du contexte'; }
    public static function getIcon(): string { return 'Variable'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['key', 'value'],
            'properties' => [
                'key' => ['type' => 'string', 'title' => 'Cle', 'template' => true],
                'value' => ['type' => 'string', 'title' => 'Valeur', 'template' => true],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
