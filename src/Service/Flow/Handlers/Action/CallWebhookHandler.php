<?php

namespace App\Service\Flow\Handlers\Action;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;
use App\Service\Security\SsrfProtectionService;
use Symfony\Component\HttpClient\HttpClient;

class CallWebhookHandler implements FlowNodeHandler
{
    private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'];
    private const MAX_BODY_BYTES = 2097152; // 2MB
    private const MAX_URL_LENGTH = 2048;

    private SsrfProtectionService $ssrfProtection;
    private ?\Symfony\Contracts\HttpClient\HttpClientInterface $httpClient;

    public function __construct(
        ?SsrfProtectionService $ssrfProtection = null,
        ?\Symfony\Contracts\HttpClient\HttpClientInterface $httpClient = null,
    ) {
        $this->ssrfProtection = $ssrfProtection ?? new SsrfProtectionService();
        $this->httpClient = $httpClient;
    }

    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
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
        $body   = (string) ($config['body'] ?? '');

        if (!in_array($method, self::ALLOWED_METHODS, true)) {
            return new NodeOutput(data: ['called' => false, 'url' => $url, 'error' => sprintf('Unsupported HTTP method "%s"', $method)]);
        }

        // Cloisonnement contextuel strict : interpolation sécurisée sans échappement environnement
        $safeVars = $ctx->getSafeVariables();
        $url  = trim($this->interpolateSafe($url, $inputData, $safeVars));
        $body = $this->interpolateSafe($body, $inputData, $safeVars);

        if (strlen($body) > self::MAX_BODY_BYTES) {
            return new NodeOutput(data: ['called' => false, 'url' => $url, 'error' => 'Webhook request payload exceeds 2MB limit']);
        }

        if ($url === '') {
            return new NodeOutput(data: ['called' => false, 'url' => '', 'error' => 'URL is empty']);
        }

        if (strlen($url) > self::MAX_URL_LENGTH) {
            return new NodeOutput(data: ['called' => false, 'url' => substr($url, 0, 100) . '...', 'error' => sprintf('Webhook URL exceeds %d characters limit', self::MAX_URL_LENGTH)]);
        }

        $parsedUrl = parse_url($url);
        if ($parsedUrl === false || !isset($parsedUrl['scheme'])) {
            return new NodeOutput(data: ['called' => false, 'url' => $url, 'error' => 'Invalid webhook URL format']);
        }
        $scheme = strtolower((string) $parsedUrl['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return new NodeOutput(data: ['called' => false, 'url' => $url, 'error' => sprintf('Unsupported protocol "%s". Only HTTP and HTTPS are permitted.', $scheme)]);
        }
        if (!isset($parsedUrl['host'])) {
            return new NodeOutput(data: ['called' => false, 'url' => $url, 'error' => 'Invalid webhook URL format']);
        }

        // Nettoyage et assainissement des en-têtes (anti-CRLF, anti-Host spoofing, anti-smuggling)
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
            if (in_array($hKeyLower, ['host', 'transfer-encoding', 'connection', 'upgrade', 'keep-alive', 'proxy-connection'], true)) {
                continue;
            }
            $cleanHeaders[$hKeyTrim] = $hValStr;
        }
        $headers = $cleanHeaders;

        // Signature HMAC et headers par défaut
        $secret = trim((string) ($config['secret'] ?? ''));
        if ($secret !== '') {
            $headers['X-JamboApi-Timestamp'] = (string) time();
            $headers['X-JamboApi-Signature'] = 'sha256=' . hash_hmac('sha256', $body, $secret);
        }
        if (!isset($headers['User-Agent']) && !isset($headers['user-agent'])) {
            $headers['User-Agent'] = 'JamboApi-FlowWebhook/1.0';
        }

        try {
            $options = $this->ssrfProtection->buildSafeHttpClientOptions($url, [
                'headers' => $headers,
                'body'    => $body,
            ], idleTimeout: 5.0, maxDuration: 10.0);

            $client = $this->httpClient ?? HttpClient::create();
            $response = $client->request($method, $url, $options);

            return new NodeOutput(data: [
                'called'      => true,
                'url'         => $url,
                'status_code' => $response->getStatusCode(),
                'body'        => $this->ssrfProtection->readSafeResponseBody($response, 16384),
            ]);
        } catch (\Throwable $e) {
            return new NodeOutput(data: ['called' => false, 'url' => $url, 'error' => $e->getMessage()]);
        }
    }

    private function interpolateSafe(string $text, array $data, array $safeVars): string
    {
        if (!str_contains($text, '{{')) {
            return $text;
        }

        $forbiddenEnvPatterns = [
            '/\{\{\s*(?:_?ENV|_?SERVER|GLOBALS|getenv|putenv|env|server|system|exec|passthru|shell_exec|eval)\b[^\}]*\}\}/i',
            '/\{\{\s*(?:APP_SECRET|APP_ENV|DATABASE_URL|MAILER_DSN|JWT_SECRET|MERCURE_JWT_SECRET|PHP_SELF|DOCUMENT_ROOT)[^\}]*\}\}/i',
            '/\{\{\s*(?:[a-zA-Z0-9_\.]*(?:password|secret|token|credential|hash|salt|bearer|jwt|database_url|api_?key|access_?token|auth))[^\}]*\}\}/i',
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
                if (str_starts_with($fullMatch, '_') || preg_match('/(?:env|server|globals|secret|password|token|key|credential|jwt|auth)/i', $fullMatch)) {
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
