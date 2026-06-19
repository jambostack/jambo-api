<?php

namespace App\Service\Flow\Handlers\Ai;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class LlmCallHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        return new NodeOutput(data: [
            'result' => '[AI LLM call -- provider a configurer]',
            'prompt' => $config['prompt'] ?? '',
            'model' => $config['model'] ?? 'default',
        ]);
    }

    public static function getCategory(): string { return 'ai'; }
    public static function getType(): string { return 'llm_call'; }
    public static function getFullType(): string { return 'ai.llm_call'; }
    public static function getLabel(): string { return 'Appel LLM'; }
    public static function getDescription(): string { return 'Appelle un modele de langage (Claude, GPT, etc.)'; }
    public static function getIcon(): string { return 'Sparkles'; }
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
                'system_prompt' => ['type' => 'string', 'format' => 'textarea', 'title' => 'System prompt', 'template' => true],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
