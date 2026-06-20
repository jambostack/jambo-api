<?php

namespace App\Service\Flow\Handlers\Ai;

use App\Service\AiContentService;
use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class TranslateHandler implements FlowNodeHandler
{
    public function __construct(
        private readonly AiContentService $aiService,
    ) {}

    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        $text = $config['text'] ?? '';
        $targetLanguage = $config['target_language'] ?? 'en';
        $model = $config['model'] ?? 'claude-sonnet-4-6';

        if (empty(trim($text))) {
            $firstInput = array_values($input)[0] ?? null;
            if ($firstInput instanceof NodeOutput) {
                $text = $firstInput->data['result'] ?? $firstInput->data['text'] ?? '';
            }
            if (is_array($firstInput)) {
                $text = $firstInput['result'] ?? $firstInput['text'] ?? '';
            }
        }

        if (empty(trim($text))) {
            return new NodeOutput(data: ['result' => '', 'error' => 'Aucun texte à traduire']);
        }

        $langNames = [
            'fr' => 'français', 'en' => 'anglais', 'es' => 'espagnol',
            'ar' => 'arabe', 'de' => 'allemand', 'it' => 'italien',
            'pt' => 'portugais', 'nl' => 'néerlandais', 'ru' => 'russe',
            'zh' => 'chinois', 'ja' => 'japonais', 'ko' => 'coréen',
        ];
        $langLabel = $langNames[$targetLanguage] ?? $targetLanguage;

        try {
            $prompt = "Traduis le texte suivant en $langLabel. Réponds UNIQUEMENT avec la traduction, sans aucun commentaire ni préambule.\n\n$text";
            $result = $this->aiService->ask($prompt, $model);
            return new NodeOutput(data: [
                'result' => $result,
                'source_language' => 'auto',
                'target_language' => $targetLanguage,
            ]);
        } catch (\Throwable $e) {
            return new NodeOutput(data: [
                'result' => '',
                'error' => $e->getMessage(),
                'target_language' => $targetLanguage,
            ]);
        }
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
                'text' => ['type' => 'string', 'format' => 'textarea', 'title' => 'Texte à traduire', 'template' => true],
                'target_language' => ['type' => 'string', 'title' => 'Langue cible', 'default' => 'en'],
                'model' => ['type' => 'string', 'title' => 'Modèle', 'default' => 'claude-sonnet-4-6'],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
