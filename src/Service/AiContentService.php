<?php

namespace App\Service;

use App\Entity\Collection;
use App\Entity\Project;
use App\Repository\AppSettingsRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AiContentService
{
    private ?array $resolved = null;

    private const DEFAULT_MODELS = [
        'openai'    => 'gpt-4o',
        'anthropic' => 'claude-sonnet-4-6',
        'deepseek'  => 'deepseek-chat',
        'ollama'    => 'llama3.2',
        'gemini'    => 'gemini-2.0-flash',
        'openrouter'=> 'openai/gpt-4o',
        'mistral'   => 'mistral-large-latest',
        'groq'      => 'llama-3.3-70b-versatile',
        'xai'       => 'grok-2-latest',
        'perplexity'=> 'sonar-pro',
        'qwen'      => 'qwen-max',
    ];

    private const MODELS = [
        'fast'  => 'gpt-4o-mini',
        'smart' => 'claude-sonnet-4-6',
        'local' => 'llama3.2',
    ];

    private const ENDPOINTS = [
        'openai'    => 'https://api.openai.com/v1/chat/completions',
        'anthropic' => 'https://api.anthropic.com/v1/messages',
        'deepseek'  => 'https://api.deepseek.com/chat/completions',
        'gemini'    => 'https://generativelanguage.googleapis.com/v1beta/models/',
        'openrouter'=> 'https://openrouter.ai/api/v1/chat/completions',
        'mistral'   => 'https://api.mistral.ai/v1/chat/completions',
        'groq'      => 'https://api.groq.com/openai/v1/chat/completions',
        'xai'       => 'https://api.x.ai/v1/chat/completions',
        'perplexity'=> 'https://api.perplexity.ai/chat/completions',
        'qwen'      => 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1/chat/completions',
    ];

    public function __construct(
        private readonly AppSettingsRepository $appSettingsRepository,
        private readonly HttpClientInterface $httpClient,
        private readonly AuditService $audit,
        private readonly Security $security,
    ) {}

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
     * Résout les fournisseurs activés depuis la base (Paramètres → Fournisseurs IA).
     *
     * @return array<string, array{key: string, model: string, url: string}>
     */
    private function resolveProviders(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $config = $this->appSettingsRepository->getOrCreate()->aiProviders ?? [];
        $out = [];

        foreach (['openai', 'anthropic', 'deepseek', 'ollama', 'gemini', 'openrouter', 'mistral', 'groq', 'xai', 'perplexity', 'qwen'] as $name) {
            $cfg = $config[$name] ?? [];
            if (empty($cfg['enabled'])) {
                continue;
            }
            $key = trim((string) ($cfg['key'] ?? ''));
            $url = trim((string) ($cfg['url'] ?? ''));

            if ($name !== 'ollama' && $key === '') {
                continue;
            }

            $out[$name] = [
                'key'   => $key,
                'model' => !empty($cfg['model']) ? $cfg['model'] : self::DEFAULT_MODELS[$name],
                'url'   => $url,
            ];
        }

        return $this->resolved = $out;
    }

    /**
     * Appel direct à l'API du fournisseur via HTTP.
     */
    private function callProvider(string $name, string $key, string $model, string $prompt, string $ollamaUrl = ''): string
    {
        $messages = [['role' => 'user', 'content' => $prompt]];

        if ($name === 'anthropic') {
            $response = $this->httpClient->request('POST', self::ENDPOINTS['anthropic'], [
                'headers' => [
                    'x-api-key'         => $key,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type'      => 'application/json',
                ],
                'json'    => ['model' => $model, 'max_tokens' => 2048, 'messages' => $messages],
                'timeout' => 60,
            ]);
            return $response->toArray()['content'][0]['text'] ?? '';
        }

        if ($name === 'gemini') {
            // Format natif Google Gemini — clé API dans le header, pas en URL
            $endpoint = self::ENDPOINTS['gemini'] . $model . ':generateContent';
            $payload = [
                'contents' => [
                    ['parts' => [['text' => $prompt]]],
                ],
            ];
            $response = $this->httpClient->request('POST', $endpoint, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $key,
                ],
                'json'    => $payload,
                'timeout' => 60,
            ]);
            return $response->toArray()['candidates'][0]['content']['parts'][0]['text'] ?? '';
        }

        if ($name === 'ollama') {
            $endpoint = rtrim($ollamaUrl ?: 'http://localhost:11434', '/') . '/v1/chat/completions';
            $response = $this->httpClient->request('POST', $endpoint, [
                'headers' => ['Content-Type' => 'application/json'],
                'json'    => ['model' => $model, 'messages' => $messages],
                'timeout' => 120,
            ]);
            return $response->toArray()['choices'][0]['message']['content'] ?? '';
        }

        // OpenAI / DeepSeek (format compatible OpenAI)
        $response = $this->httpClient->request('POST', self::ENDPOINTS[$name], [
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ],
            'json'    => ['model' => $model, 'messages' => $messages, 'temperature' => 0.7],
            'timeout' => 60,
        ]);
        return $response->toArray()['choices'][0]['message']['content'] ?? '';
    }

    public function ask(string $prompt, string $model = 'gpt-4o-mini', ?string $provider = null): string
    {
        $active = $this->resolveProviders();

        if (empty($active)) {
            throw new \RuntimeException('Aucun fournisseur IA activé. Configurez-en un dans Paramètres → Fournisseurs IA.');
        }

        // Fournisseur explicitement demandé
        if ($provider !== null && isset($active[$provider])) {
            $cfg = $active[$provider];
            return $this->callProvider($provider, $cfg['key'], $cfg['model'], $prompt, $cfg['url']);
        }

        // Déduction depuis le préfixe du modèle
        $byModel = match (true) {
            str_starts_with($model, 'gpt-') || str_starts_with($model, 'o1') || str_starts_with($model, 'o3') => 'openai',
            str_starts_with($model, 'claude-')   => 'anthropic',
            str_starts_with($model, 'deepseek-') => 'deepseek',
            str_starts_with($model, 'gemini-')   => 'gemini',
            str_starts_with($model, 'openai/') || str_starts_with($model, 'google/') || str_starts_with($model, 'meta-llama/') || str_starts_with($model, 'anthropic/') => 'openrouter',
            str_starts_with($model, 'mistral-')  => 'mistral',
            str_starts_with($model, 'grok-')     => 'xai',
            str_starts_with($model, 'sonar-') || str_starts_with($model, 'llama-3.1-sonar') => 'perplexity',
            str_starts_with($model, 'qwen-')     => 'qwen',
            str_starts_with($model, 'llama') || str_starts_with($model, 'phi') || str_starts_with($model, 'codellama') => 'ollama',
            default => null,
        };

        if ($byModel !== null && isset($active[$byModel])) {
            $cfg = $active[$byModel];
            return $this->callProvider($byModel, $cfg['key'], $model, $prompt, $cfg['url']);
        }

        // Repli : premier fournisseur activé
        $lastError = null;
        foreach ($active as $name => $cfg) {
            try {
                return $this->callProvider($name, $cfg['key'], $cfg['model'], $prompt, $cfg['url']);
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        throw new \RuntimeException('Aucun fournisseur IA disponible : ' . ($lastError?->getMessage() ?? 'erreur inconnue'));
    }

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
PROMPT;
                $response = $this->ask($prompt, self::MODELS['smart']);
                $json = $this->extractJson($response);
                return $json ?? ['error' => 'Impossible de parser la réponse IA', 'raw' => $response];
            }
        );
    }

    public function translateContent(array $content, string $targetLocale, ?Project $project = null): array
    {
        return $this->tracked(
            'translate',
            $project,
            ['target_locale' => $targetLocale, 'content_keys' => array_keys($content)],
            function () use ($content, $targetLocale) {
                $prompt = "Traduis ce contenu JSON en $targetLocale. Conserve la structure exacte, ne traduis QUE les valeurs textuelles. Retourne UNIQUEMENT le JSON traduit:\n\n" . json_encode($content, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $response = $this->ask($prompt, self::MODELS['fast']);
                return $this->extractJson($response) ?? ['error' => 'Erreur de traduction'];
            }
        );
    }

    public function summarize(string $text, int $maxWords = 80, ?Project $project = null): string
    {
        return $this->tracked(
            'summarize',
            $project,
            ['max_words' => $maxWords, 'text_length' => strlen($text)],
            function () use ($text, $maxWords) {
                return $this->ask("Résume ce texte en $maxWords mots maximum, en français:\n\n$text", self::MODELS['fast']);
            }
        );
    }

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
                return $this->extractJson($this->ask($prompt, self::MODELS['fast'])) ?? ['error' => 'Erreur SEO'];
            }
        );
    }

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
                return $this->extractJson($this->ask($prompt, self::MODELS['smart'])) ?? ['suggestions' => [], 'error' => 'Impossible d\'analyser'];
            }
        );
    }

    public function generateAltText(string $fileName, ?string $caption = null, ?Project $project = null): string
    {
        return $this->tracked(
            'alt_text',
            $project,
            ['file_name' => $fileName, 'has_caption' => $caption !== null],
            function () use ($fileName, $caption) {
                $context = $caption ? "Légende: $caption. " : '';
                return $this->ask("{$context}Génère un texte alternatif concis pour une image nommée \"$fileName\". Réponds uniquement avec le texte alternatif.", self::MODELS['fast']);
            }
        );
    }

    public function getAvailableModels(): array
    {
        return [
            'providers' => [
                'openai'    => ['gpt-4o', 'gpt-4o-mini', 'gpt-4.1', 'o1', 'o3-mini'],
                'anthropic' => ['claude-opus-4-8', 'claude-sonnet-4-6', 'claude-haiku-4-5-20251001'],
                'deepseek'  => ['deepseek-chat', 'deepseek-reasoner'],
                'ollama'    => ['llama3.3', 'mistral', 'codellama'],
                'gemini'    => ['gemini-2.0-flash', 'gemini-2.0-pro', 'gemini-1.5-pro'],
                'openrouter'=> ['openai/gpt-4o', 'anthropic/claude-sonnet-4-6', 'google/gemini-2.0-flash', 'meta-llama/llama-4-maverick'],
                'mistral'   => ['mistral-large-latest', 'mistral-small-latest', 'codestral-latest'],
                'groq'      => ['llama-3.3-70b-versatile', 'mixtral-8x7b-32768', 'gemma2-9b-it'],
                'xai'       => ['grok-2-latest', 'grok-2-vision-latest'],
                'perplexity'=> ['sonar-pro', 'sonar-reasoning-pro'],
                'qwen'      => ['qwen-max', 'qwen-plus', 'qwen-turbo'],
            ],
            'defaults' => self::MODELS,
        ];
    }

    /**
     * Retourne le profil de capacités basé sur les providers activés.
     */
    public function getCapabilities(): array
    {
        $providers = $this->resolveProviders();
        $hasText = false;
        $hasImages = false;
        $textProvider = null;
        $imageProvider = null;
        $textModel = '';
        $limits = [];

        foreach ($providers as $name => $cfg) {
            $hasText = true;
            if ($textProvider === null) {
                $textProvider = $name;
                $textModel = $cfg['model'];
            }
            // Providers avec génération d'images
            if (in_array($name, ['openai', 'gemini'], true)) {
                $hasImages = true;
                if ($imageProvider === null) $imageProvider = $name;
            }
        }

        // Ollama a des capacités limitées
        if ($textProvider === 'ollama') {
            $limits[] = 'qualite_limitee';
        }

        return [
            'text'    => $hasText,
            'images'  => $hasImages,
            'voice'   => false,
            'provider'    => $textProvider,
            'model'       => $textModel,
            'image_provider' => $imageProvider,
            'limits'    => $limits,
            'available' => $hasText,
        ];
    }

    /**
     * Génère une image via DALL-E ou Gemini Imagen.
     * Retourne l'URL de l'image ou null si pas de provider image.
     */
    public function generateImage(string $prompt, string $size = '1024x1024'): ?string
    {
        $caps = $this->getCapabilities();
        if (!$caps['images'] || $caps['image_provider'] === null) {
            return null;
        }

        $providers = $this->resolveProviders();
        $imageProvider = $caps['image_provider'];
        $cfg = $providers[$imageProvider];

        try {
            if ($imageProvider === 'openai') {
                $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/images/generations', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $cfg['key'],
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => [
                        'model'  => 'dall-e-3',
                        'prompt' => $prompt,
                        'n'      => 1,
                        'size'   => $size,
                    ],
                    'timeout' => 60,
                ]);
                $data = $response->toArray();
                return $data['data'][0]['url'] ?? null;
            }

            if ($imageProvider === 'gemini') {
                // Gemini Imagen via generateContent avec responseModalities
                $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-exp-image-generation:generateContent';
                $response = $this->httpClient->request('POST', $endpoint, [
                    'headers' => [
                        'Content-Type'  => 'application/json',
                        'x-goog-api-key' => $cfg['key'],
                    ],
                    'json' => [
                        'contents' => [['parts' => [['text' => $prompt]]]],
                        'generationConfig' => ['responseModalities' => ['Text', 'Image']],
                    ],
                    'timeout' => 60,
                ]);
                $data = $response->toArray();
                // Extraction de l'image inline (base64)
                foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
                    if (isset($part['inlineData'])) {
                        return 'data:' . ($part['inlineData']['mimeType'] ?? 'image/png')
                            . ';base64,' . $part['inlineData']['data'];
                    }
                }
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    /**
     * Génère un placeholder SVG professionnel quand pas de provider image.
     */
    public function generatePlaceholder(string $text, string $width = '800', string $height = '600'): string
    {
        $bg = '0e1611';
        $accent = '2fcf8f';
        $textColor = '99a89f';
        $fontSize = '28';
        $encoded = htmlspecialchars($text, ENT_XML1, 'UTF-8');
        return 'data:image/svg+xml,' . rawurlencode(<<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="$width" height="$height" viewBox="0 0 $width $height">
  <rect width="$width" height="$height" fill="#$bg"/>
  <rect x="1" y="1" width="$width-2" height="$height-2" fill="none" stroke="#$accent" stroke-opacity="0.15" rx="12"/>
  <circle cx="$width/2" cy="$height/2-30" r="50" fill="none" stroke="#$accent" stroke-opacity="0.1" stroke-width="2"/>
  <text x="$width/2" y="$height/2+30" text-anchor="middle" fill="#$textColor" font-family="system-ui,sans-serif" font-size="$fontSize" font-weight="600">$encoded</text>
</svg>
SVG);
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
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $response, $matches)) {
            return json_decode($matches[1], true);
        }
        $decoded = json_decode($response, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
}
