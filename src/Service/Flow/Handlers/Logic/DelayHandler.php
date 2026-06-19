<?php

namespace App\Service\Flow\Handlers\Logic;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class DelayHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        $ms = ($config['delay_ms'] ?? 1000);
        usleep($ms * 1000);
        $firstInput = array_values($input)[0] ?? [];
        $data = is_object($firstInput) ? ($firstInput->data ?? []) : [];

        return new NodeOutput(
            data: array_merge($data, ['delayed_ms' => $ms]),
        );
    }

    public static function getCategory(): string { return 'logic'; }
    public static function getType(): string { return 'delay'; }
    public static function getFullType(): string { return 'logic.delay'; }
    public static function getLabel(): string { return 'Délai'; }
    public static function getDescription(): string { return "Pause l'exécution pendant X millisecondes"; }
    public static function getIcon(): string { return 'Hourglass'; }

    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'delay_ms' => ['type' => 'number', 'title' => 'Délai (ms)', 'default' => 1000],
            ],
        ];
    }

    public static function getOutputPorts(): array { return ['default']; }
}
