<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Affiche Swagger UI pour un projet Jambo.
 * GET /api/{projectId}/docs
 * GET /api/docs (redirige vers le premier projet si un seul)
 */
#[Route('/api')]
class ApiDocsController extends AbstractController
{
    public function __construct(
        private \App\Repository\ProjectRepository $projectRepository,
    ) {}

    #[Route('/docs/{projectId}', name: 'api_docs_swagger', methods: ['GET'], priority: 30,
        requirements: ['projectId' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function swagger(string $projectId): Response
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectId]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $specUrl = '/api/' . $projectId . '/openapi.json';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$project->name} — API Docs</title>
  <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
  <style>
    html { box-sizing: border-box; overflow-y: scroll; }
    *, *::before, *::after { box-sizing: inherit; }
    body { margin: 0; background: #f9f9f9; }
    .topbar { display: none; }
  </style>
</head>
<body>
  <div id="swagger-ui"></div>
  <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
  <script>
    SwaggerUIBundle({
      url: '{$specUrl}',
      dom_id: '#swagger-ui',
      presets: [SwaggerUIBundle.presets.apis],
      layout: 'BaseLayout',
      deepLinking: true,
      defaultModelsExpandDepth: 1,
      defaultModelExpandDepth: 1,
    });
  </script>
</body>
</html>
HTML;

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
