<?php

namespace App\Service\Flow\Handlers\Ai;

use App\Service\AiContentService;
use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class GenerateTextHandler implements FlowNodeHandler
{
    public function __construct(
        private readonly AiContentService $aiService,
    ) {}

    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        $prompt = $config['prompt'] ?? '';
        $model = $config['model'] ?? 'claude-sonnet-4-6';

        if (empty(trim($prompt))) {
            return new NodeOutput(data: ['result' => '', 'error' => 'Prompt vide']);
        }

        try {
            $result = $this->aiService->ask(
                "Génère du contenu de haute qualité. Réponds uniquement avec le contenu demandé, sans préambule.\n\n$prompt",
                $model
            );
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
    public static function getType(): string { return 'generate_text'; }
    public static function getFullType(): string { return 'ai.generate_text'; }
    public static function getLabel(): string { return 'Générer du texte'; }
    public static function getDescription(): string { return 'Génère du texte à partir d\'un prompt'; }
    public static function getIcon(): string { return 'PenLine'; }
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
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
