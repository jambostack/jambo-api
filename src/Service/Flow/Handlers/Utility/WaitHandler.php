<?php

namespace App\Service\Flow\Handlers\Utility;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class WaitHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        $inputData = array_values($input)[0]->data ?? [];
        $delay = max(0, min(30000, (int) ($config['delay_ms'] ?? 1000)));

        if ($delay > 0) {
            usleep($delay * 1000);
        }

        return new NodeOutput(data: $inputData);
    }

    public static function getCategory(): string { return 'util'; }
    public static function getType(): string { return 'wait'; }
    public static function getFullType(): string { return 'util.wait'; }
    public static function getLabel(): string { return 'Attendre'; }
    public static function getDescription(): string { return 'Attend un delai configurable (max 30s)'; }
    public static function getIcon(): string { return 'Clock'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'delay_ms' => ['type' => 'number', 'title' => 'Delai (ms)', 'default' => 1000, 'minimum' => 0, 'maximum' => 30000],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
