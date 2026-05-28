<?php

namespace App\Controller;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Project;
use App\Repository\ContentVersionRepository;
use App\Service\VersioningService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class VersioningController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private VersioningService $versioning,
        private ContentVersionRepository $versionRepo,
    ) {}

    #[Route('/api/projects/{projectUuid}/collections/{collectionSlug}/entries/{uuid}/versions', name: 'versions_list', methods: ['GET'])]
    public function list(string $projectUuid, string $collectionSlug, string $uuid): JsonResponse
    {
        $entry = $this->findEntry($projectUuid, $collectionSlug, $uuid);
        if (!$entry) return $this->json(['error' => 'Entrée introuvable'], 404);

        $this->denyAccessUnlessGranted('project.view', $entry->project);

        $versions = array_map(fn($v) => [
            'uuid' => $v->uuid->toRfc4122(),
            'versionNumber' => $v->versionNumber,
            'label' => $v->label,
            'createdAt' => $v->createdAt->format(\DateTimeInterface::ATOM),
            'createdBy' => $v->createdBy ? ($v->createdBy->name ?: $v->createdBy->email) : null,
        ], $this->versionRepo->findByEntry($entry));

        return $this->json($versions);
    }

    #[Route('/api/projects/{projectUuid}/collections/{collectionSlug}/entries/{uuid}/versions/{versionNumber}/restore', name: 'versions_restore', methods: ['POST'])]
    public function restore(string $projectUuid, string $collectionSlug, string $uuid, int $versionNumber): JsonResponse
    {
        $entry = $this->findEntry($projectUuid, $collectionSlug, $uuid);
        if (!$entry) return $this->json(['error' => 'Entrée introuvable'], 404);

        $this->denyAccessUnlessGranted('content.update', $entry->project);

        $ok = $this->versioning->restoreVersion($entry, $versionNumber);
        if (!$ok) {
            return $this->json(['error' => 'Version introuvable'], 404);
        }

        return $this->json(['success' => true, 'message' => "Restauration à la version $versionNumber effectuée"]);
    }

    #[Route('/api/projects/{projectUuid}/collections/{collectionSlug}/entries/{uuid}/versions/diff', name: 'versions_diff', methods: ['GET'])]
    public function diff(string $projectUuid, string $collectionSlug, string $uuid, Request $request): JsonResponse
    {
        $entry = $this->findEntry($projectUuid, $collectionSlug, $uuid);
        if (!$entry) return $this->json(['error' => 'Entrée introuvable'], 404);

        $this->denyAccessUnlessGranted('project.view', $entry->project);

        $v1 = (int) $request->query->get('v1', 1);
        $v2 = (int) $request->query->get('v2', $this->versionRepo->getNextVersionNumber($entry) - 1);

        return $this->json($this->versioning->diff($entry, $v1, $v2));
    }

    private function findEntry(string $projectUuid, string $collectionSlug, string $uuid): ?ContentEntry
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $projectUuid]);
        if (!$project) return null;

        $collection = $this->em->getRepository(Collection::class)->findOneBy([
            'project' => $project, 'slug' => $collectionSlug, 'deletedAt' => null,
        ]);
        if (!$collection) return null;

        return $this->em->getRepository(ContentEntry::class)->findOneBy([
            'collection' => $collection, 'uuid' => $uuid, 'deletedAt' => null,
        ]);
    }
}
