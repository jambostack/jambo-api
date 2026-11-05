<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Service\ApiTokenChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Affiche Swagger UI pour un projet Jambo.
 * GET /api/{projectId}/docs
 * GET /api/docs (redirige vers le premier projet si un seul)
 */
#[Route('/api')]
class ApiDocsController extends AbstractController
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private ?ProjectMemberRepository $memberRepo = null,
        private ?ApiTokenChecker $tokenChecker = null,
        private ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $key, array $params = [], string $default = ''): string
    {
        return $this->translator ? $this->translator->trans($key, $params) : ($default ?: $key);
    }

    #[Route('/docs/{projectId}', name: 'api_docs_swagger', methods: ['GET'], priority: 30,
        requirements: ['projectId' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function swagger(string $projectId, Request $request): Response
    {
        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectId]);
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('api_docs.project_not_found', [], 'Project not found')], 404);
        }
        if (!$project) {
            return $this->json(['error' => $this->trans('api_docs.project_not_found', [], 'Project not found')], 404);
        }

        // Vérification d'autorisation si l'API du projet n'est pas publique
        if (!$project->publicApi) {
            $isAuthorized = false;
            $user = $this->getUser();
            if ($user instanceof User) {
                if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
                    $isAuthorized = true;
                } elseif ($this->memberRepo && $this->memberRepo->findActiveByUserAndProject($user, $project) !== null) {
                    $isAuthorized = true;
                }
            } elseif ($this->tokenChecker !== null) {
                $token = $this->tokenChecker->resolve($request);
                if ($token !== null && $token->project?->id === $project->id) {
                    $isAuthorized = true;
                }
            }

            if (!$isAuthorized) {
                return $this->json(['error' => $this->trans('api_docs.auth_required', [], 'Authentication required')], 401);
            }
        }

        $specUrl = '/api/' . $projectId . '/openapi.json';
        $escapedTitle = htmlspecialchars($project->name ?? 'Project', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $specUrlJson = json_encode($specUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$escapedTitle} — API Docs</title>
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
      url: {$specUrlJson},
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

        return new Response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ]);
    }
}
