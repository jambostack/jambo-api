<?php

namespace App\Mcp;

use Symfony\Component\HttpFoundation\JsonResponse;

class McpServer
{
    /** @var McpTool[] */
    private array $tools = [];

    /** @var McpResource[] */
    private array $resources = [];

    private string $serverName;
    private string $serverVersion = '1.0.0';
    private bool $initialized = false;
    private ?\App\Service\AuditService $audit = null;
    private bool $auditEnabled = false;

    public function __construct(string $name, string $version = '1.0.0')
    {
        $this->serverName = $name;
        $this->serverVersion = $version;
    }

    public function setAuditService(\App\Service\AuditService $auditService): void
    {
        $this->audit = $auditService;
        $this->auditEnabled = true;
    }

    public function registerTool(McpTool $tool): void
    {
        $this->tools[$tool->name] = $tool;
    }

    public function registerResource(McpResource $resource): void
    {
        $this->resources[$resource->uri] = $resource;
    }

    /**
     * Handle a JSON-RPC 2.0 request and return a response.
     */
    public function handleRequest(string $jsonBody, array $context = []): JsonResponse
    {
        try {
            $request = json_decode($jsonBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $this->error(null, -32700, 'Parse error: ' . $e->getMessage());
        }

        $id = $request['id'] ?? null;
        $method = $request['method'] ?? '';
        $params = $request['params'] ?? [];

        if (!is_string($method) || empty($method)) {
            return $this->error($id, -32600, 'Invalid Request: method required');
        }

        try {
            $result = $this->dispatch($method, $params, $context);
            return $this->success($id, $result);
        } catch (McpException $e) {
            return $this->error($id, $e->getCode(), $e->getMessage());
        } catch (\Throwable $e) {
            return $this->error($id, -32603, 'Internal error: ' . $e->getMessage());
        }
    }

    private function dispatch(string $method, array $params, array $context): mixed
    {
        return match ($method) {
            'initialize' => $this->handleInitialize($params),
            'notifications/initialized' => $this->handleInitialized(),
            'tools/list' => $this->handleToolsList($context),
            'tools/call' => $this->handleToolsCall($params, $context),
            'resources/list' => $this->handleResourcesList($context),
            'resources/read' => $this->handleResourcesRead($params, $context),
            'ping' => ['pong' => true],
            default => throw new McpException("Méthode inconnue: $method", -32601),
        };
    }

    private function handleInitialize(array $params): array
    {
        $this->initialized = true;

        return [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [
                'tools' => ['listChanged' => false],
                'resources' => ['subscribe' => false, 'listChanged' => false],
            ],
            'serverInfo' => [
                'name' => $this->serverName,
                'version' => $this->serverVersion,
            ],
        ];
    }

    private function handleInitialized(): array
    {
        return ['status' => 'ok'];
    }

    private function handleToolsList(array $context): array
    {
        $tools = [];
        foreach ($this->tools as $tool) {
            if ($tool->isAvailable($context)) {
                $tools[] = [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'inputSchema' => $tool->getInputSchema(),
                ];
            }
        }

        return ['tools' => $tools];
    }

    private function handleToolsCall(array $params, array $context): array
    {
        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (!isset($this->tools[$toolName])) {
            throw new McpException("Outil inconnu: $toolName", -32602);
        }

        $tool = $this->tools[$toolName];
        if (!$tool->isAvailable($context)) {
            throw new McpException("Outil non disponible dans ce contexte", -32001);
        }

        $startTime = microtime(true);
        $result = null;
        $status = 'success';
        $errorMsg = null;

        try {
            $result = $tool->execute($arguments, $context);
        } catch (\Throwable $e) {
            $status = 'error';
            $errorMsg = $e->getMessage();
            $result = ['error' => $errorMsg];
        }

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);

        // Audit logging
        if ($this->auditEnabled && $this->audit) {
            try {
                $project = null;
                if (!empty($context['project_uuid'])) {
                    // Le projet peut être résolu par le service appelant
                }
                $user = $context['user'] ?? null;
                $this->audit->log(
                    toolName: $toolName,
                    project: $project,
                    input: $arguments,
                    output: $result,
                    status: $status,
                    errorMessage: $errorMsg,
                    createdBy: $user ? ($user->getUserIdentifier()) : 'system',
                    source: 'mcp',
                    durationMs: $durationMs,
                );
            } catch (\Throwable) {
                // Ne jamais bloquer sur une erreur d'audit
            }
        }

        if ($status === 'error') {
            throw new McpException($errorMsg, -32000);
        }

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                ],
            ],
        ];
    }

    private function handleResourcesList(array $context): array
    {
        $resources = [];
        foreach ($this->resources as $resource) {
            if ($resource->isAvailable($context)) {
                $resources[] = [
                    'uri' => $resource->uri,
                    'name' => $resource->name,
                    'description' => $resource->description,
                    'mimeType' => $resource->mimeType,
                ];
            }
        }

        return ['resources' => $resources];
    }

    private function handleResourcesRead(array $params, array $context): array
    {
        $uri = $params['uri'] ?? '';

        if (!isset($this->resources[$uri])) {
            throw new McpException("Ressource inconnue: $uri", -32602);
        }

        $resource = $this->resources[$uri];
        if (!$resource->isAvailable($context)) {
            throw new McpException("Ressource non disponible", -32001);
        }

        $data = $resource->read($context);

        return [
            'contents' => [
                [
                    'uri' => $uri,
                    'mimeType' => $resource->mimeType,
                    'text' => is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE),
                ],
            ],
        ];
    }

    private function success(mixed $id, mixed $result): JsonResponse
    {
        return new JsonResponse([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ]);
    }

    private function error(mixed $id, int $code, string $message): JsonResponse
    {
        return new JsonResponse([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ]);
    }
}
