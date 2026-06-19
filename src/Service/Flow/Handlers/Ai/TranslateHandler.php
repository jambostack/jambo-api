<?php

namespace App\Service\Flow\Handlers\Ai;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class TranslateHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        return new NodeOutput(data: [
            'result' => '[AI Translate -- provider a configurer]',
            'text' => $config['text'] ?? '',
            'target_language' => $config['target_language'] ?? 'en',
        ]);
    }

    public static function getCategory(): string { return 'ai'; }
    public static function getType(): string { return 'translate'; }
    public static function getFullType(): string { return 'ai.translate'; }
    public static function getLabel(): string { return 'Traduire'; }
    public static function getDescription(): string { return 'Traduit un texte dans une langue cible'; }
    public static function getIcon(): string { return 'Languages'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['text', 'target_language'],
            'properties' => [
                'text' => ['type' => 'string', 'format' => 'textarea', 'title' => 'Texte a traduire', 'template' => true],
                'target_language' => ['type' => 'string', 'title' => 'Langue cible', 'default' => 'en'],
                'model' => ['type' => 'string', 'title' => 'Modele', 'default' => 'claude-sonnet-4-6'],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
