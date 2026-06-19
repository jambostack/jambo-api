<?php

namespace App\Service\Flow\Handlers\Http;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;
use Symfony\Component\HttpClient\HttpClient;

class HttpRequestHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $inputData = array_values($input)[0]->data ?? [];
        $config = $ctx->variables['_node_config'] ?? [];
        $url = $config['url'] ?? '';
        $method = strtoupper($config['method'] ?? 'GET');
        $headers = $config['headers'] ?? [];
        $body = $config['body'] ?? '';

        $this->validateUrl($url);

        $client = HttpClient::create(['timeout' => 10, 'max_redirects' => 0]);
        try {
            $response = $client->request($method, $url, ['headers' => $headers, 'body' => $body]);
            return new NodeOutput(data: [
                'status_code' => $response->getStatusCode(),
                'body' => $response->getContent(),
                'headers' => $response->getHeaders(),
            ]);
        } catch (\Throwable $e) {
            return new NodeOutput(data: ['error' => $e->getMessage(), 'status_code' => 0]);
        }
    }

    private function validateUrl(string $url): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            throw new \RuntimeException('Invalid webhook URL: missing host');
        }
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('Only http/https allowed');
        }
        // Bloquer les IPs internes/reservees (IPv4 + IPv6)
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (empty($records)) {
            throw new \RuntimeException('Cannot resolve host');
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? '';
            if ($ip === '') continue;

            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new \RuntimeException('Private/internal IP blocked');
            }
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
