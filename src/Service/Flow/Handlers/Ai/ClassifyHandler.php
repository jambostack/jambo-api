<?php

namespace App\Service\Flow\Handlers\Ai;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class ClassifyHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        return new NodeOutput(data: [
            'result' => '[AI Classify -- provider a configurer]',
            'text' => $config['text'] ?? '',
            'categories' => $config['categories'] ?? [],
        ]);
    }

    public static function getCategory(): string { return 'ai'; }
    public static function getType(): string { return 'classify'; }
    public static function getFullType(): string { return 'ai.classify'; }
    public static function getLabel(): string { return 'Classer'; }
    public static function getDescription(): string { return 'Classifie un texte dans des categories predefinies'; }
    public static function getIcon(): string { return 'Tags'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['text', 'categories'],
            'properties' => [
                'text' => ['type' => 'string', 'format' => 'textarea', 'title' => 'Texte a classer', 'template' => true],
                'categories' => ['type' => 'array', 'title' => 'Categories', 'items' => ['type' => 'string']],
                'model' => ['type' => 'string', 'title' => 'Modele', 'default' => 'claude-sonnet-4-6'],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
