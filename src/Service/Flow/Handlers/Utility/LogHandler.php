<?php

namespace App\Service\Flow\Handlers\Utility;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class LogHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        $inputData = array_values($input)[0]->data ?? [];

        // Log the input data into the stepLog for debugging
        $ctx->logStep(
            nodeId: spl_object_id($this),
            type: 'util.log',
            label: $config['label'] ?? 'Log',
            status: 'success',
            durationMs: 0,
            input: $inputData,
            output: $inputData,
            error: null,
        );

        return new NodeOutput(data: $inputData);
    }

    public static function getCategory(): string { return 'util'; }
    public static function getType(): string { return 'log'; }
    public static function getFullType(): string { return 'util.log'; }
    public static function getLabel(): string { return 'Journaliser'; }
    public static function getDescription(): string { return 'Enregistre les donnees dans le journal d execution'; }
    public static function getIcon(): string { return 'ScrollText'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'label' => ['type' => 'string', 'title' => 'Etiquette', 'default' => 'Log'],
                'level' => ['type' => 'string', 'enum' => ['debug', 'info', 'warn', 'error'], 'title' => 'Niveau', 'default' => 'info'],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
