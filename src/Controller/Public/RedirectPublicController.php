<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Repository\ProjectRepository;
use App\Service\Redirect\RedirectResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class RedirectPublicController extends AbstractController
{
    public function __construct(
        private readonly ProjectRepository $projectRepo,
        private readonly RedirectResolver $redirectResolver,
    ) {}

    #[Route('/{projectUuid}/redirects/resolve', name: 'public_redirect_resolve', requirements: ['projectUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function resolve(string $projectUuid, Request $request): Response
    {
        $project = $this->projectRepo->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return new JsonResponse(['error' => 'Project not found.'], 404);
        }

        $path = $request->query->get('path', '/');
        $redirect = $this->redirectResolver->resolve($path, $project);

        if ($redirect === null) {
            return new JsonResponse(['redirected' => false, 'path' => $path]);
        }

        // Retour en JSON ou en redirect HTTP selon le header Accept
        $acceptHeader = $request->headers->get('Accept', '');
        if (str_contains($acceptHeader, 'text/html') || str_contains($acceptHeader, '*/*')) {
            return new RedirectResponse(
                $redirect->toPath,
                $redirect->httpCode,
            );
        }

        return new JsonResponse([
            'redirected' => true,
            'from' => $redirect->fromPath,
            'to' => $redirect->toPath,
            'httpCode' => $redirect->httpCode,
        ]);
    }
}
