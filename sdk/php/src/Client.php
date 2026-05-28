<?php

namespace JamboApi;

class Client
{
    private string $baseUrl;
    private ?string $apiKey;
    private int $timeout;
    private int $retries;
    private array $cache = [];

    public function __construct(array $config)
    {
        $this->baseUrl = rtrim($config['baseUrl'] ?? '', '/');
        $this->apiKey = $config['apiKey'] ?? null;
        $this->timeout = $config['timeout'] ?? 15;
        $this->retries = $config['retries'] ?? 2;
    }

    /** @return array{entries: array, total: int} */
    public function list(string $collection, array $options = []): array
    {
        $params = array_filter([
            'locale' => $options['locale'] ?? null,
            'status' => $options['status'] ?? null,
            'limit'  => $options['limit'] ?? 50,
            'offset' => $options['offset'] ?? 0,
        ], fn($v) => $v !== null);

        return $this->get("/api/collections/{$collection}", $params);
    }

    /** @return array|null */
    public function getEntry(string $collection, string $uuid): ?array
    {
        return $this->get("/api/collections/{$collection}/{$uuid}");
    }

    /** @return array */
    public function create(string $collection, array $data): array
    {
        return $this->post("/api/collections/{$collection}", $data);
    }

    /** @return array */
    public function update(string $collection, string $uuid, array $data): array
    {
        return $this->put("/api/collections/{$collection}/{$uuid}", $data);
    }

    public function delete(string $collection, string $uuid): bool
    {
        $result = $this->request('DELETE', "/api/collections/{$collection}/{$uuid}");

        return ($result['deleted'] ?? false) === true;
    }

    /** @return array */
    public function search(string $query, array $options = []): array
    {
        return $this->get('/api/search', array_filter([
            'q'           => $query,
            'collection'  => $options['collection'] ?? null,
            'locale'      => $options['locale'] ?? null,
            'limit'       => $options['limit'] ?? 20,
        ], fn($v) => $v !== null));
    }

    /** @return array */
    public function listMedia(array $options = []): array
    {
        return $this->get('/api/media', array_filter([
            'search' => $options['search'] ?? null,
            'limit'  => $options['limit'] ?? 50,
            'offset' => $options['offset'] ?? 0,
        ], fn($v) => $v !== null));
    }

    public function mediaUrl(string $uuid, array $transforms = []): string
    {
        $query = !empty($transforms) ? '?' . http_build_query($transforms) : '';

        return "{$this->baseUrl}/cdn/media/{$uuid}{$query}";
    }

    // ===== HTTP Core =====

    private function request(string $method, string $path, array $body = null): array
    {
        $url = $this->baseUrl . $path;
        $options = [
            'http' => [
                'method'  => $method,
                'header'  => "Content-Type: application/json\r\n",
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
        ];

        if ($this->apiKey) {
            $options['http']['header'] .= "Authorization: Bearer {$this->apiKey}\r\n";
        }

        if ($body !== null) {
            $options['http']['content'] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }

        for ($attempt = 0; $attempt <= $this->retries; $attempt++) {
            $context = stream_context_create($options);
            $response = @file_get_contents($url, false, $context);

            if ($response !== false) {
                return json_decode($response, true) ?? [];
            }

            if ($attempt < $this->retries) {
                usleep(min(1000000 * (2 ** $attempt), 8000000));
            }
        }

        throw new JamboApiException("Échec de la requête après {$this->retries} tentatives");
    }

    private function get(string $path, array $params = []): array
    {
        $query = !empty($params) ? '?' . http_build_query($params) : '';

        return $this->request('GET', $path . $query);
    }

    private function post(string $path, array $data): array
    {
        return $this->request('POST', $path, $data);
    }

    private function put(string $path, array $data): array
    {
        return $this->request('PUT', $path, $data);
    }
}
