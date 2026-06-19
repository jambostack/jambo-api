<?php

namespace App\Service\Flow\Handlers\Action;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;
use Symfony\Component\HttpClient\HttpClient;

class CallWebhookHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        $url    = $config['url'] ?? '';
        $method = strtoupper($config['method'] ?? 'POST');
        $headers = $config['headers'] ?? [];
        $body    = $config['body'] ?? '';

        try {
            $this->validateUrl($url);

            $client = HttpClient::create(['timeout' => 10, 'max_redirects' => 0]);
            $response = $client->request($method, $url, [
                'headers' => $headers,
                'body'    => $body,
            ]);

            return new NodeOutput(data: ['called' => true, 'url' => $url, 'status_code' => $response->getStatusCode()]);
        } catch (\Throwable $e) {
            return new NodeOutput(data: ['called' => false, 'url' => $url, 'error' => $e->getMessage()]);
        }
    }

    private function validateUrl(string $url): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host   = parse_url($url, PHP_URL_HOST);

        if ($host === null || $host === false || $host === '') {
            throw new \RuntimeException('Invalid webhook URL: missing host');
        }

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('Invalid webhook URL: only http/https allowed');
        }

        // Bloquer les IPs internes/réservées
        $ip = gethostbyname($host);
        if ($ip === $host) {
            throw new \RuntimeException('Cannot resolve webhook host');
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new \RuntimeException('Webhook URL resolves to private/internal IP');
        }
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
