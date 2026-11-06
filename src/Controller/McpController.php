<?php

namespace App\Controller;

use App\Entity\Project;
use App\Mcp\JamboApiMcpServer;
use App\Service\AuditService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class McpController extends AbstractController
{
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const SECURITY_HEADERS = [
        'Cache-Control'             => 'no-store, no-cache, must-revalidate, private',
        'Pragma'                    => 'no-cache',
        'X-Content-Type-Options'    => 'nosniff',
        'X-Frame-Options'           => 'DENY',
        'Referrer-Policy'           => 'no-referrer',
        'Content-Security-Policy'   => "default-src 'none'; frame-ancestors 'none';",
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private JamboApiMcpServer $mcpServer,
        private RateLimiterFactory $mcpLimiter,
        private ?TranslatorInterface $translator = null,
        private ?AuditService $auditService = null,
    ) {
        if ($this->auditService !== null) {
            $this->mcpServer->setAuditService($this->auditService);
        }
        if ($this->translator !== null) {
            $this->mcpServer->setTranslator($this->translator);
        }
    }

    private function trans(string $key, array $params = [], string $default = ''): string
    {
        return $this->translator ? $this->translator->trans($key, $params) : ($default ?: $key);
    }

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    private function resolveIdentifier(Request $request, ?array $context = null): string
    {
        $user = $context['user'] ?? null;
        if ($user instanceof \App\Entity\User) {
            return 'user_' . $user->getUserIdentifier();
        }
        if (isset($context['project_uuid'])) {
            return 'token_project_' . $context['project_uuid'];
        }
        return $request->getClientIp() ?: '127.0.0.1';
    }

    /**
     * MCP HTTP endpoint - point d'entrée unique pour les clients MCP.
     *
     * Compatible avec Claude Desktop, Claude Code, Cursor, VS Code, etc.
     */
    #[Route('/mcp', name: 'mcp_endpoint', methods: ['POST'])]
    public function handle(Request $request): Response
    {
        $contentType = (string) $request->headers->get('Content-Type', '');
        if ($contentType !== '' && !str_contains(strtolower($contentType), 'application/json')) {
            return $this->secureJson([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32700,
                    'message' => $this->trans('mcp.unsupported_content_type', [], 'Unsupported Media Type: Content-Type must be application/json.'),
                ],
            ], 415);
        }

        $token = $this->extractBearerToken($request);
        $context = $this->buildContext($request, $token);
        $identifier = $this->resolveIdentifier($request, $context);

        // Rate limiting : 60 requêtes par minute par IP ou identifiant
        $rateLimit = null;
        if ($this->mcpLimiter !== null) {
            $limiter = $this->mcpLimiter->create($identifier);
            $rateLimit = $limiter->consume(1);
            if (!$rateLimit->isAccepted()) {
                $retryAfter = (string) ceil(max(1, $rateLimit->getRetryAfter()->getTimestamp() - time()));
                $this->auditService?->log(
                    toolName: 'mcp_rate_limit_exceeded',
                    project: $context['_project'] ?? null,
                    input: ['identifier' => $identifier],
                    output: null,
                    status: 'failed',
                    errorMessage: 'Rate limit exceeded for MCP endpoint',
                    createdBy: $identifier,
                    source: 'mcp_controller',
                );
                return $this->secureJson(['error' => $this->trans('mcp.rate_limit_exceeded', [], 'Too many MCP requests. Please retry later.')], 429, [
                    'Retry-After' => $retryAfter,
                    'X-RateLimit-Limit' => (string) $rateLimit->getLimit(),
                    'X-RateLimit-Remaining' => '0',
                ]);
            }
        }

        $body = $request->getContent();
        if (strlen($body) > 2 * 1024 * 1024) {
            $this->auditService?->log(
                toolName: 'mcp_payload_too_large',
                project: $context['_project'] ?? null,
                input: ['size' => strlen($body)],
                output: null,
                status: 'failed',
                errorMessage: 'Payload too large (max 2MB)',
                createdBy: $identifier,
                source: 'mcp_controller',
            );
            return $this->secureJson(['error' => $this->trans('mcp.payload_too_large', [], 'Payload too large (max 2MB).')], 413);
        }
        if (trim($body) === '') {
            return $this->secureJson([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32600,
                    'message' => $this->trans('mcp.invalid_request', [], 'Invalid Request: body cannot be empty.'),
                ],
            ], 400);
        }

        if (str_contains($body, "\0")) {
            return $this->secureJson([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32600,
                    'message' => $this->trans('mcp.invalid_control_characters', [], 'Invalid Request: body contains invalid control characters.'),
                ],
            ], 400);
        }

        $extraHeaders = [];
        if ($rateLimit !== null) {
            $extraHeaders['X-RateLimit-Limit'] = (string) $rateLimit->getLimit();
            $extraHeaders['X-RateLimit-Remaining'] = (string) $rateLimit->getRemainingTokens();
        }

        return $this->mcpServer->handleRequest($body, $context, $extraHeaders);
    }

    /**
     * MCP endpoint spécifique à un projet (pratique pour les IDE comme Cursor).
     */
    #[Route('/api/projects/{uuid}/mcp', name: 'mcp_project_endpoint', methods: ['POST'])]
    public function handleProject(string $uuid, Request $request): Response
    {
        $contentType = (string) $request->headers->get('Content-Type', '');
        if ($contentType !== '' && !str_contains(strtolower($contentType), 'application/json')) {
            return $this->secureJson([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32700,
                    'message' => $this->trans('mcp.unsupported_content_type', [], 'Unsupported Media Type: Content-Type must be application/json.'),
                ],
            ], 415);
        }

        $token = $this->extractBearerToken($request);
        $context = $this->buildContext($request, $token);
        $identifier = $this->resolveIdentifier($request, $context);

        // Rate limiting : 60 requêtes par minute par IP ou identifiant
        $rateLimit = null;
        if ($this->mcpLimiter !== null) {
            $limiter = $this->mcpLimiter->create($identifier);
            $rateLimit = $limiter->consume(1);
            if (!$rateLimit->isAccepted()) {
                $retryAfter = (string) ceil(max(1, $rateLimit->getRetryAfter()->getTimestamp() - time()));
                $this->auditService?->log(
                    toolName: 'mcp_rate_limit_exceeded',
                    project: null,
                    input: ['uuid' => $uuid, 'identifier' => $identifier],
                    output: null,
                    status: 'failed',
                    errorMessage: 'Rate limit exceeded for project MCP endpoint',
                    createdBy: $identifier,
                    source: 'mcp_controller',
                );
                return $this->secureJson(['error' => $this->trans('mcp.rate_limit_exceeded', [], 'Too many MCP requests. Please retry later.')], 429, [
                    'Retry-After' => $retryAfter,
                    'X-RateLimit-Limit' => (string) $rateLimit->getLimit(),
                    'X-RateLimit-Remaining' => '0',
                ]);
            }
        }

        $body = $request->getContent();
        if (strlen($body) > 2 * 1024 * 1024) {
            $this->auditService?->log(
                toolName: 'mcp_payload_too_large',
                project: null,
                input: ['uuid' => $uuid, 'size' => strlen($body)],
                output: null,
                status: 'failed',
                errorMessage: 'Payload too large (max 2MB)',
                createdBy: $identifier,
                source: 'mcp_controller',
            );
            return $this->secureJson(['error' => $this->trans('mcp.payload_too_large', [], 'Payload too large (max 2MB).')], 413);
        }
        if (trim($body) === '') {
            return $this->secureJson([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32600,
                    'message' => $this->trans('mcp.invalid_request', [], 'Invalid Request: body cannot be empty.'),
                ],
            ], 400);
        }

        if (str_contains($body, "\0")) {
            return $this->secureJson([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32600,
                    'message' => $this->trans('mcp.invalid_control_characters', [], 'Invalid Request: body contains invalid control characters.'),
                ],
            ], 400);
        }

        if (!preg_match(self::UUID_REGEX, $uuid)) {
            return $this->secureJson(['error' => $this->trans('mcp.project_not_found', [], 'Project not found.')], 404);
        }

        try {
            $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('mcp.project_not_found', [], 'Project not found.')], 404);
        }
        if (!$project) {
            return $this->secureJson(['error' => $this->trans('mcp.project_not_found', [], 'Project not found.')], 404);
        }

        $user = $context['user'] ?? null;
        $isAuthenticated = $context['authenticated'] ?? false;

        // Rejeter si ni utilisateur ni token valide
        if (!$user instanceof \App\Entity\User && !$isAuthenticated) {
            $this->auditService?->log(
                toolName: 'mcp_auth_failure',
                project: $project,
                input: ['uuid' => $uuid],
                output: null,
                status: 'failed',
                errorMessage: 'Authentication required for project MCP endpoint',
                createdBy: 'anonymous',
                source: 'mcp_controller',
            );
            return $this->secureJson(['error' => $this->trans('mcp.auth_required', [], 'Authentication required.')], 401);
        }

        // Si authentifié via ApiToken, vérifier que le token est scopé à ce projet
        if (isset($context['project_uuid']) && $context['project_uuid'] !== $uuid) {
            $this->auditService?->log(
                toolName: 'mcp_access_denied',
                project: $project,
                input: ['uuid' => $uuid, 'token_project' => $context['project_uuid']],
                output: null,
                status: 'failed',
                errorMessage: 'Token project mismatch for project MCP endpoint',
                createdBy: $user?->getUserIdentifier() ?? 'token',
                source: 'mcp_controller',
            );
            return $this->secureJson(['error' => $this->trans('mcp.access_denied', [], 'Access denied.')], 403);
        }

        // Si authentifié par session utilisateur ou PAT, vérifier l'appartenance
        if ($user instanceof \App\Entity\User) {
            $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
            $isMember = $this->em->getRepository(\App\Entity\ProjectMember::class)
                ->findActiveByUserAndProject($user, $project) !== null;
            if (!$isSuperAdmin && !$isMember) {
                $this->auditService?->log(
                    toolName: 'mcp_access_denied',
                    project: $project,
                    input: ['uuid' => $uuid],
                    output: null,
                    status: 'failed',
                    errorMessage: 'User is not a member of project',
                    createdBy: $user->getUserIdentifier(),
                    source: 'mcp_controller',
                );
                return $this->secureJson(['error' => $this->trans('mcp.access_denied', [], 'Access denied.')], 403);
            }
        }

        $context['project_uuid'] = $uuid;
        $context['project_id'] = $project->id;
        $context['_project'] = $project;

        $extraHeaders = [];
        if ($rateLimit !== null) {
            $extraHeaders['X-RateLimit-Limit'] = (string) $rateLimit->getLimit();
            $extraHeaders['X-RateLimit-Remaining'] = (string) $rateLimit->getRemainingTokens();
        }

        return $this->mcpServer->handleRequest($body, $context, $extraHeaders);
    }

    /**
     * MCP Server Information (pour découvrir les capacités sans JSON-RPC).
     */
    #[Route('/mcp', name: 'mcp_info', methods: ['GET'])]
    public function info(?Request $request = null): JsonResponse
    {
        $headers = [];
        if ($this->mcpLimiter !== null && $request !== null) {
            $identifier = $this->resolveIdentifier($request);
            $limiter = $this->mcpLimiter->create($identifier);
            $rateLimit = $limiter->consume(1);
            if (!$rateLimit->isAccepted()) {
                $retryAfter = (string) ceil(max(1, $rateLimit->getRetryAfter()->getTimestamp() - time()));
                return $this->secureJson(['error' => $this->trans('mcp.rate_limit_exceeded', [], 'Too many MCP requests. Please retry later.')], 429, [
                    'Retry-After' => $retryAfter,
                    'X-RateLimit-Limit' => (string) $rateLimit->getLimit(),
                    'X-RateLimit-Remaining' => '0',
                ]);
            }
            $headers['X-RateLimit-Limit'] = (string) $rateLimit->getLimit();
            $headers['X-RateLimit-Remaining'] = (string) $rateLimit->getRemainingTokens();
        }
        return $this->secureJson([
            'name' => 'JamboApi MCP Server',
            'version' => '2.0.0',
            'protocolVersion' => '2024-11-05',
            'vendor' => 'JamboApi CMS',
            'endpoints' => [
                'http' => '/mcp',
                'project' => '/api/projects/{uuid}/mcp',
            ],
            'authentication' => [
                'type' => 'bearer_token',
                'description' => $this->trans('mcp.auth_description', [], 'Use a JamboApi API token in the Authorization: Bearer <token> header.'),
            ],
            'capabilities' => [
                'tools' => true,
                'resources' => true,
            ],
        ], 200, $headers);
    }

    private function extractBearerToken(Request $request): ?string
    {
        $authHeader = $request->headers->get('Authorization');
        if ($authHeader && preg_match('/^Bearer\s+([a-zA-Z0-9_\-\.=]{10,2048})$/i', trim($authHeader), $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    private function buildContext(Request $request, ?string $token): array
    {
        $user = null;
        try {
            $user = $this->getUser();
        } catch (\Throwable) {}

        $context = [
            'user' => $user,
            'authenticated' => $user instanceof \App\Entity\User,
        ];

        // PAT user-scopé (Admin API) : authentifie en tant qu'utilisateur + scopes.
        if ($token && str_starts_with($token, 'jbo_pat_')) {
            $pat = $this->em->getRepository(\App\Entity\PersonalAccessToken::class)
                ->findByPlainToken($token);
            if ($pat && !$pat->isExpired()) {
                $context['user'] = $pat->user;
                $context['scopes'] = $pat->scopes;
                $context['authenticated'] = true;
            }

            return $context;
        }

        // Si un token API est fourni, trouver le projet associé
        if ($token) {
            $apiToken = $this->em->getRepository(\App\Entity\ApiToken::class)
                ->findByPlainToken($token);
            if ($apiToken && !$apiToken->isExpired()) {
                $context['project_uuid'] = $apiToken->project?->uuid?->toRfc4122();
                $context['project_id'] = $apiToken->project?->id;
                $context['_project'] = $apiToken->project;
                $context['token_abilities'] = $apiToken->abilities;
                $context['authenticated'] = true;
            }
        }

        return $context;
    }
}
