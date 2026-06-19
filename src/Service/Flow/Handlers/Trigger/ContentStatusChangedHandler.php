<?php

namespace App\Service\Flow\Handlers\Trigger;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class ContentStatusChangedHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $firstInput = array_values($input)[0] ?? [];
        return new NodeOutput(data: $firstInput);
    }

    public static function getCategory(): string { return 'trigger'; }
    public static function getType(): string { return 'content.status_changed'; }
    public static function getFullType(): string { return 'trigger.content.status_changed'; }
    public static function getLabel(): string { return 'Statut changé'; }
    public static function getDescription(): string { return "Se déclenche quand le statut d'un contenu change (draft → published, etc.)"; }
    public static function getIcon(): string { return 'GitMerge'; }

    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'collection_slugs' => [
                    'type' => 'array',
                    'title' => 'Collections (optionnel)',
                    'items' => ['type' => 'string'],
                ],
            ],
        ];
    }

    public static function getOutputPorts(): array { return ['default']; }
}
