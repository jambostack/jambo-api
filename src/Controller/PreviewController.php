<?php

namespace App\Controller;

use App\Entity\ContentEntry;
use App\Entity\Project;
use App\Repository\ContentEntryRepository;
use App\Repository\ProjectRepository;
use App\Service\EavDataFormatterService;
use App\Service\PreviewTokenService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints pour le systeme de Live Preview.
 *
 * /token/{entryUuid}   — genere un JWT de preview (appele par l'admin)
 * /content/{col}/{eid}  — retourne l'entree (meme brouillon) via JWT preview
 */
#[Route('/api/projects/{projectUuid}/preview', name: 'api_preview_')]
class PreviewController extends AbstractController
{
    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly ContentEntryRepository $entryRepository,
        private readonly PreviewTokenService $tokenService,
        private readonly EavDataFormatterService $formatter,
    ) {}

    /**
     * Genere un token de preview pour une entree.
     * Protege par l'auth admin Symfony standard.
     */
    #[Route('/token/{entryUuid}', name: 'token', methods: ['GET'])]
    public function token(string $projectUuid, string $entryUuid): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $this->denyAccessUnlessGranted('project.view', $project);

        $entry = $this->entryRepository->findOneBy(['uuid' => $entryUuid, 'project' => $project]);
        if (!$entry || $entry->isDeleted()) {
            return $this->json(['error' => 'Entry not found'], 404);
        }

        $token = $this->tokenService->createToken($entry);

        return $this->json([
            'token'      => $token,
            'expires_in' => 3600,
        ]);
    }

    /**
     * Retourne une entree via le token de preview.
     * Permet d'acceder aux brouillons sans auth admin standard.
     */
    #[Route('/content/{collection}/{entryUuid}', name: 'content', methods: ['GET'])]
    public function content(string $projectUuid, string $collection, string $entryUuid, Request $request): JsonResponse
    {
        // Extraire le token du header Authorization
        $authHeader = $request->headers->get('Authorization');
        if ($authHeader === null || !str_starts_with($authHeader, 'Bearer ')) {
            return $this->json(['error' => 'Missing or malformed Authorization header'], 401);
        }

        $token = substr($authHeader, 7);
        $claims = $this->tokenService->validateToken($token);

        if ($claims === null) {
            return $this->json(['error' => 'Invalid or expired preview token'], 401);
        }

        // Verifier que le token correspond a l'URL
        if ($claims['pid'] !== $projectUuid) {
            return $this->json(['error' => 'Token does not match project'], 403);
        }
        if ($claims['eid'] !== $entryUuid) {
            return $this->json(['error' => 'Token does not match entry'], 403);
        }
        if ($claims['col'] !== $collection) {
            return $this->json(['error' => 'Token does not match collection'], 403);
        }

        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $entry = $this->entryRepository->findOneBy(['uuid' => $entryUuid, 'project' => $project]);
        if (!$entry || $entry->isDeleted()) {
            return $this->json(['error' => 'Entry not found'], 404);
        }

        return $this->json($this->formatter->formatEntry($entry));
    }
}
