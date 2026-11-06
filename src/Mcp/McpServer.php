<?php

namespace App\Mcp;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\Translation\TranslatorInterface;

class McpServer
{
    public const SECURITY_HEADERS = [
        'Cache-Control'             => 'no-store, no-cache, must-revalidate, private',
        'Pragma'                    => 'no-cache',
        'X-Content-Type-Options'    => 'nosniff',
        'X-Frame-Options'           => 'DENY',
        'Referrer-Policy'           => 'no-referrer',
        'Content-Security-Policy'   => "default-src 'none'; frame-ancestors 'none';",
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
    ];

    private const MAX_ARGUMENTS_COUNT = 50;
    private const MAX_ARG_STRING_LENGTH = 250_000;
    private const MAX_ARG_DEPTH = 6;

    /** @var McpTool[] */
    protected array $tools = [];

    /** @var McpResource[] */
    protected array $resources = [];

    private string $serverName;
    private string $serverVersion = '1.0.0';
    private bool $initialized = false;
    private ?\App\Service\AuditService $audit = null;
    private bool $auditEnabled = false;

    public function __construct(
        string $name,
        string $version = '1.0.0',
        protected ?TranslatorInterface $translator = null,
    ) {
        $this->serverName = $name;
        $this->serverVersion = $version;
    }

    public function setTranslator(?TranslatorInterface $translator): void
    {
        $this->translator = $translator;
    }

    protected function trans(string $key, array $params = [], string $default = ''): string
    {
        return $this->translator ? $this->translator->trans($key, $params) : ($default ?: $key);
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
    public function handleRequest(string $jsonBody, array $context = [], array $extraHeaders = []): JsonResponse
    {
        try {
            $request = json_decode($jsonBody, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return $this->error(null, -32700, $this->trans('mcp.parse_error', ['%error%' => $e->getMessage()], 'Parse error: ' . $e->getMessage()), $extraHeaders);
        }

        if (!is_array($request) || (array_is_list($request) && !empty($request))) {
            return $this->error(null, -32600, $this->trans('mcp.invalid_root', [], 'Invalid Request: root must be a JSON object.'), $extraHeaders);
        }

        $id = $request['id'] ?? null;
        if ($id !== null && !is_string($id) && !is_int($id)) {
            return $this->error(null, -32600, $this->trans('mcp.invalid_id', [], 'Invalid Request: id must be a string, integer or null.'), $extraHeaders);
        }
        if (is_string($id) && strlen($id) > 256) {
            return $this->error(null, -32600, $this->trans('mcp.id_too_long', [], 'Invalid Request: id exceeds maximum length.'), $extraHeaders);
        }

        if (isset($request['jsonrpc']) && $request['jsonrpc'] !== '2.0') {
            return $this->error($id, -32600, $this->trans('mcp.invalid_version', [], 'Invalid Request: jsonrpc version must be "2.0".'), $extraHeaders);
        }

        $method = $request['method'] ?? '';
        if (!is_string($method) || empty($method)) {
            return $this->error($id, -32600, $this->trans('mcp.method_required', [], 'Invalid Request: method required.'), $extraHeaders);
        }
        if (strlen($method) > 128) {
            return $this->error($id, -32600, $this->trans('mcp.method_too_long', [], 'Invalid Request: method name too long.'), $extraHeaders);
        }

        $params = $request['params'] ?? [];
        if (!is_array($params)) {
            return $this->error($id, -32600, $this->trans('mcp.invalid_params', [], 'Invalid Request: params must be an object or array.'), $extraHeaders);
        }

        try {
            $result = $this->dispatch($method, $params, $context);
            return $this->success($id, $result, $extraHeaders);
        } catch (McpException $e) {
            return $this->error($id, $e->getCode(), $e->getMessage(), $extraHeaders);
        } catch (\Throwable $e) {
            return $this->error($id, -32603, $this->trans('mcp.internal_error', ['%error%' => $e->getMessage()], 'Internal error: ' . $e->getMessage()), $extraHeaders);
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
            default => throw new McpException($this->trans('mcp.unknown_method', ['%method%' => $method], "Method not found: $method"), -32601),
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
        $toolName = (string) ($params['name'] ?? '');
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if (!isset($this->tools[$toolName])) {
            throw new McpException($this->trans('mcp.unknown_tool', ['%tool%' => $toolName], "Tool not found: $toolName"), -32602);
        }

        $tool = $this->tools[$toolName];
        if (!$tool->isAvailable($context)) {
            throw new McpException($this->trans('mcp.tool_not_available', [], 'Tool not available in this context.'), -32001);
        }

        $this->validateArguments($arguments, $tool->getInputSchema());

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
                $project = $context['_project'] ?? null;
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
            return [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $errorMsg ?? 'Unknown error',
                    ],
                ],
                'isError' => true,
            ];
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
            throw new McpException($this->trans('mcp.unknown_resource', ['%uri%' => $uri], "Resource not found: $uri"), -32602);
        }

        $resource = $this->resources[$uri];
        if (!$resource->isAvailable($context)) {
            throw new McpException($this->trans('mcp.resource_not_available', [], 'Resource not available in this context.'), -32001);
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

    private function validateArguments(array $arguments, array $schema): void
    {
        if (count($arguments) > self::MAX_ARGUMENTS_COUNT) {
            throw new McpException(
                $this->trans('mcp.too_many_arguments', ['%max%' => (string) self::MAX_ARGUMENTS_COUNT], sprintf('Too many arguments provided (max %d).', self::MAX_ARGUMENTS_COUNT)),
                -32602
            );
        }

        $required = $schema['required'] ?? [];
        foreach ($required as $field) {
            if (!array_key_exists($field, $arguments) || $arguments[$field] === null || $arguments[$field] === '') {
                throw new McpException(
                    $this->trans('mcp.missing_argument', ['%arg%' => $field], "Missing required argument: {$field}."),
                    -32602
                );
            }
        }

        foreach ($arguments as $key => $val) {
            $this->validateArgumentValue((string) $key, $val, 1);
        }
    }

    private function validateArgumentValue(string $key, mixed $value, int $depth): void
    {
        if ($depth > self::MAX_ARG_DEPTH) {
            throw new McpException(
                $this->trans('mcp.argument_depth_exceeded', ['%arg%' => $key], "Argument '{$key}' exceeds maximum nesting depth."),
                -32602
            );
        }

        if (is_string($value)) {
            if (strlen($value) > self::MAX_ARG_STRING_LENGTH) {
                throw new McpException(
                    $this->trans('mcp.argument_too_large', ['%arg%' => $key], "Argument '{$key}' exceeds maximum allowed size."),
                    -32602
                );
            }
        } elseif (is_array($value)) {
            if (count($value) > 200) {
                throw new McpException(
                    $this->trans('mcp.argument_too_large', ['%arg%' => $key], "Argument '{$key}' contains too many elements."),
                    -32602
                );
            }
            foreach ($value as $subKey => $subVal) {
                $this->validateArgumentValue($key . '.' . $subKey, $subVal, $depth + 1);
            }
        }
    }

    private function success(mixed $id, mixed $result, array $extraHeaders = []): JsonResponse
    {
        return new JsonResponse([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ], 200, array_merge(self::SECURITY_HEADERS, $extraHeaders));
    }

    private function error(mixed $id, int $code, string $message, array $extraHeaders = []): JsonResponse
    {
        return new JsonResponse([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], 200, array_merge(self::SECURITY_HEADERS, $extraHeaders));
    }
}
