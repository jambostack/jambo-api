<?php

namespace App\Service\Flow\Handlers\Http;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;
use App\Service\Security\SsrfProtectionService;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class HttpRequestHandler implements FlowNodeHandler
{
    private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'];
    private const MAX_BODY_BYTES = 2097152; // 2MB
    private const MAX_URL_LENGTH = 2048;

    private SsrfProtectionService $ssrfProtection;
    private ?HttpClientInterface $httpClient;
    private ?TranslatorInterface $translator;

    public function __construct(
        ?SsrfProtectionService $ssrfProtection = null,
        ?HttpClientInterface $httpClient = null,
        ?TranslatorInterface $translator = null,
    ) {
        $this->ssrfProtection = $ssrfProtection ?? new SsrfProtectionService();
        $this->httpClient = $httpClient;
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
                'error'       => $this->trans('flow.http_request.missing_project_context', [], 'Missing project context for outbound HTTP request.'),
                'status_code' => 0,
                'headers'     => [],
            ]);
        }

        $firstInput = array_values($input)[0] ?? null;
        $inputData = $firstInput instanceof NodeOutput ? $firstInput->data : (is_array($firstInput) ? $firstInput : []);
        $config = $ctx->variables['_node_config'] ?? [];
        $url = (string) ($config['url'] ?? '');
        $method = strtoupper((string) ($config['method'] ?? 'GET'));
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
                'error'       => $this->trans('flow.http_request.unsupported_method', ['%method%' => $method], sprintf('Unsupported HTTP method "%s"', $method)),
                'status_code' => 0,
                'headers'     => [],
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
                'error'       => $this->trans('flow.http_request.url_empty', [], 'URL is empty'),
                'status_code' => 0,
                'headers'     => [],
            ]);
        }

        if (strlen($url) > self::MAX_URL_LENGTH) {
            return new NodeOutput(data: [
                'called'      => false,
                'url'         => substr($url, 0, 100) . '...',
                'error'       => $this->trans('flow.http_request.url_too_long', ['%max%' => (string) self::MAX_URL_LENGTH], sprintf('HTTP URL exceeds %d characters limit', self::MAX_URL_LENGTH)),
                'status_code' => 0,
                'headers'     => [],
            ]);
        }

        if (preg_match('/[\r\n\x00-\x1F\x7F]/', $url)) {
            return new NodeOutput(data: [
                'called'      => false,
                'url'         => preg_replace('/[\r\n\x00-\x1F\x7F]/', '', substr($url, 0, 100)),
                'error'       => $this->trans('flow.http_request.url_control_chars', [], 'HTTP URL contains forbidden control characters.'),
                'status_code' => 0,
                'headers'     => [],
            ]);
        }

        $parsedUrl = parse_url($url);
        if ($parsedUrl === false || !isset($parsedUrl['scheme'])) {
            return new NodeOutput(data: [
                'called'      => false,
                'url'         => $url,
                'error'       => $this->trans('flow.http_request.invalid_url', [], 'Invalid HTTP URL format'),
                'status_code' => 0,
                'headers'     => [],
            ]);
        }
        $scheme = strtolower((string) $parsedUrl['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return new NodeOutput(data: [
                'called'      => false,
                'url'         => $url,
                'error'       => $this->trans('flow.http_request.unsupported_protocol', ['%scheme%' => $scheme], sprintf('Unsupported protocol "%s". Only HTTP and HTTPS are permitted.', $scheme)),
                'status_code' => 0,
                'headers'     => [],
            ]);
        }
        if (!isset($parsedUrl['host']) || trim((string) $parsedUrl['host']) === '') {
            return new NodeOutput(data: [
                'called'      => false,
                'url'         => $url,
                'error'       => $this->trans('flow.http_request.invalid_url', [], 'Invalid HTTP URL format'),
                'status_code' => 0,
                'headers'     => [],
            ]);
        }

        if (isset($parsedUrl['user']) || isset($parsedUrl['pass'])) {
            return new NodeOutput(data: [
                'called'      => false,
                'url'         => $url,
                'error'       => $this->trans('flow.http_request.credentials_forbidden', [], 'Credentials in HTTP URL are strictly forbidden.'),
                'status_code' => 0,
                'headers'     => [],
            ]);
        }

        if (strlen($body) > self::MAX_BODY_BYTES) {
            return new NodeOutput(data: [
                'called'      => false,
                'url'         => $url,
                'error'       => $this->trans('flow.http_request.payload_too_large', [], 'HTTP request payload exceeds 2MB limit'),
                'status_code' => 0,
                'headers'     => [],
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

        // Tracing multi-tenant authentique garanti par la plateforme
        $headers['X-JamboApi-Project'] = $ctx->projectUuid;
        $headers['X-JamboApi-Automation-Id'] = (string) $ctx->automationId;
        $headers['X-JamboApi-Run-Id'] = $ctx->runId;

        if (!isset($headers['User-Agent']) && !isset($headers['user-agent'])) {
            $headers['User-Agent'] = 'JamboApi-FlowHttp/1.0';
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
                'body'        => $this->ssrfProtection->readSafeResponseBody($response, 1048576), // 1 Mo max
                'headers'     => $response->getHeaders(false),
            ]);
        } catch (\Throwable $e) {
            return new NodeOutput(data: [
                'called'      => false,
                'url'         => $url,
                'error'       => $e->getMessage(),
                'status_code' => 0,
                'headers'     => [],
            ]);
        }
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

    public static function getCategory(): string { return 'http'; }
    public static function getType(): string { return 'request'; }
    public static function getFullType(): string { return 'http.request'; }
    public static function getLabel(): string { return 'Requete HTTP'; }
    public static function getDescription(): string { return 'Appelle une URL HTTP externe'; }
    public static function getIcon(): string { return 'Globe'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['url'],
            'properties' => [
                'url' => ['type' => 'string', 'format' => 'url', 'title' => 'URL', 'template' => true],
                'method' => ['type' => 'string', 'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], 'title' => 'Methode', 'default' => 'POST'],
                'headers' => ['type' => 'json', 'title' => 'Headers (JSON)', 'default' => '{}'],
                'body' => ['type' => 'string', 'format' => 'textarea', 'title' => 'Corps', 'template' => true],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}

