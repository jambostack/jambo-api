<?php

namespace App\Service\Flow\Handlers\Ai;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class GenerateTextHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        return new NodeOutput(data: [
            'result' => '[AI GenerateText -- provider a configurer]',
            'prompt' => $config['prompt'] ?? '',
            'model' => $config['model'] ?? 'default',
        ]);
    }

    public static function getCategory(): string { return 'ai'; }
    public static function getType(): string { return 'generate_text'; }
    public static function getFullType(): string { return 'ai.generate_text'; }
    public static function getLabel(): string { return 'Generer du texte'; }
    public static function getDescription(): string { return 'Genere du texte a partir d un prompt'; }
    public static function getIcon(): string { return 'PenLine'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['prompt'],
            'properties' => [
                'prompt' => ['type' => 'string', 'format' => 'textarea', 'title' => 'Prompt', 'template' => true],
                'model' => ['type' => 'string', 'title' => 'Modele', 'default' => 'claude-sonnet-4-6'],
                'max_tokens' => ['type' => 'number', 'title' => 'Max tokens', 'default' => 1024],
                'temperature' => ['type' => 'number', 'title' => 'Temperature', 'default' => 0.7],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
