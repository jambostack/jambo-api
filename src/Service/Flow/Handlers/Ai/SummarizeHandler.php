<?php

namespace App\Service\Flow\Handlers\Ai;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class SummarizeHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        return new NodeOutput(data: [
            'result' => '[AI Summarize -- provider a configurer]',
            'text' => $config['text'] ?? '',
            'max_length' => $config['max_length'] ?? 200,
        ]);
    }

    public static function getCategory(): string { return 'ai'; }
    public static function getType(): string { return 'summarize'; }
    public static function getFullType(): string { return 'ai.summarize'; }
    public static function getLabel(): string { return 'Resumer'; }
    public static function getDescription(): string { return 'Resume un texte avec un LLM'; }
    public static function getIcon(): string { return 'AlignLeft'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['text'],
            'properties' => [
                'text' => ['type' => 'string', 'format' => 'textarea', 'title' => 'Texte a resumer', 'template' => true],
                'max_length' => ['type' => 'number', 'title' => 'Longueur max', 'default' => 200],
                'model' => ['type' => 'string', 'title' => 'Modele', 'default' => 'claude-sonnet-4-6'],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
