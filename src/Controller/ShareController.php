<?php

namespace App\Controller;

use App\Enum\ShareDuration;
use App\Repository\ContentEntryRepository;
use App\Repository\ProjectRepository;
use App\Repository\ShareRepository;
use App\Service\Share\ShareService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/api/projects/{projectUuid}/shares', name: 'api_shares_')]
class ShareController extends AbstractController
{
    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly ContentEntryRepository $entryRepository,
        private readonly ShareRepository $shareRepository,
        private readonly ShareService $shareService,
    ) {}

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }
        $this->denyAccessUnlessGranted('content.update', $project);

        $data = json_decode($request->getContent(), true) ?? [];
        $entry = $this->entryRepository->findOneBy(['uuid' => $data['entryUuid'] ?? '', 'project' => $project]);
        if (!$entry || $entry->isDeleted()) {
            return $this->json(['error' => 'Entry not found'], 404);
        }

        try {
            $duration = ShareDuration::fromQuery($data['duration'] ?? null);
        } catch (\InvalidArgumentException) {
            return $this->json(['error' => 'Invalid duration'], 400);
        }

        ['share' => $share, 'plainToken' => $plain] = $this->shareService->create($entry, $duration, $this->getUser());

        $url = $this->generateUrl('public_share_show', ['token' => $plain], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->json(['data' => [
            'id'        => $share->id,
            'url'       => $url,
            'expiresAt' => $share->expiresAt?->format('c'),
            'createdAt' => $share->createdAt->format('c'),
        ]], 201);
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }
        $this->denyAccessUnlessGranted('content.update', $project);

        $entry = $this->entryRepository->findOneBy(['uuid' => $request->query->get('entryUuid', ''), 'project' => $project]);
        if (!$entry) {
            return $this->json(['error' => 'Entry not found'], 404);
        }

        $shares = $this->shareRepository->findByEntry($entry);

        return $this->json(['data' => array_map(fn ($s) => [
            'id'             => $s->id,
            'expiresAt'      => $s->expiresAt?->format('c'),
            'revokedAt'      => $s->revokedAt?->format('c'),
            'lastAccessedAt' => $s->lastAccessedAt?->format('c'),
            'viewCount'      => $s->viewCount,
            'createdAt'      => $s->createdAt->format('c'),
            'isValid'        => $s->isValid(),
        ], $shares)]);
    }

    #[Route('/{id}', name: 'destroy', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function destroy(string $projectUuid, int $id): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }
        $this->denyAccessUnlessGranted('content.update', $project);

        $share = $this->shareRepository->find($id);
        if (!$share || $share->project->id !== $project->id) {
            return $this->json(['error' => 'Share not found'], 404);
        }

        $this->shareService->revoke($share);

        return $this->json(null, 204);
    }
}
