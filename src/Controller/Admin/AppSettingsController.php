<?php

namespace App\Controller\Admin;

use App\Repository\AppSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Vich\UploaderBundle\Handler\UploadHandler;

#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/admin/api/app-settings', name: 'admin_app_settings_')]
class AppSettingsController extends AbstractController
{
    public function __construct(
        private AppSettingsRepository $repository,
        private EntityManagerInterface $em,
        private UploadHandler $uploadHandler,
        private CacheInterface $cache,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // Chargement dynamique des modèles d'un fournisseur IA
    // ─────────────────────────────────────────────────────────────────────────

    private const PROVIDER_ENDPOINTS = [
        'openai'     => ['url' => 'https://api.openai.com/v1/models',                          'auth' => 'bearer'],
        'anthropic'  => ['url' => 'https://api.anthropic.com/v1/models',                       'auth' => 'anthropic'],
        'deepseek'   => ['url' => 'https://api.deepseek.com/v1/models',                        'auth' => 'bearer'],
        'gemini'     => ['url' => 'https://generativelanguage.googleapis.com/v1beta/models',    'auth' => 'query_key'],
        'mistral'    => ['url' => 'https://api.mistral.ai/v1/models',                          'auth' => 'bearer'],
        'groq'       => ['url' => 'https://api.groq.com/openai/v1/models',                     'auth' => 'bearer'],
        'openrouter' => ['url' => 'https://openrouter.ai/api/v1/models',                       'auth' => 'bearer'],
        'xai'        => ['url' => 'https://api.x.ai/v1/models',                                'auth' => 'bearer'],
        'qwen'       => ['url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1/models',  'auth' => 'bearer'],
        'ollama'     => ['url' => null /* dynamic */,                                           'auth' => 'none'],
        // Perplexity n'expose pas d'endpoint public de listage → liste statique
        'perplexity' => ['url' => null,                                                         'auth' => 'static'],
    ];

    private const PERPLEXITY_MODELS = [
        'sonar-pro', 'sonar', 'sonar-reasoning-pro', 'sonar-reasoning',
        'sonar-deep-research', 'r1-1776',
    ];

    #[Route('/models/{provider}', name: 'models', methods: ['GET'])]
    public function models(string $provider): JsonResponse
    {
        $allowed = array_keys(self::PROVIDER_ENDPOINTS);
        if (!\in_array($provider, $allowed, true)) {
            return $this->json(['error' => 'Unknown provider'], 400);
        }

        $settings = $this->repository->getOrCreate();
        $cfg      = $settings->aiProviders[$provider] ?? [];
        $key      = $cfg['key'] ?? null;
        $baseUrl  = $cfg['url'] ?? null;
        $meta     = self::PROVIDER_ENDPOINTS[$provider];

        // Perplexity — liste statique
        if ($meta['auth'] === 'static') {
            return $this->json(['models' => self::PERPLEXITY_MODELS]);
        }

        // Ollama — endpoint local
        if ($provider === 'ollama') {
            if (empty($baseUrl)) {
                return $this->json(['error' => 'Ollama URL not configured'], 422);
            }
            $url = rtrim($baseUrl, '/') . '/api/tags';
            $raw = $this->fetchUrl($url, [], 6);
            if ($raw === null) {
                return $this->json(['error' => 'Could not reach Ollama at ' . $baseUrl], 502);
            }
            $data   = json_decode($raw, true);
            $models = array_column($data['models'] ?? [], 'name');
            return $this->json(['models' => $models]);
        }

        // Providers à clé API
        if (empty($key)) {
            return $this->json(['error' => 'API key not configured for ' . $provider], 422);
        }

        $url     = $meta['url'];
        $headers = match ($meta['auth']) {
            'bearer'    => ['Authorization: Bearer ' . $key],
            'anthropic' => ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
            'query_key' => [], // key passed as query param
            default     => [],
        };

        if ($meta['auth'] === 'query_key') {
            $url .= '?key=' . urlencode($key);
        }

        $raw = $this->fetchUrl($url, $headers, 10);
        if ($raw === null) {
            return $this->json(['error' => 'Provider API unreachable'], 502);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Unexpected response from provider'], 502);
        }

        $models = match ($provider) {
            'gemini' => array_map(
                fn($m) => str_replace('models/', '', $m['name'] ?? ''),
                $data['models'] ?? []
            ),
            default => array_column($data['data'] ?? [], 'id'),
        };

        // Filtrer les modèles pertinents (exclure embeddings, audio, etc.)
        $models = array_values(array_filter($models, fn($id) => match ($provider) {
            'openai'     => str_starts_with($id, 'gpt-') || str_starts_with($id, 'o1') || str_starts_with($id, 'o3') || str_starts_with($id, 'o4'),
            'anthropic'  => str_contains($id, 'claude'),
            'gemini'     => str_contains($id, 'gemini') && !str_contains($id, 'embedding') && !str_contains($id, 'aqa'),
            default      => !empty($id),
        }));

        sort($models);

        return $this->json(['models' => $models]);
    }

    private function fetchUrl(string $url, array $headers, int $timeout): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT        => $timeout,
            \CURLOPT_HTTPHEADER     => $headers,
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($body !== false && $code >= 200 && $code < 300) ? $body : null;
    }

