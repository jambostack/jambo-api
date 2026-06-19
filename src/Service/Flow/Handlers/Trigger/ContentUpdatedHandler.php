<?php

namespace App\Service\Flow\Handlers\Trigger;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class ContentUpdatedHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $firstInput = array_values($input)[0] ?? [];
        return new NodeOutput(data: $firstInput);
    }

    public static function getCategory(): string { return 'trigger'; }
    public static function getType(): string { return 'content.updated'; }
    public static function getFullType(): string { return 'trigger.content.updated'; }
    public static function getLabel(): string { return 'Contenu modifié'; }
    public static function getDescription(): string { return "Se déclenche quand un contenu est modifié"; }
    public static function getIcon(): string { return 'FileEdit'; }

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
