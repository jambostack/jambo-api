<?php

namespace App\Controller;

use App\Entity\Project;
use App\Mcp\JamboApiMcpServer;
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

    public function __construct(
        private EntityManagerInterface $em,
        private JamboApiMcpServer $mcpServer,
        private RateLimiterFactory $mcpLimiter,
        private ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $key, array $params = [], string $default = ''): string
    {
        return $this->translator ? $this->translator->trans($key, $params) : ($default ?: $key);
    }

    /**
     * MCP HTTP endpoint - point d'entrée unique pour les clients MCP.
     *
     * Compatible avec Claude Desktop, Claude Code, Cursor, VS Code, etc.
     */
    #[Route('/mcp', name: 'mcp_endpoint', methods: ['POST'])]
    public function handle(Request $request): Response
    {
        // Rate limiting : 60 requêtes par minute par IP
        $limiter = $this->mcpLimiter->create($request->getClientIp());
        if (false === $limiter->consume(1)->isAccepted()) {
            return new JsonResponse(['error' => $this->trans('mcp.rate_limit_exceeded', [], 'Too many MCP requests. Please retry later.')], 429);
        }

        $body = $request->getContent();
        if (strlen($body) > 2 * 1024 * 1024) {
            return new JsonResponse(['error' => $this->trans('mcp.payload_too_large', [], 'Payload too large (max 2MB).')], 413);
        }
        if (trim($body) === '') {
            return new JsonResponse([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32600,
                    'message' => $this->trans('mcp.invalid_request', [], 'Invalid Request: body cannot be empty.'),
                ],
            ], 400);
        }
        $token = $this->extractBearerToken($request);

        // Construire le contexte utilisateur/projet
        $context = $this->buildContext($request, $token);

        return $this->mcpServer->handleRequest($body, $context);
    }

    /**
     * MCP endpoint spécifique à un projet (pratique pour les IDE comme Cursor).
     */
    #[Route('/api/projects/{uuid}/mcp', name: 'mcp_project_endpoint', methods: ['POST'])]
    public function handleProject(string $uuid, Request $request): Response
    {
        // Rate limiting : 60 requêtes par minute par IP
        $limiter = $this->mcpLimiter->create($request->getClientIp());
        if (false === $limiter->consume(1)->isAccepted()) {
            return new JsonResponse(['error' => $this->trans('mcp.rate_limit_exceeded', [], 'Too many MCP requests. Please retry later.')], 429);
        }

        $body = $request->getContent();
        if (strlen($body) > 2 * 1024 * 1024) {
            return new JsonResponse(['error' => $this->trans('mcp.payload_too_large', [], 'Payload too large (max 2MB).')], 413);
        }
        if (trim($body) === '') {
            return new JsonResponse([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32600,
                    'message' => $this->trans('mcp.invalid_request', [], 'Invalid Request: body cannot be empty.'),
                ],
            ], 400);
        }

        if (!preg_match(self::UUID_REGEX, $uuid)) {
            return new JsonResponse(['error' => $this->trans('mcp.project_not_found', [], 'Project not found.')], 404);
        }

        try {
            $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return new JsonResponse(['error' => $this->trans('mcp.project_not_found', [], 'Project not found.')], 404);
        }
        if (!$project) {
            return new JsonResponse(['error' => $this->trans('mcp.project_not_found', [], 'Project not found.')], 404);
        }

        $token = $this->extractBearerToken($request);
        $context = $this->buildContext($request, $token);

        $user = $context['user'] ?? null;
        $isAuthenticated = $context['authenticated'] ?? false;

        // Rejeter si ni utilisateur ni token valide
        if (!$user instanceof \App\Entity\User && !$isAuthenticated) {
            return new JsonResponse(['error' => $this->trans('mcp.auth_required', [], 'Authentication required.')], 401);
        }

        // Si authentifié via ApiToken, vérifier que le token est scopé à ce projet
        if (isset($context['project_uuid']) && $context['project_uuid'] !== $uuid) {
            return new JsonResponse(['error' => $this->trans('mcp.access_denied', [], 'Access denied.')], 403);
        }

        // Si authentifié par session utilisateur ou PAT, vérifier l'appartenance
        if ($user instanceof \App\Entity\User) {
            $isSuperAdmin = in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
            $isMember = $this->em->getRepository(\App\Entity\ProjectMember::class)
                ->findActiveByUserAndProject($user, $project) !== null;
            if (!$isSuperAdmin && !$isMember) {
                return new JsonResponse(['error' => $this->trans('mcp.access_denied', [], 'Access denied.')], 403);
            }
        }

        $context['project_uuid'] = $uuid;
        $context['project_id'] = $project->id;
        $context['_project'] = $project;

        return $this->mcpServer->handleRequest($body, $context);
    }

    /**
     * MCP Server Information (pour découvrir les capacités sans JSON-RPC).
     */
    #[Route('/mcp', name: 'mcp_info', methods: ['GET'])]
    public function info(): JsonResponse
    {
        return new JsonResponse([
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
        ]);
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
        $user = $this->getUser();
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
