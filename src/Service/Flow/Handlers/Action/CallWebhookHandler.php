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
        $headers = is_array($config['headers'] ?? null) ? $config['headers'] : [];
        $body    = (string) ($config['body'] ?? '');

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
            '/\{\{\s*(?:[a-zA-Z0-9_\.]*(?:password|secret|token|credential|hash|salt|bearer|jwt|database_url))[^\}]*\}\}/i',
        ];
        $result = (string) preg_replace($forbiddenEnvPatterns, '', $text);
        $merged = array_merge($safeVars, $data);

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
