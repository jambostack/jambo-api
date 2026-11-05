<?php

namespace App\Service\Flow\Handlers\Action;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;
use App\Service\Security\SsrfProtectionService;
use App\Service\WebhookSecretService;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class CallWebhookHandler implements FlowNodeHandler
{
    private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'];
    private const MAX_BODY_BYTES = 2097152; // 2MB
    private const MAX_URL_LENGTH = 2048;

    private SsrfProtectionService $ssrfProtection;
    private ?HttpClientInterface $httpClient;
    private ?WebhookSecretService $secretService;
    private ?TranslatorInterface $translator;

    public function __construct(
        ?SsrfProtectionService $ssrfProtection = null,
        ?HttpClientInterface $httpClient = null,
        ?WebhookSecretService $secretService = null,
        ?TranslatorInterface $translator = null,
    ) {
        $this->ssrfProtection = $ssrfProtection ?? new SsrfProtectionService();
        $this->httpClient = $httpClient;
        $this->secretService = $secretService;
        $this->translator = $translator;
    }

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            $translated = $this->translator->trans($id, $parameters, 'messages');
            if ($translated !== $id) {
                return $translated;
            }
        }
        if ($default !== null) {
            return strtr($default, $parameters);
        }
        return $id;
    }

    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        // Contrôle strict du contexte multi-tenant
        if (trim($ctx->projectUuid) === '') {
            return new NodeOutput(data: [
                'called'      => false,
                'url'         => '',
                'error'       => $this->trans('flow.call_webhook.missing_project_context', [], 'Missing project context for outbound webhook.'),
                'status_code' => 0,
            ]);
        }

        $firstInput = array_values($input)[0] ?? null;
        $inputData = $firstInput instanceof NodeOutput ? $firstInput->data : (is_array($firstInput) ? $firstInput : []);
        $config = $ctx->variables['_node_config'] ?? [];
        $url    = (string) ($config['url'] ?? '');
        $method = strtoupper((string) ($config['method'] ?? 'POST'));
        $rawHeaders = $config['headers'] ?? [];
        $headers = is_array($rawHeaders) ? $rawHeaders : [];
        if (is_string($rawHeaders) && trim($rawHeaders) !== '') {
            $decoded = json_decode($rawHeaders, true);
            if (is_array($decoded)) {
                $headers = $decoded;
            }
        }
        $body = (string) ($config['body'] ?? '');

        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            return new NodeOutput(data: [
                'called'      => false,
                'url'         => $url,
                'error'       => $this->trans('flow.call_webhook.unsupported_method', ['%method%' => $method], sprintf('Unsupported HTTP method "%s"', $method)),
                'status_code' => 0,
            ]);
        }

        // Cloisonnement contextuel strict : interpolation sécurisée sans échappement environnement
        $safeVars = $ctx->getSafeVariables();
        $url  = trim($this->interpolateSafe($url, $inputData, $safeVars));
        $body = $this->interpolateSafe($body, $inputData, $safeVars);

        if ($url === '') {
            return new NodeOutput(data: [
                'called'      => false,
                'url'         => '',
                'error'       => $this->trans('flow.call_webhook.url_empty', [], 'URL is empty'),
                'status_code' => 0,
            ]);
        }

        if (strlen($url) > self::MAX_URL_LENGTH) {
            return new NodeOutput(data: [
                'called'      => false,
                'url'         => substr($url, 0, 100) . '...',
                'error'       => $this->trans('flow.call_webhook.url_too_long', ['%max%' => (string) self::MAX_URL_LENGTH], sprintf('Webhook URL exceeds %d characters limit', self::MAX_URL_LENGTH)),
                'status_code' => 0,
            ]);
        }

        if (preg_match('/[\r\n\x00-\x1F\x7F]/', $url)) {
            return new NodeOutput(data: [
                'called'      => false,
                'url'         => preg_replace('/[\r\n\x00-\x1F\x7F]/', '', substr($url, 0, 100)),
                'error'       => $this->trans('flow.call_webhook.url_control_chars', [], 'Webhook URL contains forbidden control characters.'),
                'status_code' => 0,
            ]);
        }

        $parsedUrl = parse_url($url);
        if ($parsedUrl === false || !isset($parsedUrl['scheme'])) {
            return new NodeOutput(data: [
                'called'      => false,
                'url'         => $url,
                'error'       => $this->trans('flow.call_webhook.invalid_url', [], 'Invalid webhook URL format'),
                'status_code' => 0,
            ]);
        }
        $scheme = strtolower((string) $parsedUrl['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return new NodeOutput(data: [
                'called'      => false,
                'url'         => $url,
                'error'       => $this->trans('flow.call_webhook.unsupported_protocol', ['%scheme%' => $scheme], sprintf('Unsupported protocol "%s". Only HTTP and HTTPS are permitted.', $scheme)),
                'status_code' => 0,
            ]);
        }
        if (!isset($parsedUrl['host']) || trim((string) $parsedUrl['host']) === '') {
            return new NodeOutput(data: [
                'called'      => false,
                'url'         => $url,
                'error'       => $this->trans('flow.call_webhook.invalid_url', [], 'Invalid webhook URL format'),
                'status_code' => 0,
            ]);
        }

        if (isset($parsedUrl['user']) || isset($parsedUrl['pass'])) {
            return new NodeOutput(data: [
                'called'      => false,
                'url'         => $url,
                'error'       => $this->trans('flow.call_webhook.credentials_forbidden', [], 'Credentials in webhook URL are strictly forbidden.'),
                'status_code' => 0,
            ]);
        }

        if (strlen($body) > self::MAX_BODY_BYTES) {
            return new NodeOutput(data: [
                'called'      => false,
                'url'         => $url,
                'error'       => $this->trans('flow.call_webhook.payload_too_large', [], 'Webhook request payload exceeds 2MB limit'),
                'status_code' => 0,
            ]);
        }

        // Nettoyage et assainissement des en-têtes (anti-CRLF, anti-Host spoofing, anti-smuggling, anti-spoofing tenant)
        $cleanHeaders = [];
        foreach ($headers as $hKey => $hVal) {
            if (!is_string($hKey) || !is_scalar($hVal)) {
                continue;
            }
            $hKeyTrim = trim($hKey);
            $hValStr = trim((string) $hVal);
            if (preg_match('/[\r\n\x00]/', $hKeyTrim) || preg_match('/[\r\n\x00]/', $hValStr)) {
                continue;
            }
            $hKeyLower = strtolower($hKeyTrim);
            if (in_array($hKeyLower, ['host', 'transfer-encoding', 'connection', 'upgrade', 'keep-alive', 'proxy-connection', 'content-length'], true)) {
                continue;
            }
            if (str_starts_with($hKeyLower, 'x-jamboapi-')) {
                continue;
            }
            $cleanHeaders[$hKeyTrim] = $hValStr;
        }
        $headers = $cleanHeaders;

        // Contrôle strict de profondeur d'appels et prévention des boucles infinies de webhooks
        $currentHop = (int) ($ctx->variables['_hop_count'] ?? $ctx->getVariable('_hop_count', 0));
        if ($currentHop >= 5) {
            return new NodeOutput(data: [
                'called'      => false,
                'url'         => $url,
                'error'       => $this->trans('flow.call_webhook.recursion_limit_exceeded', ['%max%' => '5'], 'Maximum webhook call recursion depth exceeded (5).'),
                'status_code' => 0,
            ]);
        }

        // Tracing multi-tenant authentique garanti par la plateforme
        $headers['X-JamboApi-Project'] = $ctx->projectUuid;
        $headers['X-JamboApi-Automation-Id'] = (string) $ctx->automationId;
        $headers['X-JamboApi-Run-Id'] = $ctx->runId;
        $headers['X-JamboApi-Hop-Count'] = (string) ($currentHop + 1);

        // Signature HMAC et headers par défaut
        $secret = trim((string) ($config['secret'] ?? ''));
        if ($secret !== '') {
            if (str_starts_with($secret, 'enc:') && $this->secretService !== null) {
                try {
                    $secret = $this->secretService->decrypt(substr($secret, 4));
                } catch (\Throwable) {
                    return new NodeOutput(data: [
                        'called'      => false,
                        'url'         => $url,
                        'error'       => $this->trans('flow.call_webhook.secret_decrypt_error', [], 'Failed to decrypt webhook secret.'),
                        'status_code' => 0,
                    ]);
                }
            }
            $timestamp = (string) time();
            $headers['X-JamboApi-Timestamp'] = $timestamp;
            $headers['X-JamboApi-Signature'] = 'sha256=' . hash_hmac('sha256', $body, $secret);
            $headers['X-JamboApi-Signature-256'] = "t={$timestamp},v1=" . hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
        }
        if (!isset($headers['User-Agent']) && !isset($headers['user-agent'])) {
            $headers['User-Agent'] = 'JamboApi-FlowWebhook/1.0';
        }

        $timeout = isset($config['timeout']) && is_numeric($config['timeout'])
            ? min(15.0, max(1.0, (float) $config['timeout']))
            : 5.0;
        $maxDuration = isset($config['max_duration']) && is_numeric($config['max_duration'])
            ? min(30.0, max(2.0, (float) $config['max_duration']))
            : 10.0;
        $maxRetries = isset($config['retries']) && is_numeric($config['retries'])
            ? min(3, max(0, (int) $config['retries']))
            : 0;

        $attempt = 0;
        $lastException = null;

        while ($attempt <= $maxRetries) {
            try {
                $options = $this->ssrfProtection->buildSafeHttpClientOptions($url, [
                    'headers' => $headers,
                    'body'    => $body,
                ], idleTimeout: $timeout, maxDuration: $maxDuration);

                $client = $this->httpClient ?? HttpClient::create();
                $response = $client->request($method, $url, $options);
                $statusCode = $response->getStatusCode();

                if (in_array($statusCode, [502, 503, 504], true) && $attempt < $maxRetries) {
                    $attempt++;
                    usleep((int) (min(500, pow(2, $attempt) * 50) * 1000));
                    continue;
                }

                return new NodeOutput(data: [
                    'called'      => true,
                    'url'         => $url,
                    'status_code' => $statusCode,
                    'body'        => $this->ssrfProtection->readSafeResponseBody($response, 16384),
                    'attempts'    => $attempt + 1,
                ]);
            } catch (\InvalidArgumentException $e) {
                // Erreur de sécurité SSRF (RFC 1918, loopback, metadata) : NE JAMAIS RETENTER
                return new NodeOutput(data: [
                    'called'      => false,
                    'url'         => $url,
                    'error'       => $this->trans('flow.call_webhook.ssrf_blocked', ['%reason%' => $e->getMessage()], 'Outbound webhook blocked by SSRF protection policy: ' . $e->getMessage()),
                    'status_code' => 0,
                ]);
            } catch (\Throwable $e) {
                $lastException = $e;
                $attempt++;
                if ($attempt <= $maxRetries) {
                    usleep((int) (min(500, pow(2, $attempt) * 50) * 1000));
                    continue;
                }
                break;
            }
        }

        return new NodeOutput(data: [
            'called'      => false,
            'url'         => $url,
            'error'       => $lastException?->getMessage() ?? 'Webhook call failed',
            'status_code' => 0,
            'attempts'    => $attempt,
        ]);
    }

    private function interpolateSafe(string $text, array $data, array $safeVars): string
    {
        if (!str_contains($text, '{{')) {
            return $text;
        }

        $forbiddenEnvPatterns = [
            '/\{\{\s*(?:_?ENV|_?SERVER|GLOBALS|getenv|putenv|env|server|system|exec|passthru|shell_exec|eval|php_sapi|php_uname)\b[^\}]*\}\}/i',
            '/\{\{\s*(?:APP_SECRET|APP_ENV|DATABASE_URL|MAILER_DSN|JWT_SECRET|MERCURE_JWT_SECRET|PHP_SELF|DOCUMENT_ROOT|SYMFONY_DOTENV_VARS)[^\}]*\}\}/i',
            '/\{\{\s*(?:[a-zA-Z0-9_\.]*(?:password|secret|token|credential|hash|salt|bearer|jwt|database_url|api_?key|access_?token|auth|cookie|session_id|private_key))[^\}]*\}\}/i',
        ];
        $result = (string) preg_replace($forbiddenEnvPatterns, '', $text);
        $merged = array_merge($safeVars, $data);

        // Clés simples directes
        foreach ($merged as $key => $value) {
            if (!is_string($key) || str_starts_with($key, '_')) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $valStr = (string) ($value ?? '');
                $result = str_replace('{{ ' . $key . ' }}', $valStr, $result);
                $result = str_replace('{{' . $key . '}}', $valStr, $result);
            }
        }

        // Chemins en notation pointée (ex: {{ entry.title }})
        if (preg_match_all('/\{\{\s*([a-zA-Z0-9_\.]+)\s*\}\}/', $result, $matches)) {
            foreach ($matches[1] as $fullMatch) {
                if (str_starts_with($fullMatch, '_') || preg_match('/(?:env|server|globals|secret|password|token|key|credential|jwt|auth|cookie|session)/i', $fullMatch)) {
                    continue;
                }
                if (str_contains($fullMatch, '.')) {
                    $parts = explode('.', $fullMatch);
                    $val = $merged;
                    foreach ($parts as $part) {
                        if (is_array($val) && array_key_exists($part, $val)) {
                            $val = $val[$part];
                        } else {
                            $val = null;
                            break;
                        }
                    }
                    if (is_scalar($val) || $val === null) {
                        $valStr = (string) ($val ?? '');
                        $result = str_replace('{{ ' . $fullMatch . ' }}', $valStr, $result);
                        $result = str_replace('{{' . $fullMatch . '}}', $valStr, $result);
                    }
                }
            }
        }

        return $result;
    }

    public static function getCategory(): string { return 'action'; }
    public static function getType(): string { return 'call_webhook'; }
    public static function getFullType(): string { return 'action.call_webhook'; }
    public static function getLabel(): string { return 'Appeler un webhook'; }
    public static function getDescription(): string { return "Effectue un appel HTTP vers une URL externe (webhook)"; }
    public static function getIcon(): string { return 'Webhook'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['url'],
            'properties' => [
                'url' => ['type' => 'string', 'format' => 'uri', 'title' => 'URL du webhook', 'template' => true],
                'method' => ['type' => 'string', 'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], 'title' => 'Méthode HTTP', 'default' => 'POST'],
                'headers' => ['type' => 'object', 'title' => 'En-têtes HTTP', 'default' => []],
                'body' => ['type' => 'string', 'format' => 'textarea', 'title' => 'Corps de la requête', 'template' => true],
            ],
        ];
    }

    public static function getOutputPorts(): array { return ['default']; }
}
