<?php

namespace App\Service\Flow\Handlers\Ai;

use App\Service\AiContentService;
use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class LlmCallHandler implements FlowNodeHandler
{
    public function __construct(
        private readonly AiContentService $aiService,
    ) {}

    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        $prompt = $config['prompt'] ?? '';
        $model = $config['model'] ?? 'claude-sonnet-4-6';
        $systemPrompt = $config['system_prompt'] ?? '';
        $temperature = $config['temperature'] ?? null;

        if (empty(trim($prompt))) {
            return new NodeOutput(data: ['result' => '', 'error' => 'Prompt vide']);
        }

        $fullPrompt = $systemPrompt ? "$systemPrompt\n\n$prompt" : $prompt;

        try {
            $result = $this->aiService->ask($fullPrompt, $model);
            return new NodeOutput(data: [
                'result' => $result,
                'model' => $model,
            ]);
        } catch (\Throwable $e) {
            return new NodeOutput(data: [
                'result' => '',
                'error' => $e->getMessage(),
                'model' => $model,
            ]);
        }
    }

    public static function getCategory(): string { return 'ai'; }
    public static function getType(): string { return 'llm_call'; }
    public static function getFullType(): string { return 'ai.llm_call'; }
    public static function getLabel(): string { return 'Appel LLM'; }
    public static function getDescription(): string { return 'Appelle un modèle de langage (Claude, GPT, etc.)'; }
    public static function getIcon(): string { return 'Sparkles'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['prompt'],
            'properties' => [
                'prompt' => ['type' => 'string', 'format' => 'textarea', 'title' => 'Prompt', 'template' => true],
                'model' => ['type' => 'string', 'title' => 'Modèle', 'default' => 'claude-sonnet-4-6'],
                'max_tokens' => ['type' => 'number', 'title' => 'Max tokens', 'default' => 1024],
                'temperature' => ['type' => 'number', 'title' => 'Température', 'default' => 0.7],
                'system_prompt' => ['type' => 'string', 'format' => 'textarea', 'title' => 'System prompt', 'template' => true],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
