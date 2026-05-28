<?php

namespace App\Controller;

use App\Entity\Project;
use App\Mcp\JamboApiMcpServer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class McpController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private JamboApiMcpServer $mcpServer,
    ) {}

    /**
     * MCP HTTP endpoint - point d'entrée unique pour les clients MCP.
     *
     * Compatible avec Claude Desktop, Claude Code, Cursor, VS Code, etc.
     */
    #[Route('/mcp', name: 'mcp_endpoint', methods: ['POST'])]
    public function handle(Request $request): Response
    {
        $body = $request->getContent();

        // Extraire le token d'authentification du header
        $authHeader = $request->headers->get('Authorization');
        $token = null;
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
        }

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
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) {
            return new JsonResponse(['error' => 'Projet introuvable'], 404);
        }

        $body = $request->getContent();
        $context = [
            'project_uuid' => $uuid,
            'project_id' => $project->id,
            'user' => $this->getUser(),
        ];

        return $this->mcpServer->handleRequest($body, $context);
    }

    /**
     * MCP Server Information (pour découvrir les capacités sans JSON-RPC).
     */
    #[Route('/mcp', name: 'mcp_info', methods: ['GET'])]
    public function info(): JsonResponse
    {
        return $this->json([
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
                'description' => 'Utiliser un token API JamboApi dans le header Authorization: Bearer <token>',
            ],
            'capabilities' => [
                'tools' => true,
                'resources' => true,
            ],
        ]);
    }

    private function buildContext(Request $request, ?string $token): array
    {
        $context = ['user' => $this->getUser()];

        // Si un token API est fourni, trouver le projet associé
        if ($token) {
            $apiToken = $this->em->getRepository(\App\Entity\ApiToken::class)
                ->findOneByToken($token);
            if ($apiToken && !$apiToken->isExpired()) {
                $context['project_uuid'] = $apiToken->project?->uuid?->toRfc4122();
                $context['project_id'] = $apiToken->project?->id;
                $context['token_abilities'] = $apiToken->abilities;
                $context['authenticated'] = true;
            }
        }

        return $context;
    }
}
