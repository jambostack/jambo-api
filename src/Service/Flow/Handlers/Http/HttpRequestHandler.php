<?php

namespace App\Service\Flow\Handlers\Http;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;
use App\Service\Security\SsrfProtectionService;
use Symfony\Component\HttpClient\HttpClient;

class HttpRequestHandler implements FlowNodeHandler
{
    private SsrfProtectionService $ssrfProtection;

    public function __construct(?SsrfProtectionService $ssrfProtection = null)
    {
        $this->ssrfProtection = $ssrfProtection ?? new SsrfProtectionService();
    }

    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $firstInput = array_values($input)[0] ?? null;
        $inputData = $firstInput instanceof NodeOutput ? $firstInput->data : (is_array($firstInput) ? $firstInput : []);
        $config = $ctx->variables['_node_config'] ?? [];
        $url = (string) ($config['url'] ?? '');
        $method = strtoupper($config['method'] ?? 'GET');
        $headers = is_array($config['headers'] ?? null) ? $config['headers'] : [];
        $body = (string) ($config['body'] ?? '');

        // Cloisonnement contextuel strict : interpolation sécurisée sans échappement environnement
        $safeVars = $ctx->getSafeVariables();
        $url  = trim($this->interpolateSafe($url, $inputData, $safeVars));
        $body = $this->interpolateSafe($body, $inputData, $safeVars);

        if ($url === '') {
            return new NodeOutput(data: ['error' => 'URL is empty', 'status_code' => 0]);
        }

        try {
            $options = $this->ssrfProtection->buildSafeHttpClientOptions($url, [
                'headers' => $headers,
                'body'    => $body,
            ], idleTimeout: 5.0, maxDuration: 10.0);

            $client = HttpClient::create();
            $response = $client->request($method, $url, $options);
            return new NodeOutput(data: [
                'status_code' => $response->getStatusCode(),
                'body' => $this->ssrfProtection->readSafeResponseBody($response, 1048576), // 1 Mo max
                'headers' => $response->getHeaders(false),
            ]);
        } catch (\Throwable $e) {
            return new NodeOutput(data: ['error' => $e->getMessage(), 'status_code' => 0]);
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
