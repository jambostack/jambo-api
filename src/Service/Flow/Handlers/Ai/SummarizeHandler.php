<?php

namespace App\Service\Flow\Handlers\Ai;

use App\Service\AiContentService;
use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class SummarizeHandler implements FlowNodeHandler
{
    public function __construct(
        private readonly AiContentService $aiService,
    ) {}

    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        $text = $config['text'] ?? '';
        $maxLength = (int) ($config['max_length'] ?? 200);
        $model = $config['model'] ?? 'claude-sonnet-4-6';

        if (empty(trim($text))) {
            // Cherche le texte dans l'input précédent
            $firstInput = array_values($input)[0] ?? null;
            if ($firstInput instanceof NodeOutput) {
                $text = $firstInput->data['result'] ?? $firstInput->data['text'] ?? '';
            }
            if (is_array($firstInput)) {
                $text = $firstInput['result'] ?? $firstInput['text'] ?? '';
            }
        }

        if (empty(trim($text))) {
            return new NodeOutput(data: ['result' => '', 'error' => 'Aucun texte à résumer']);
        }

        try {
            // Utilise summarize() du AiContentService si adapté, sinon ask()
            $wordCount = (int) ceil($maxLength / 5);
            $prompt = "Résume ce texte en $wordCount mots maximum. Sois concis et factuel. Réponds uniquement avec le résumé, sans préambule.\n\n$text";
            $result = $this->aiService->ask($prompt, $model);
            return new NodeOutput(data: [
                'result' => $result,
                'original_length' => mb_strlen($text),
                'summary_length' => mb_strlen($result),
            ]);
        } catch (\Throwable $e) {
            return new NodeOutput(data: [
                'result' => '',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function getCategory(): string { return 'ai'; }
    public static function getType(): string { return 'summarize'; }
    public static function getFullType(): string { return 'ai.summarize'; }
    public static function getLabel(): string { return 'Résumer'; }
    public static function getDescription(): string { return 'Résume un texte avec un LLM'; }
    public static function getIcon(): string { return 'AlignLeft'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['text'],
            'properties' => [
                'text' => ['type' => 'string', 'format' => 'textarea', 'title' => 'Texte à résumer', 'template' => true],
                'max_length' => ['type' => 'number', 'title' => 'Longueur max (caractères)', 'default' => 200],
                'model' => ['type' => 'string', 'title' => 'Modèle', 'default' => 'claude-sonnet-4-6'],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
