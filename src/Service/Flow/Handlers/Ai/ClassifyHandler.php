<?php

namespace App\Service\Flow\Handlers\Ai;

use App\Service\AiContentService;
use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class ClassifyHandler implements FlowNodeHandler
{
    public function __construct(
        private readonly AiContentService $aiService,
    ) {}

    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        $text = $config['text'] ?? '';
        $categories = $config['categories'] ?? [];
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
            return new NodeOutput(data: ['category' => '', 'error' => 'Aucun texte à classifier']);
        }

        if (empty($categories)) {
            return new NodeOutput(data: ['category' => '', 'error' => 'Aucune catégorie définie']);
        }

        $catList = implode('", "', $categories);

        try {
            $prompt = "Classifie le texte suivant dans UNE SEULE des catégories proposées. Réponds UNIQUEMENT avec le nom exact de la catégorie choisie, rien d'autre.\n\nCatégories: \"$catList\"\n\nTexte: $text";
            $result = trim($this->aiService->ask($prompt, $model));

            // Valide que la réponse est bien une des catégories
            $matched = $result;
            foreach ($categories as $cat) {
                if (strcasecmp($result, $cat) === 0) {
                    $matched = $cat;
                    break;
                }
            }

            return new NodeOutput(data: [
                'category' => $matched,
                'categories' => $categories,
                'confidence' => in_array($matched, $categories, true) ? 'high' : 'low',
            ]);
        } catch (\Throwable $e) {
            return new NodeOutput(data: [
                'category' => '',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function getCategory(): string { return 'ai'; }
    public static function getType(): string { return 'classify'; }
    public static function getFullType(): string { return 'ai.classify'; }
    public static function getLabel(): string { return 'Classer'; }
    public static function getDescription(): string { return 'Classifie un texte dans des catégories prédéfinies'; }
    public static function getIcon(): string { return 'Tags'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['text', 'categories'],
            'properties' => [
                'text' => ['type' => 'string', 'format' => 'textarea', 'title' => 'Texte à classer', 'template' => true],
                'categories' => ['type' => 'array', 'title' => 'Catégories', 'items' => ['type' => 'string']],
                'model' => ['type' => 'string', 'title' => 'Modèle', 'default' => 'claude-sonnet-4-6'],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
