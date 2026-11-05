<?php

namespace App\Controller\Admin;

use App\Repository\AppSettingsRepository;
use App\Service\Security\SsrfProtectionService;
use App\Service\WebhookSecretService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Vich\UploaderBundle\Handler\UploadHandler;

#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/admin/api/app-settings', name: 'admin_app_settings_')]
class AppSettingsController extends AbstractController
{
    private const MAX_JSON_PAYLOAD_BYTES = 524288; // 512 KB
    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-store',
    ];

    private readonly SsrfProtectionService $ssrfProtection;

    public function __construct(
        private AppSettingsRepository $repository,
        private EntityManagerInterface $em,
        private UploadHandler $uploadHandler,
        private CacheInterface $cache,
        private WebhookSecretService $secretService,
        private readonly ?TranslatorInterface $translator = null,
        ?SsrfProtectionService $ssrfProtection = null,
    ) {
        $this->ssrfProtection = $ssrfProtection ?? new SsrfProtectionService();
    }

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters);
        }
        return $default ?? $id;
    }

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

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
            return $this->secureJson([
                'error' => $this->trans('app_settings.admin.unknown_provider', [], 'Unknown provider'),
                'code' => 'UNKNOWN_PROVIDER',
            ], 400);
        }

        $settings = $this->repository->getOrCreate();
        $cfg      = $settings->aiProviders[$provider] ?? [];
        $key      = $cfg['key'] ?? null;
        $baseUrl  = $cfg['url'] ?? null;
        $meta     = self::PROVIDER_ENDPOINTS[$provider];

        // Perplexity — liste statique
        if ($meta['auth'] === 'static') {
            return $this->secureJson(['models' => self::PERPLEXITY_MODELS]);
        }

        // Ollama — endpoint local
        if ($provider === 'ollama') {
            if (empty($baseUrl)) {
                return $this->secureJson([
                    'error' => $this->trans('app_settings.admin.ollama_url_not_configured', [], 'Ollama URL not configured'),
                    'code' => 'OLLAMA_URL_NOT_CONFIGURED',
                ], 422);
            }
            if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || strlen($baseUrl) > 2048) {
                return $this->secureJson([
                    'error' => $this->trans('app_settings.admin.invalid_ollama_url', [], 'Invalid Ollama URL'),
                    'code' => 'INVALID_OLLAMA_URL',
                ], 422);
            }
            if (preg_match('/[\r\n\x00-\x1F\x7F]/', $baseUrl)) {
                return $this->secureJson([
                    'error' => $this->trans('app_settings.admin.invalid_ollama_url', [], 'Invalid Ollama URL'),
                    'code' => 'INVALID_OLLAMA_URL',
                ], 422);
            }
            $parsed = parse_url($baseUrl);
            $scheme = strtolower($parsed['scheme'] ?? '');
            if (!in_array($scheme, ['http', 'https'], true)) {
                return $this->secureJson([
                    'error' => $this->trans('app_settings.admin.ollama_scheme_forbidden', [], 'Only http and https schemes are allowed for Ollama'),
                    'code' => 'OLLAMA_SCHEME_FORBIDDEN',
                ], 422);
            }
            if (isset($parsed['user']) || isset($parsed['pass'])) {
                return $this->secureJson([
                    'error' => $this->trans('app_settings.admin.invalid_ollama_url', [], 'Invalid Ollama URL'),
                    'code' => 'USERINFO_FORBIDDEN',
                ], 422);
            }
            $host = strtolower($parsed['host'] ?? '');
            if ($this->isCloudMetadataOrForbiddenHost($host)) {
                return $this->secureJson([
                    'error' => $this->trans('app_settings.admin.ollama_metadata_forbidden', [], 'Ollama URL cannot target cloud metadata or reserved infrastructure'),
                    'code' => 'OLLAMA_METADATA_FORBIDDEN',
                ], 403);
            }

            $url = rtrim($baseUrl, '/') . '/api/tags';
            $raw = $this->fetchUrl($url, [], 6);
            if ($raw === null) {
                return $this->secureJson([
                    'error' => $this->trans('app_settings.admin.could_not_reach_ollama', ['%url%' => $baseUrl], 'Could not reach Ollama at ' . $baseUrl),
                    'code' => 'OLLAMA_UNREACHABLE',
                ], 502);
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                return $this->secureJson([
                    'error' => $this->trans('app_settings.admin.unexpected_ollama_response', [], 'Unexpected response from Ollama'),
                    'code' => 'OLLAMA_UNEXPECTED_RESPONSE',
                ], 502);
            }
            $models = array_column($data['models'] ?? [], 'name');
            return $this->secureJson(['models' => $models]);
        }

        // Providers à clé API
        if (empty($key)) {
            return $this->secureJson([
                'error' => $this->trans('app_settings.admin.key_not_configured', ['%provider%' => $provider], 'API key not configured for ' . $provider),
                'code' => 'API_KEY_NOT_CONFIGURED',
            ], 422);
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
            return $this->secureJson([
                'error' => $this->trans('app_settings.admin.provider_api_unreachable', [], 'Provider API unreachable'),
                'code' => 'PROVIDER_API_UNREACHABLE',
            ], 502);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $this->secureJson([
                'error' => $this->trans('app_settings.admin.unexpected_provider_response', [], 'Unexpected response from provider'),
                'code' => 'PROVIDER_UNEXPECTED_RESPONSE',
            ], 502);
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

        return $this->secureJson(['models' => $models]);
    }

    public function isCloudMetadataOrForbiddenHost(string $host): bool
    {
        if (preg_match('/[\r\n\x00-\x1F\x7F]/', $host)) {
            return true;
        }

        $cleanHost = strtolower(trim($host, " \t[]"));
        if ($cleanHost === '') {
            return true;
        }

        $blockedHosts = [
            '169.254.169.254',
            '169.254.169.250',
            '169.254.0.2',
            '100.100.100.200',
            'fd00:ec2::254',
            'metadata.google.internal',
            'metadata.internal',
            'metadata',
            'instance-data',
        ];

        if (in_array($cleanHost, $blockedHosts, true)) {
            return true;
        }

        // IPv6 Link-Local / AWS IMDS
        if (str_starts_with($cleanHost, 'fd00:ec2::') || str_starts_with($cleanHost, 'fe80:')) {
            return true;
        }

        // Dword integer notation (e.g. 2852039166 = 169.254.169.254)
        if (ctype_digit($cleanHost)) {
            $ip = long2ip((int) $cleanHost);
            if ($ip && (str_starts_with($ip, '169.254.') || str_starts_with($ip, '100.100.'))) {
                return true;
            }
        }

        // Hexadecimal notation (e.g. 0xa9fea9fe)
        if (preg_match('/^0x[0-9a-f]+$/i', $cleanHost)) {
            $dec = hexdec(substr($cleanHost, 2));
            $ip = long2ip((int) $dec);
            if ($ip && (str_starts_with($ip, '169.254.') || str_starts_with($ip, '100.100.'))) {
                return true;
            }
        }

        // Octal notation (e.g. 0251.0372.0251.0372)
        if (preg_match('/(^|\.)0[0-7]+(\.|$)/', $cleanHost)) {
            $parts = explode('.', $cleanHost);
            if (count($parts) === 4) {
                $decParts = array_map(fn($p) => str_starts_with($p, '0') && strlen($p) > 1 ? octdec($p) : (int)$p, $parts);
                $ip = implode('.', $decParts);
                if (str_starts_with($ip, '169.254.') || str_starts_with($ip, '100.100.')) {
                    return true;
                }
            }
        }

        // Direct IP or Hostname resolution
        $ip = filter_var($cleanHost, FILTER_VALIDATE_IP) ? $cleanHost : @gethostbyname($cleanHost);
        if ($ip && (str_starts_with($ip, '169.254.') || str_starts_with($ip, '100.100.'))) {
            return true;
        }

        return false;
    }

    private function fetchUrl(string $url, array $headers, int $timeout): ?string
    {
        if (!filter_var($url, FILTER_VALIDATE_URL) || strlen($url) > 2048) {
            return null;
        }

        $maxBytes = 2 * 1024 * 1024; // 2 MB max
        $body = '';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            \CURLOPT_TIMEOUT        => $timeout,
            \CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            \CURLOPT_HTTPHEADER     => $headers,
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_FOLLOWLOCATION => false, // Anti-SSRF: interdire les redirections
            \CURLOPT_PROTOCOLS      => \CURLPROTO_HTTP | \CURLPROTO_HTTPS,
            \CURLOPT_REDIR_PROTOCOLS => 0,
            \CURLOPT_BUFFERSIZE     => 16384,
            \CURLOPT_WRITEFUNCTION  => function ($ch, $data) use (&$body, $maxBytes) {
                $body .= $data;
                if (strlen($body) > $maxBytes) {
                    return 0; // Abort download
                }
                return strlen($data);
            },
        ]);
        $success = curl_exec($ch);
        $code = curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($success !== false && $code >= 200 && $code < 300) ? $body : null;
    }

    // ─────────────────────────────────────────────────────────────────────────

    #[Route('', name: 'get', methods: ['GET'])]
    public function get(): JsonResponse
    {
        $settings = $this->repository->getOrCreate();

        return $this->secureJson($this->serialize($settings));
    }

    #[Route('', name: 'update', methods: ['POST'])]
    public function update(Request $request): JsonResponse
    {
        $settings = $this->repository->getOrCreate();
        $changed  = false;

        // Handle JSON fields
        $jsonData = null;
        if ($request->getContentTypeFormat() === 'json') {
            if (strlen($request->getContent()) > self::MAX_JSON_PAYLOAD_BYTES) {
                return $this->secureJson([
                    'error' => $this->trans('app_settings.admin.payload_too_large', [], 'Payload exceeds maximum allowed size (512 KB).'),
                    'code' => 'PAYLOAD_TOO_LARGE',
                ], 413);
            }

            try {
                $jsonData = $request->toArray();
            } catch (\Throwable) {
                return $this->secureJson([
                    'error' => $this->trans('app_settings.admin.invalid_json_body', [], 'Invalid JSON body'),
                    'code' => 'INVALID_JSON',
                ], 400);
            }
            if (isset($jsonData['appName']) && trim((string) $jsonData['appName']) !== '') {
                $name = trim((string) $jsonData['appName']);
                if (mb_strlen($name) > 100) {
                    return $this->secureJson([
                        'errors' => ['appName' => $this->trans('app_settings.admin.app_name_too_long', [], 'App name must not exceed 100 characters')],
                        'code' => 'APP_NAME_TOO_LONG',
                    ], 422);
                }
                if (preg_match('/[\r\n\x00-\x1F\x7F]/', $name)) {
                    return $this->secureJson([
                        'errors' => ['appName' => $this->trans('app_settings.admin.app_name_control_chars', [], 'App name contains invalid control characters')],
                        'code' => 'APP_NAME_INVALID',
                    ], 422);
                }
                if (str_contains($name, '<') || str_contains($name, '>') || preg_match('/<\s*script/i', $name)) {
                    return $this->secureJson([
                        'errors' => ['appName' => $this->trans('app_settings.admin.app_name_xss_detected', [], 'App name contains disallowed HTML tags or script elements')],
                        'code' => 'APP_NAME_XSS_DETECTED',
                    ], 422);
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
                return $this->secureJson([
                    'errors' => [$formField => $this->trans('app_settings.admin.demo_mode_disabled', [], 'Logo/icon changes are disabled in demo mode.')],
                    'code' => 'DEMO_MODE_DISABLED',
                ], 403);
            }

            $allowIco = ($formField === 'favicon');
            $error = $this->validateImageFile($file, $allowIco);
            if ($error !== null) {
                return $this->secureJson(['errors' => [$formField => $error]], 422);
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
        if ($jsonData !== null) {
            $data = $jsonData;
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
                        $rawUrl = trim((string) $p['url']);
                        if ($rawUrl !== '') {
                            if (strlen($rawUrl) > 2048 || !filter_var($rawUrl, FILTER_VALIDATE_URL) || preg_match('/[\r\n\x00-\x1F\x7F]/', $rawUrl)) {
                                return $this->secureJson([
                                    'errors' => ['aiProviders' => $this->trans('app_settings.admin.invalid_provider_url', [], 'Invalid or forbidden provider URL')],
                                    'code' => 'INVALID_PROVIDER_URL',
                                ], 422);
                            }
                            $parsedUrl = parse_url($rawUrl);
                            $scheme = strtolower($parsedUrl['scheme'] ?? '');
                            if (!in_array($scheme, ['http', 'https'], true)) {
                                return $this->secureJson([
                                    'errors' => ['aiProviders' => $this->trans('app_settings.admin.invalid_provider_url', [], 'Invalid or forbidden provider URL')],
                                    'code' => 'INVALID_PROVIDER_URL',
                                ], 422);
                            }
                            $host = strtolower($parsedUrl['host'] ?? '');
                            if ($this->isCloudMetadataOrForbiddenHost($host)) {
                                return $this->secureJson([
                                    'errors' => ['aiProviders' => $this->trans('app_settings.admin.ollama_metadata_forbidden', [], 'Ollama URL cannot target cloud metadata or reserved infrastructure')],
                                    'code' => 'OLLAMA_METADATA_FORBIDDEN',
                                ], 422);
                            }
                            $current[$provider]['url'] = $rawUrl;
                        } else {
                            $current[$provider]['url'] = null;
                        }
                    }
                }

                $settings->aiProviders = $current;
                $changed = true;
            }

            // OAuth providers (JSON body with key "oauthProviders")
            if (array_key_exists('oauthProviders', $data) && is_array($data['oauthProviders'])) {
                $current  = $settings->oauthProviders ?? [];
                $incoming = $data['oauthProviders'];

                foreach (['google', 'microsoft', 'github', 'gitlab'] as $provider) {
                    if (!array_key_exists($provider, $incoming)) {
                        continue;
                    }
                    $p = $incoming[$provider];

                    if (array_key_exists('enabled', $p)) {
                        $current[$provider]['enabled'] = (bool) $p['enabled'];
                    }
                    if (array_key_exists('clientId', $p)) {
                        $current[$provider]['clientId'] = trim((string) $p['clientId']) ?: null;
                    }
                    if (array_key_exists('clientSecret', $p)) {
                        $val = trim((string) $p['clientSecret']);
                        $current[$provider]['clientSecret'] = $val !== ''
                            ? 'enc:' . $this->secretService->encrypt($val)
                            : null;
                    }
                }

                $settings->oauthProviders = $current;
                $changed = true;
            }

            // OIDC providers (JSON body with key "oidcProviders")
            if (array_key_exists('oidcProviders', $data) && is_array($data['oidcProviders'])) {
                $current = $settings->oauthProviders['oidcProviders'] ?? [];
                $incoming = $data['oidcProviders'];

                foreach ($incoming as $index => $p) {
                    $id = $p['id'] ?? (string) Uuid::v4();

                    // Merge avec l'existant si l'ID correspond
                    $existing = null;
                    foreach ($current as $ei => $ec) {
                        if (($ec['id'] ?? '') === $id) {
                            $existing = $ei;
                            break;
                        }
                    }

                    $entry = $existing !== null ? $current[$existing] : ['id' => $id];

                    if (array_key_exists('name', $p)) {
                        $pName = trim((string) $p['name']);
                        if (mb_strlen($pName) > 128 || preg_match('/[\r\n\x00-\x1F\x7F]/', $pName) || str_contains($pName, '<') || str_contains($pName, '>')) {
                            return $this->secureJson([
                                'errors' => ['oidcProviders' => $this->trans('app_settings.admin.invalid_oidc_name', [], 'OIDC provider name is invalid or contains disallowed characters')],
                                'code' => 'INVALID_OIDC_NAME',
                            ], 422);
                        }
                        $entry['name'] = $pName;
                    }
                    if (array_key_exists('issuer', $p)) {
                        $rawIssuer = trim((string) $p['issuer']);
                        if ($rawIssuer !== '') {
                            if (strlen($rawIssuer) > 2048 || !filter_var($rawIssuer, FILTER_VALIDATE_URL) || preg_match('/[\r\n\x00-\x1F\x7F]/', $rawIssuer)) {
                                return $this->secureJson([
                                    'errors' => ['oidcProviders' => $this->trans('app_settings.admin.invalid_oidc_issuer', [], 'Invalid or forbidden OIDC issuer URL')],
                                    'code' => 'INVALID_OIDC_ISSUER',
                                ], 422);
                            }
                            $parsedIssuer = parse_url($rawIssuer);
                            $scheme = strtolower($parsedIssuer['scheme'] ?? '');
                            if (!in_array($scheme, ['http', 'https'], true)) {
                                return $this->secureJson([
                                    'errors' => ['oidcProviders' => $this->trans('app_settings.admin.invalid_oidc_issuer', [], 'Invalid or forbidden OIDC issuer URL')],
                                    'code' => 'INVALID_OIDC_ISSUER',
                                ], 422);
                            }
                            $host = strtolower($parsedIssuer['host'] ?? '');
                            if ($this->isCloudMetadataOrForbiddenHost($host)) {
                                return $this->secureJson([
                                    'errors' => ['oidcProviders' => $this->trans('app_settings.admin.invalid_oidc_issuer', [], 'Invalid or forbidden OIDC issuer URL')],
                                    'code' => 'INVALID_OIDC_ISSUER',
                                ], 422);
                            }
                            $entry['issuer'] = $rawIssuer;
                        }
                    }
                    if (array_key_exists('clientId', $p)) {
                        $entry['clientId'] = trim((string) $p['clientId']) ?: null;
                    }
                    if (array_key_exists('clientSecret', $p)) {
                        $val = trim((string) $p['clientSecret']);
                        $entry['clientSecret'] = $val !== ''
                            ? 'enc:' . $this->secretService->encrypt($val)
                            : null;
                    }
                    if (array_key_exists('enabled', $p)) {
                        $entry['enabled'] = (bool) $p['enabled'];
                    }

                    if ($existing !== null) {
                        $current[$existing] = $entry;
                    } else {
                        $current[] = $entry;
                    }
                }

                $settings->oauthProviders['oidcProviders'] = $current;
                $changed = true;
            }
        }

        if ($changed) {
            $settings->updatedAt = new \DateTimeImmutable();
            $this->em->flush();
            $this->cache->delete('app_settings_data');
        }

        return $this->secureJson($this->serialize($settings));
    }

    private function validateImageFile(UploadedFile $file, bool $allowIco = false): ?string
    {
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
        if ($allowIco) {
            $allowedMimes[] = 'image/x-icon';
            $allowedMimes[] = 'image/vnd.microsoft.icon';
        }

        if (!in_array($file->getMimeType(), $allowedMimes, true)) {
            return $this->trans('app_settings.admin.invalid_file_type', ['%extra%' => $allowIco ? ', ICO' : ''], 'Invalid file type. Allowed: JPEG, PNG, GIF, WebP, SVG' . ($allowIco ? ', ICO' : ''));
        }

        if ($file->getSize() > 2 * 1024 * 1024) {
            return $this->trans('app_settings.admin.file_too_large', [], 'File exceeds the maximum size of 2 MB');
        }

        return null;
    }

    /** Supprime les scripts, XXE et gestionnaires d'événements des fichiers SVG uploadés. */
    private function sanitizeSvg(\Symfony\Component\HttpFoundation\File\UploadedFile $file): void
    {
        $content = file_get_contents($file->getPathname());
        if ($content === false) return;

        // Supprimer les déclarations DOCTYPE et ENTITY (anti-XXE)
        $content = preg_replace('/<!(?:DOCTYPE|ENTITY)\b[^>]*>/i', '', $content);
        // Supprimer les balises <script> et leur contenu
        $content = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $content);
        // Supprimer les gestionnaires d'événements inline (onload, onclick, on*, etc.)
        $content = preg_replace('/\s+on[a-z0-9_-]+\s*=\s*["\'][^"\']*["\']/i', '', $content);
        // Supprimer les <foreignObject> (peuvent contenir du HTML arbitraire)
        $content = preg_replace('/<foreignObject[^>]*>.*?<\/foreignObject>/is', '', $content);
        // Neutraliser les attributs href / xlink:href avec javascript: ou data:text/html
        $content = preg_replace('/(href|xlink:href)\s*=\s*["\']\s*(?:javascript:|data:\s*text\/html)[^"\']*["\']/i', '$1=""', $content);

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
            'oauthProviders' => $this->serializeOauthProviders($s->oauthProviders ?? []),
            'oidcProviders' => $this->serializeOidcProviders($s->oauthProviders['oidcProviders'] ?? []),
        ];
    }

    /** @return array<string, array{enabled: bool, configured: bool}> */
    private function serializeOauthProviders(?array $providers): array
    {
        $result = [];
        foreach (['google', 'microsoft', 'github', 'gitlab'] as $p) {
            $cfg = $providers[$p] ?? [];
            $result[$p] = [
                'enabled'    => (bool) ($cfg['enabled'] ?? false),
                'configured' => !empty($cfg['clientId']) && !empty($cfg['clientSecret']),
                'redirectUri'=> ($cfg['enabled'] ?? false) ? '/connect/' . $p . '/check' : null,
            ];
        }
        return $result;
    }

    /** @return array<array{id: string, name: string, issuer: string, clientId: string, enabled: bool, configured: bool}> */
    private function serializeOidcProviders(?array $providers): array
    {
        if (!$providers) {
            return [];
        }
        $result = [];
        foreach ($providers as $p) {
            $result[] = [
                'id' => $p['id'] ?? '',
                'name' => $p['name'] ?? '',
                'issuer' => $p['issuer'] ?? '',
                'clientId' => $p['clientId'] ?? '',
                // JAMAIS renvoyer le clientSecret (meme chiffre) au frontend
                'enabled' => (bool) ($p['enabled'] ?? false),
                'configured' => !empty($p['clientId']) && !empty($p['clientSecret']),
            ];
        }
        return $result;
    }

}
