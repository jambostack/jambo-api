<?php

namespace App\Service\Flow\Handlers\Trigger;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class WebhookInboundHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $firstInput = array_values($input)[0] ?? [];
        $data = $firstInput instanceof NodeOutput ? $firstInput->data : (is_array($firstInput) ? $firstInput : []);
        return new NodeOutput(data: $data);
    }

    public static function getCategory(): string { return 'trigger'; }
    public static function getType(): string { return 'webhook.inbound'; }
    public static function getFullType(): string { return 'trigger.webhook.inbound'; }
    public static function getLabel(): string { return 'Webhook entrant'; }
    public static function getDescription(): string { return "Déclenché via un appel HTTP externe (webhook inbound)"; }
    public static function getIcon(): string { return 'Webhook'; }

    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'secret' => [
                    'type' => 'string',
                    'title' => 'Secret (optionnel)',
                    'description' => 'Secret partagé pour valider le webhook entrant',
                ],
            ],
        ];
    }

    public static function getOutputPorts(): array { return ['default']; }
}
