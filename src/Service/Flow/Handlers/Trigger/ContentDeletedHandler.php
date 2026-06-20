<?php

namespace App\Service\Flow\Handlers\Trigger;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class ContentDeletedHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $firstInput = array_values($input)[0] ?? [];
        $data = $firstInput instanceof NodeOutput ? $firstInput->data : (is_array($firstInput) ? $firstInput : []);
        return new NodeOutput(data: $data);
    }

    public static function getCategory(): string { return 'trigger'; }
    public static function getType(): string { return 'content.deleted'; }
    public static function getFullType(): string { return 'trigger.content.deleted'; }
    public static function getLabel(): string { return 'Contenu supprimé'; }
    public static function getDescription(): string { return "Se déclenche quand un contenu est supprimé"; }
    public static function getIcon(): string { return 'Trash2'; }

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
