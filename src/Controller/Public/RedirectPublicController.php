<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Repository\ProjectRepository;
use App\Service\Redirect\RedirectResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class RedirectPublicController extends AbstractController
{
    public function __construct(
        private readonly ProjectRepository $projectRepo,
        private readonly RedirectResolver $redirectResolver,
    ) {}

    #[Route('/{projectUuid}/redirects/resolve', name: 'public_redirect_resolve', requirements: ['projectUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    #[Route('/api/projects/{projectUuid}/redirects/resolve', name: 'api_public_redirect_resolve')]
    public function resolve(string $projectUuid, Request $request): Response
    {
        try {
            $project = $this->projectRepo->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Project not found.'], 404);
        }
        if (!$project) {
            return new JsonResponse(['error' => 'Project not found.'], 404);
        }

        $path = $request->query->get('path', '/');
        $redirect = $this->redirectResolver->resolve($path, $project);

        if ($redirect === null) {
            return new JsonResponse(['redirected' => false, 'path' => $path]);
        }

        // Retour en JSON ou en redirect HTTP selon le header Accept
        $acceptHeader = (string) $request->headers->get('Accept', '');
        $wantsJson = str_contains($acceptHeader, 'application/json') || $request->query->getBoolean('json');
        if (!$wantsJson && str_contains($acceptHeader, 'text/html')) {
            $dest = $redirect->toPath;
            $lowerDest = strtolower($dest);
            if (
                str_starts_with($lowerDest, 'javascript:') ||
                str_starts_with($lowerDest, 'data:') ||
                str_starts_with($lowerDest, 'vbscript:') ||
                str_starts_with($dest, '//')
            ) {
                return new JsonResponse(['error' => 'Unsafe redirect destination.'], 400);
            }

            return new RedirectResponse(
                $dest,
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