    // ─────────────────────────────────────────────────────────────────────────

    #[Route('', name: 'get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        $settings = $this->repository->getOrCreate();

        return $this->json($this->serialize($settings));
    }

    #[Route('', name: 'update', methods: ['POST'])]
    public function update(Request $request): JsonResponse
    {
        $settings = $this->repository->getOrCreate();
        $changed  = false;

        // Handle JSON fields
        if ($request->getContentTypeFormat() === 'json') {
            $data = $request->toArray();
            if (isset($data['appName']) && trim($data['appName']) !== '') {
                $name = trim($data['appName']);
                if (mb_strlen($name) > 100) {
                    return $this->json(['errors' => ['appName' => 'App name must not exceed 100 characters']], 422);
                }
                $settings->appName = $name;
                $changed = true;
            }
        }

        // Multipart file uploads — each field maps to a Vich property
        $fileFields = [
            'logo'       => 'logoFile',
            'logo_dark'  => 'logoDarkFile',
            'logo_light' => 'logoLightFile',
            'icon_dark'  => 'iconDarkFile',
            'icon_light' => 'iconLightFile',
            'favicon'    => 'faviconFile',
        ];

        foreach ($fileFields as $formField => $property) {
            $file = $request->files->get($formField);
            if ($file === null) {
                continue;
            }

            if ($_ENV['DEMO_MODE'] ?? false) {
                return $this->json(['errors' => [$formField => 'Logo/icon changes are disabled in demo mode.']], 403);
            }

            $allowIco = ($formField === 'favicon');
            $error = $this->validateImageFile($file, $allowIco);
            if ($error !== null) {
                return $this->json(['errors' => [$formField => $error]], 422);
            }

            // Assainir les SVG uploadés (XSS via <script> dans les logos)
            if ($file->getMimeType() === 'image/svg+xml') {
                $this->sanitizeSvg($file);
            }

            $settings->$property = $file;
            $this->uploadHandler->upload($settings, $property);
            $changed = true;
        }

        // AI providers (JSON body with key "aiProviders")
        if ($request->getContentTypeFormat() === 'json') {
            $data = $request->toArray();
            if (array_key_exists('aiProviders', $data) && is_array($data['aiProviders'])) {
                $current  = $settings->aiProviders ?? [];
                $incoming = $data['aiProviders'];

                foreach (['openai', 'anthropic', 'deepseek', 'ollama', 'gemini', 'openrouter', 'mistral', 'groq', 'xai', 'perplexity', 'qwen'] as $provider) {
                    if (!array_key_exists($provider, $incoming)) {
                        continue;
                    }
                    $p = $incoming[$provider];

                    // enabled state — always saved when present
                    if (array_key_exists('enabled', $p)) {
                        $current[$provider]['enabled'] = (bool) $p['enabled'];
                    }
                    // API key — empty string clears it
                    if (array_key_exists('key', $p)) {
                        $val = trim((string) $p['key']);
                        $current[$provider]['key'] = $val !== '' ? $val : null;
                    }
                    if (array_key_exists('model', $p)) {
                        $current[$provider]['model'] = trim((string) $p['model']) ?: null;
                    }
                    if (array_key_exists('url', $p)) {
                        $current[$provider]['url'] = trim((string) $p['url']) ?: null;
                    }
                }

                $settings->aiProviders = $current;
                $changed = true;
            }
        }

        if ($changed) {
            $settings->updatedAt = new \DateTimeImmutable();
            $this->em->flush();
            $this->cache->delete('app_settings_data');
        }

        return $this->json($this->serialize($settings));
    }

    private function validateImageFile(UploadedFile $file, bool $allowIco = false): ?string
    {
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
        if ($allowIco) {
            $allowedMimes[] = 'image/x-icon';
            $allowedMimes[] = 'image/vnd.microsoft.icon';
        }

        if (!in_array($file->getMimeType(), $allowedMimes, true)) {
            return 'Invalid file type. Allowed: JPEG, PNG, GIF, WebP, SVG' . ($allowIco ? ', ICO' : '');
        }

        if ($file->getSize() > 2 * 1024 * 1024) {
            return 'File exceeds the maximum size of 2 MB';
        }

        return null;
    }

    /** Supprime les scripts et gestionnaires d'événements des fichiers SVG uploadés. */
    private function sanitizeSvg(\Symfony\Component\HttpFoundation\File\UploadedFile $file): void
    {
        $content = file_get_contents($file->getPathname());
        if ($content === false) return;

        // Supprimer les balises <script> et leur contenu
        $content = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $content);
        // Supprimer les gestionnaires d'événements inline (onload, onclick, etc.)
        $content = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $content);
        // Supprimer les <foreignObject> (peuvent contenir du HTML arbitraire)
        $content = preg_replace('/<foreignObject[^>]*>.*?<\/foreignObject>/is', '', $content);

        file_put_contents($file->getPathname(), $content);
    }

    private function serialize(\App\Entity\AppSettings $s): array
    {
        $providers = $s->aiProviders ?? [];

        return [
            'appName'      => $s->appName,
            'logoUrl'      => $s->getLogoUrl(),
            'logoDarkUrl'  => $s->getLogoDarkUrl(),
            'logoLightUrl' => $s->getLogoLightUrl(),
            'iconDarkUrl'  => $s->getIconDarkUrl(),
            'iconLightUrl' => $s->getIconLightUrl(),
            'faviconUrl'   => $s->getFaviconUrl(),
            'aiProviders'  => [
                'openai'    => [
                    'enabled'    => (bool) ($providers['openai']['enabled']    ?? false),
                    'configured' => !empty($providers['openai']['key']),
                    'model'      => $providers['openai']['model']    ?? 'gpt-4o',
                ],
                'anthropic' => [
                    'enabled'    => (bool) ($providers['anthropic']['enabled'] ?? false),
                    'configured' => !empty($providers['anthropic']['key']),
                    'model'      => $providers['anthropic']['model'] ?? 'claude-sonnet-4-6',
                ],
                'deepseek'  => [
                    'enabled'    => (bool) ($providers['deepseek']['enabled']  ?? false),
                    'configured' => !empty($providers['deepseek']['key']),
                    'model'      => $providers['deepseek']['model']  ?? 'deepseek-chat',
                ],
                'ollama'    => [
                    'enabled'    => (bool) ($providers['ollama']['enabled']    ?? false),
                    'configured' => !empty($providers['ollama']['url']),
                    'url'        => $providers['ollama']['url']       ?? '',
                    'model'      => $providers['ollama']['model']     ?? 'llama3.2',
                ],
                'gemini'    => [
                    'enabled'    => (bool) ($providers['gemini']['enabled']    ?? false),
                    'configured' => !empty($providers['gemini']['key']),
                    'model'      => $providers['gemini']['model']     ?? 'gemini-2.0-flash',
                ],
                'openrouter'=> [
                    'enabled'    => (bool) ($providers['openrouter']['enabled'] ?? false),
                    'configured' => !empty($providers['openrouter']['key']),
                    'model'      => $providers['openrouter']['model'] ?? 'openai/gpt-4o',
                ],
                'mistral'   => [
                    'enabled'    => (bool) ($providers['mistral']['enabled']   ?? false),
                    'configured' => !empty($providers['mistral']['key']),
                    'model'      => $providers['mistral']['model']    ?? 'mistral-large-latest',
                ],
                'groq'      => [
                    'enabled'    => (bool) ($providers['groq']['enabled']      ?? false),
                    'configured' => !empty($providers['groq']['key']),
                    'model'      => $providers['groq']['model']       ?? 'llama-3.3-70b-versatile',
                ],
                'xai'       => [
                    'enabled'    => (bool) ($providers['xai']['enabled']       ?? false),
                    'configured' => !empty($providers['xai']['key']),
                    'model'      => $providers['xai']['model']        ?? 'grok-2-latest',
                ],
                'perplexity'=> [
                    'enabled'    => (bool) ($providers['perplexity']['enabled']?? false),
                    'configured' => !empty($providers['perplexity']['key']),
                    'model'      => $providers['perplexity']['model'] ?? 'sonar-pro',
                ],
                'qwen'      => [
                    'enabled'    => (bool) ($providers['qwen']['enabled']      ?? false),
                    'configured' => !empty($providers['qwen']['key']),
                    'model'      => $providers['qwen']['model']       ?? 'qwen-max',
                ],
            ],
        ];
    }

}
