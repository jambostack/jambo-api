<?php

namespace App\Service;

use App\Entity\Collection;
use Symfony\AI\Platform\PlatformInterface;

class AiContentService
{
    private array $platforms;

    private const MODELS = [
        'fast' => 'gpt-4o-mini',
        'smart' => 'claude-sonnet-4-6',
        'local' => 'llama3.2',
    ];

    public function __construct(
        PlatformInterface $openaiPlatform,
        PlatformInterface $anthropicPlatform,
        PlatformInterface $ollamaPlatform,
    ) {
        // Autowiring via named parameters: these are bound in services.yaml
        $this->platforms = [
            'openai' => $openaiPlatform,
            'anthropic' => $anthropicPlatform,
            'ollama' => $ollamaPlatform,
        ];
    }

    /**
     * Ask any provider with automatic fallback and timeout.
     */
    public function ask(string $prompt, string $model = 'gpt-4o-mini', ?string $provider = null): string
    {
        if ($provider && isset($this->platforms[$provider])) {
            return $this->platforms[$provider]->ask($prompt, $model);
        }

        // Provider mapping by model prefix
        $modelProvider = match (true) {
            str_starts_with($model, 'gpt-') || str_starts_with($model, 'o1') || str_starts_with($model, 'o3') => 'openai',
            str_starts_with($model, 'claude-') => 'anthropic',
            default => 'anthropic', // fallback par défaut
        };

        if (isset($this->platforms[$modelProvider])) {
            try {
                return $this->platforms[$modelProvider]->ask($prompt, $model);
            } catch (\Throwable) {
                // Fallback: essayer les autres providers
            }
        }

        // Fallback : essayer chaque provider restant
        foreach ($this->platforms as $name => $platform) {
            if ($name === $modelProvider) continue; // déjà essayé
            try {
                return $platform->ask($prompt, $model);
            } catch (\Throwable) {
                continue;
            }
        }

        throw new \RuntimeException('Aucun fournisseur IA disponible');
    }

    /**
     * Generate content from a natural language brief.
     */
    public function generateContent(string $brief, Collection $collection, string $locale = 'fr'): array
    {
        $fields = $this->describeFields($collection);

        $prompt = <<<PROMPT
Tu es un rédacteur de contenu expert. Génère le contenu pour une collection CMS.

Collection: {$collection->name}
Description: {$collection->description}
Locale: {$locale}

Champs à remplir:
$fields

Brief de l'utilisateur: $brief

Retourne UNIQUEMENT un objet JSON valide avec les champs comme clés (pas de texte autour).
Chaque champ doit contenir une valeur appropriée au type décrit.
PROMPT;

        $response = $this->ask($prompt, self::MODELS['smart']);

        $json = $this->extractJson($response);

        return $json ?? ['error' => 'Impossible de parser la réponse IA', 'raw' => $response];
    }

    /**
     * Translate content to another locale.
     */
    public function translateContent(array $content, string $targetLocale): array
    {
        $prompt = "Traduis ce contenu JSON en $targetLocale. Conserve la structure exacte, ne traduis QUE les valeurs textuelles. Retourne UNIQUEMENT le JSON traduit:\n\n" . json_encode($content, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $response = $this->ask($prompt, self::MODELS['fast']);

        $json = $this->extractJson($response);

        return $json ?? ['error' => 'Erreur de traduction'];
    }

    /**
     * Summarize a text.
     */
    public function summarize(string $text, int $maxWords = 80): string
    {
        $prompt = "Résume ce texte en $maxWords mots maximum, en français:\n\n$text";

        return $this->ask($prompt, self::MODELS['fast']);
    }

    /**
     * Generate SEO metadata for content.
     */
    public function generateSeo(array $content): array
    {
        $text = json_encode($content, JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
Analyse ce contenu et génère les métadonnées SEO. Retourne UNIQUEMENT un JSON:
{
    "metaTitle": "Titre SEO (50-60 caractères)",
    "metaDescription": "Description SEO (150-160 caractères)",
    "slug": "slug-url-optimise",
    "keywords": ["mot1", "mot2", "mot3"]
}

Contenu: $text
PROMPT;

        $response = $this->ask($prompt, self::MODELS['fast']);

        return $this->extractJson($response) ?? ['error' => 'Erreur SEO'];
    }

    /**
     * Suggest schema improvements for a collection.
     */
    public function suggestSchema(Collection $collection): array
    {
        $fields = $this->describeFields($collection);

        $prompt = <<<PROMPT
Analyse cette collection CMS et suggère des améliorations. Retourne UNIQUEMENT un JSON:
{
    "suggestions": [
        {"field": "nom_champ", "type": "text|number|boolean|richtext|media|relation|enumeration", "reason": "Pourquoi"}
    ],
    "missingIndexes": ["champ1", "champ2"],
    "validationRules": {"champ": "règle"}
}

Collection: {$collection->name} ({$collection->slug})
Champs existants:
$fields
PROMPT;

        $response = $this->ask($prompt, self::MODELS['smart']);

        return $this->extractJson($response) ?? ['suggestions' => [], 'error' => 'Impossible d\'analyser'];
    }

    /**
     * Generate alt text for an image filename.
     */
    public function generateAltText(string $fileName, ?string $caption = null): string
    {
        $context = $caption ? "Légende: $caption. " : '';
        $prompt = "{$context}Génère un texte alternatif concis et descriptif (alt-text) pour une image nommée \"$fileName\". Réponds uniquement avec le texte alternatif, pas d'explication.";

        return $this->ask($prompt, self::MODELS['fast']);
    }

    /**
     * List available AI models.
     */
    public function getAvailableModels(): array
    {
        return [
            'providers' => [
                'openai' => ['gpt-4o', 'gpt-4o-mini', 'gpt-4.1'],
                'anthropic' => ['claude-opus-4-7', 'claude-sonnet-4-6', 'claude-haiku-4-5'],
                'ollama' => ['llama3.2', 'mistral', 'phi4'],
            ],
            'defaults' => self::MODELS,
        ];
    }

    private function describeFields(Collection $collection): string
    {
        $lines = [];
        foreach ($collection->fields as $field) {
            if ($field->isDeleted()) continue;
            $required = $field->isRequired ? ' (requis)' : '';
            $lines[] = "- {$field->slug}: {$field->type}{$required} — {$field->name}";
        }

        return implode("\n", $lines);
    }

    private function extractJson(string $response): ?array
    {
        // Try to extract JSON from markdown code blocks
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $response, $matches)) {
            return json_decode($matches[1], true);
        }

        // Try parsing the whole response as JSON
        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return null;
    }
}
