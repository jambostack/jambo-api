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
use Symfony\Component\RateLimiter\RateLimiterFactory;
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
        private readonly ?RateLimiterFactory $previewLimiter = null,
    ) {}

    /**
     * Genere un token de preview pour une entree.
     * Protege par l'auth admin Symfony standard.
     */
    #[Route('/token/{entryUuid}', name: 'token', methods: ['GET'])]
    public function token(string $projectUuid, string $entryUuid, Request $request): JsonResponse
    {
        if ($this->previewLimiter !== null) {
            $limiter = $this->previewLimiter->create($request->getClientIp() ?: '127.0.0.1');
            if (false === $limiter->consume(1)->isAccepted()) {
                return $this->json(['error' => 'Too many preview requests. Try again later.'], 429);
            }
        }

        if (!$this->getUser()) {
            return $this->json(['error' => 'Authentication required'], 401);
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->json(['error' => 'Project not found'], 404);
        }
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        if ($request->attributes->get('_project') !== $project) {
            $this->denyAccessUnlessGranted('project.view', $project);
        }

        try {
            $entry = $this->entryRepository->findOneBy(['uuid' => $entryUuid, 'project' => $project]);
        } catch (\Throwable) {
            return $this->json(['error' => 'Entry not found'], 404);
        }
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
        if ($this->previewLimiter !== null) {
            $limiter = $this->previewLimiter->create($request->getClientIp() ?: '127.0.0.1');
            if (false === $limiter->consume(1)->isAccepted()) {
                return $this->json(['error' => 'Too many preview requests. Try again later.'], 429);
            }
        }

        // Extraire le token du header Authorization ou des query params (?token= ou ?preview_token=)
        $token = null;
        $authHeader = $request->headers->get('Authorization');
        if ($authHeader !== null && str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
        } elseif ($request->query->has('token')) {
            $token = (string) $request->query->get('token');
        } elseif ($request->query->has('preview_token')) {
            $token = (string) $request->query->get('preview_token');
        }

        if ($token === null || trim($token) === '') {
            return $this->json(['error' => 'Missing or malformed Authorization header or preview token parameter'], 401);
        }

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

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->json(['error' => 'Project not found'], 404);
        }
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        // Verifier que la preview est activee pour ce projet
        if (!$project->previewEnabled) {
            return $this->json(['error' => 'Preview not enabled for this project'], 403);
        }

        try {
            $entry = $this->entryRepository->findOneBy(['uuid' => $entryUuid, 'project' => $project]);
        } catch (\Throwable) {
            return $this->json(['error' => 'Entry not found'], 404);
        }
        if (!$entry || $entry->isDeleted()) {
            return $this->json(['error' => 'Entry not found'], 404);
        }

        return $this->json($this->formatter->formatEntry($entry));
    }
}
