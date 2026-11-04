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
        $inputData = array_values($input)[0]->data ?? [];
        $config = $ctx->variables['_node_config'] ?? [];
        $url = $config['url'] ?? '';
        $method = strtoupper($config['method'] ?? 'GET');
        $headers = $config['headers'] ?? [];
        $body = $config['body'] ?? '';

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
