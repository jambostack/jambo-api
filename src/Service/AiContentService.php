<?php

namespace App\Service;

use App\Entity\Collection;
use App\Entity\Project;
use App\Repository\AppSettingsRepository;
use Symfony\AI\Platform\Bridge\Anthropic\Factory as AnthropicFactory;
use Symfony\AI\Platform\Bridge\DeepSeek\Factory as DeepSeekFactory;
use Symfony\AI\Platform\Bridge\Ollama\Factory as OllamaFactory;
use Symfony\AI\Platform\Bridge\OpenAi\Factory as OpenAiFactory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AiContentService
{
    private array $platforms;
    private ?array $resolved = null;

    private const DEFAULT_MODELS = [
        'openai'    => 'gpt-4o',
        'anthropic' => 'claude-sonnet-4-6',
        'deepseek'  => 'deepseek-chat',
        'ollama'    => 'llama3.2',
    ];

    private const MODELS = [
        'fast' => 'gpt-4o-mini',
        'smart' => 'claude-sonnet-4-6',
        'local' => 'llama3.2',
    ];

    public function __construct(
        PlatformInterface $openaiPlatform,
        PlatformInterface $anthropicPlatform,
        PlatformInterface $ollamaPlatform,
        PlatformInterface $deepseekPlatform,
        private readonly AppSettingsRepository $appSettingsRepository,
        private readonly HttpClientInterface $httpClient,
        private readonly AuditService $audit,
        private readonly Security $security,
    ) {
        $this->platforms = [
            'openai'    => $openaiPlatform,
            'anthropic' => $anthropicPlatform,
            'ollama'    => $ollamaPlatform,
            'deepseek'  => $deepseekPlatform,
        ];
    }

    /**
     * Wrap an AI call with timing + audit logging. Captures success and failure.
     */
    private function tracked(string $action, ?Project $project, array $input, callable $fn): mixed
    {
        $start = microtime(true);
        $user = $this->security->getUser();
        $createdBy = $user?->getUserIdentifier();

        try {
            $output = $fn();
            $this->audit->logAiAction(
                action: $action,
                project: $project,
                input: $input,
                output: $output,
                createdBy: $createdBy,
                durationMs: (int) ((microtime(true) - $start) * 1000),
            );
            return $output;
        } catch (\Throwable $e) {
            $this->audit->log(
                toolName: "ai_$action",
                project: $project,
                input: $input,
                output: null,
                status: 'failed',
                errorMessage: $e->getMessage(),
                createdBy: $createdBy,
                source: 'ai',
                durationMs: (int) ((microtime(true) - $start) * 1000),
            );
            throw $e;
        }
    }

    /**
     * Construit la liste des fournisseurs activés depuis les paramètres en base.
     * Chaque entrée : ['platform' => PlatformInterface, 'model' => string].
     * Les clés saisies dans l'admin priment sur les variables d'environnement.
     *
     * @return array<string, array{platform: PlatformInterface, model: string}>
     */
    private function resolveProviders(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $config = $this->appSettingsRepository->getOrCreate()->aiProviders ?? [];
        $out = [];

        foreach (['openai', 'anthropic', 'deepseek', 'ollama'] as $name) {
            $cfg = $config[$name] ?? [];
            if (empty($cfg['enabled'])) {
                continue;
            }

            $platform = $this->buildPlatform($name, $cfg);
            if ($platform === null) {
                continue;
            }

            $out[$name] = [
                'platform' => $platform,
                'model'    => !empty($cfg['model']) ? $cfg['model'] : self::DEFAULT_MODELS[$name],
            ];
        }

        return $this->resolved = $out;
    }

    /**
     * Instancie une plateforme à partir de la clé stockée en base ; à défaut,
     * réutilise la plateforme câblée sur les variables d'environnement.
     */
    private function buildPlatform(string $name, array $cfg): ?PlatformInterface
    {
        $key = trim((string) ($cfg['key'] ?? ''));
        $url = trim((string) ($cfg['url'] ?? ''));

        try {
            return match ($name) {
                'openai'    => $key !== '' ? OpenAiFactory::createPlatform($key, $this->httpClient)    : $this->platforms['openai'],
                'anthropic' => $key !== '' ? AnthropicFactory::createPlatform($key, $this->httpClient) : $this->platforms['anthropic'],
                'deepseek'  => $key !== '' ? DeepSeekFactory::createPlatform($key, $this->httpClient)  : $this->platforms['deepseek'],
                'ollama'    => OllamaFactory::createPlatform($url !== '' ? $url : null, null, $this->httpClient),
                default     => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Interroge un fournisseur IA via l'API officielle invoke()->asText(),
     * avec repli automatique sur les autres fournisseurs activés.
     */
    public function ask(string $prompt, string $model = 'gpt-4o-mini', ?string $provider = null): string
    {
        $active = $this->resolveProviders();

        if (empty($active)) {
            throw new \RuntimeException('Aucun fournisseur IA activé. Activez et configurez un fournisseur dans Paramètres de l\'app > Fournisseurs IA.');
        }

        $messages = new MessageBag(Message::ofUser($prompt));

        // 1) Fournisseur explicitement demandé → son modèle configuré
        if ($provider !== null && isset($active[$provider])) {
            return $active[$provider]['platform']->invoke($active[$provider]['model'], $messages)->asText();
        }

        // 2) Fournisseur déduit du préfixe du modèle demandé (s'il est activé)
        $byModel = match (true) {
            str_starts_with($model, 'gpt-') || str_starts_with($model, 'o1') || str_starts_with($model, 'o3') => 'openai',
            str_starts_with($model, 'claude-')   => 'anthropic',
            str_starts_with($model, 'deepseek-') => 'deepseek',
            str_starts_with($model, 'llama') || str_starts_with($model, 'mistral') || str_starts_with($model, 'phi') => 'ollama',
            default => null,
        };

        if ($byModel !== null && isset($active[$byModel])) {
            return $active[$byModel]['platform']->invoke($model, $messages)->asText();
        }

        // 3) Repli : premier fournisseur activé, avec SON modèle configuré
        $lastError = null;
        foreach ($active as $entry) {
            try {
                return $entry['platform']->invoke($entry['model'], $messages)->asText();
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        throw new \RuntimeException('Aucun fournisseur IA disponible : ' . ($lastError?->getMessage() ?? 'erreur inconnue'));
    }

    /**
     * Generate content from a natural language brief.
     */
    public function generateContent(string $brief, Collection $collection, string $locale = 'fr'): array
    {
        return $this->tracked(
            'generate_content',
            $collection->project,
            ['brief' => $brief, 'collection' => $collection->slug, 'locale' => $locale],
            function () use ($brief, $collection, $locale) {
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
        );
    }

    /**
     * Translate content to another locale.
     */
    public function translateContent(array $content, string $targetLocale, ?Project $project = null): array
    {
        return $this->tracked(
            'translate',
            $project,
            ['target_locale' => $targetLocale, 'content_keys' => array_keys($content)],
            function () use ($content, $targetLocale) {
                $prompt = "Traduis ce contenu JSON en $targetLocale. Conserve la structure exacte, ne traduis QUE les valeurs textuelles. Retourne UNIQUEMENT le JSON traduit:\n\n" . json_encode($content, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $response = $this->ask($prompt, self::MODELS['fast']);
                $json = $this->extractJson($response);
                return $json ?? ['error' => 'Erreur de traduction'];
            }
        );
    }

    /**
     * Summarize a text.
     */
    public function summarize(string $text, int $maxWords = 80, ?Project $project = null): string
    {
        return $this->tracked(
            'summarize',
            $project,
            ['max_words' => $maxWords, 'text_length' => strlen($text)],
            function () use ($text, $maxWords) {
                $prompt = "Résume ce texte en $maxWords mots maximum, en français:\n\n$text";
                return $this->ask($prompt, self::MODELS['fast']);
            }
        );
    }

    /**
     * Generate SEO metadata for content.
     */
    public function generateSeo(array $content, ?Project $project = null): array
    {
        return $this->tracked(
            'seo',
            $project,
            ['content_keys' => array_keys($content)],
            function () use ($content) {
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
        );
    }

    /**
     * Suggest schema improvements for a collection.
     */
    public function suggestSchema(Collection $collection): array
    {
        return $this->tracked(
            'suggest_schema',
            $collection->project,
            ['collection' => $collection->slug],
            function () use ($collection) {
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
        );
    }

    /**
     * Generate alt text for an image filename.
     */
    public function generateAltText(string $fileName, ?string $caption = null, ?Project $project = null): string
    {
        return $this->tracked(
            'alt_text',
            $project,
            ['file_name' => $fileName, 'has_caption' => $caption !== null],
            function () use ($fileName, $caption) {
                $context = $caption ? "Légende: $caption. " : '';
                $prompt = "{$context}Génère un texte alternatif concis et descriptif (alt-text) pour une image nommée \"$fileName\". Réponds uniquement avec le texte alternatif, pas d'explication.";
                return $this->ask($prompt, self::MODELS['fast']);
            }
        );
    }

    /**
     * List available AI models.
     */
    public function getAvailableModels(): array
    {
        return [
            'providers' => [
                'openai'    => ['gpt-4o', 'gpt-4o-mini', 'gpt-4.1'],
                'anthropic' => ['claude-opus-4-7', 'claude-sonnet-4-6', 'claude-haiku-4-5'],
                'deepseek'  => ['deepseek-chat', 'deepseek-reasoner'],
                'ollama'    => ['llama3.2', 'mistral', 'phi4'],
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
