<?php
// src/Service/Cloud/DockerContainerOrchestrator.php
namespace App\Service\Cloud;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class DockerContainerOrchestrator implements ContainerOrchestratorInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $dockerApiBase,   // e.g. http://127.0.0.1:2375
        private readonly string $network,         // e.g. jambo_cloud
    ) {}

    public function buildImage(string $tag, array $files, string $dockerfile): string
    {
        $tar = $this->buildTarContext($files, $dockerfile);

        $response = $this->httpClient->request(
            'POST',
            $this->dockerApiBase . '/build?' . http_build_query(['t' => $tag, 'rm' => 'true']),
            [
                'headers' => ['Content-Type' => 'application/x-tar'],
                'body'    => $tar,
            ],
        );

        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException('Docker build failed: ' . $response->getContent(false));
        }
        // Drain the streamed build log so the build completes.
        $response->getContent(false);

        return $tag;
    }

    public function runContainer(string $imageRef, string $name, array $labels, array $env): string
    {
        $envList = [];
        foreach ($env as $k => $v) {
            $envList[] = "{$k}={$v}";
        }

        $create = $this->httpClient->request(
            'POST',
            $this->dockerApiBase . '/containers/create?' . http_build_query(['name' => $name]),
            [
                'json' => [
                    'Image'  => $imageRef,
                    'Labels' => $labels,
                    'Env'    => $envList,
                    'HostConfig' => [
                        'NetworkMode'   => $this->network,
                        'RestartPolicy' => ['Name' => 'unless-stopped'],
                    ],
                ],
            ],
        );

        if ($create->getStatusCode() >= 400) {
            throw new \RuntimeException('Docker create failed: ' . $create->getContent(false));
        }

        $id = $create->toArray(false)['Id'] ?? throw new \RuntimeException('No container Id returned');

        $start = $this->httpClient->request('POST', $this->dockerApiBase . "/containers/{$id}/start");
        if ($start->getStatusCode() >= 400) {
            throw new \RuntimeException('Docker start failed: ' . $start->getContent(false));
        }

        return $id;
    }

    public function stopContainer(string $containerId): void
    {
        $this->httpClient->request('POST', $this->dockerApiBase . "/containers/{$containerId}/stop")->getStatusCode();
    }

    public function removeContainer(string $containerId): void
    {
        $this->httpClient->request('DELETE', $this->dockerApiBase . "/containers/{$containerId}?" . http_build_query(['force' => 'true']))->getStatusCode();
    }

    public function containerStatus(string $containerId): string
    {
        $res = $this->httpClient->request('GET', $this->dockerApiBase . "/containers/{$containerId}/json");
        if ($res->getStatusCode() === 404) {
            return 'missing';
        }
        $state = $res->toArray(false)['State'] ?? [];
        return ($state['Running'] ?? false) ? 'running' : 'exited';
    }

    /**
     * Build an uncompressed tar archive holding the files + Dockerfile.
     * @param array<string,string> $files
     */
    private function buildTarContext(array $files, string $dockerfile): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'jambo_ctx_') . '.tar';
        $phar = new \PharData($tmp);

        foreach ($files as $path => $content) {
            $phar->addFromString($path, $content);
        }
        $phar->addFromString('Dockerfile', $dockerfile);

        $bytes = (string) file_get_contents($tmp);
        unlink($tmp);

        return $bytes;
    }
}
