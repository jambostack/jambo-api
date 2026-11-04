<?php

namespace App\Controller;

use App\Entity\MediaFolder;
use App\Repository\MediaFolderRepository;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;

#[Route('/api/projects/{projectUuid}/media-folders', name: 'api_media_folder_')]
class MediaFolderController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projectRepository,
        private MediaFolderRepository $folderRepository,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $projectUuid): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $folders = $this->folderRepository->findByProject($project);

        return $this->json([
            'data' => array_map(fn (MediaFolder $f) => $this->serializeFolder($f), $folders),
        ]);
    }

    #[Route('/tree', name: 'tree', methods: ['GET'])]
    public function tree(string $projectUuid): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $roots = $this->folderRepository->findTree($project);

        return $this->json([
            'data' => array_map(fn (MediaFolder $f) => $this->serializeTree($f), $roots),
        ]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }
        $name = trim($data['name'] ?? '');
        if ($name === '') {
            return $this->json(['error' => 'Name is required'], 422);
        }

        $folder = new MediaFolder();
        $folder->name = $name;
        $folder->slug = (new AsciiSlugger())->slug($name)->lower()->toString();
        $folder->project = $project;

        // Position après le dernier dossier du même niveau
        $parentId = $data['parent_id'] ?? null;
        if ($parentId !== null) {
            $parent = $this->folderRepository->findOneBy(['id' => (int) $parentId, 'project' => $project]);
            if (!$parent) {
                return $this->json(['error' => 'Parent folder not found'], 404);
            }
            $folder->parent = $parent;
        }

        // Calculer la position max parmi les siblings
        $all = $this->folderRepository->findByProject($project);
        $maxPos = 0;
        foreach ($all as $f) {
            $fParentId = $f->parent?->id;
            if ($fParentId === ($folder->parent?->id)) {
                if ($f->position > $maxPos) {
                    $maxPos = $f->position;
                }
            }
        }
        $folder->position = $maxPos + 1;

        $this->em->persist($folder);
        $this->em->flush();

        return $this->json(['data' => $this->serializeFolder($folder)], 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(string $projectUuid, int $id, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $folder = $this->folderRepository->findOneBy(['id' => $id, 'project' => $project]);
        if (!$folder || $folder->isDeleted()) {
            return $this->json(['error' => 'Folder not found'], 404);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }
        if (!empty($data['name'])) {
            $folder->name = trim($data['name']);
            $folder->slug = (new AsciiSlugger())->slug($folder->name)->lower()->toString();
        }

        $this->em->flush();

        return $this->json(['data' => $this->serializeFolder($folder)]);
    }

    #[Route('/{id}/move', name: 'move', methods: ['PATCH'])]
    public function move(string $projectUuid, int $id, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $folder = $this->folderRepository->findOneBy(['id' => $id, 'project' => $project]);
        if (!$folder || $folder->isDeleted()) {
            return $this->json(['error' => 'Folder not found'], 404);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }
        if (!array_key_exists('parent_id', $data)) {
            return $this->json(['error' => 'parent_id is required'], 422);
        }

        $parentId = $data['parent_id'];
        if ($parentId !== null) {
            // Empêcher le déplacement vers soi-même
            if ((int) $parentId === $folder->id) {
                return $this->json(['error' => 'Cannot move a folder into itself'], 422);
            }
            $parent = $this->folderRepository->findOneBy(['id' => (int) $parentId, 'project' => $project]);
            if (!$parent) {
                return $this->json(['error' => 'Parent folder not found'], 404);
            }

            // Empêcher le déplacement vers un descendant (boucle cyclique)
            $ancestor = $parent;
            while ($ancestor !== null) {
                if ($ancestor->id === $folder->id) {
                    return $this->json(['error' => 'Cannot move a folder into one of its descendants'], 422);
                }
                $ancestor = $ancestor->parent;
            }

            $folder->parent = $parent;
        } else {
            $folder->parent = null;
        }

        $this->em->flush();

        return $this->json(['data' => $this->serializeFolder($folder)]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $projectUuid, int $id): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $folder = $this->folderRepository->findOneBy(['id' => $id, 'project' => $project]);
        if (!$folder || $folder->isDeleted()) {
            return $this->json(['error' => 'Folder not found'], 404);
        }

        // Soft-delete : les enfants et les médias restent, leur FK est SET NULL
        $folder->deletedAt = new \DateTimeImmutable();
        $this->em->flush();

        return $this->json(null, 204);
    }

    private function resolveProject(string $projectUuid): ?\App\Entity\Project
    {
        try {
            return $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function serializeFolder(MediaFolder $folder): array
    {
        return [
            'id'         => $folder->id,
            'name'       => $folder->name,
            'slug'       => $folder->slug,
            'position'   => $folder->position,
            'parent_id'  => $folder->parent?->id,
            'created_at' => $folder->createdAt->format('c'),
            'updated_at' => $folder->updatedAt->format('c'),
        ];
    }

    private function serializeTree(MediaFolder $folder): array
    {
        $data = $this->serializeFolder($folder);
        $children = $folder->getChildren()->toArray();
        // Filtrer les enfants soft-deleted
        $children = array_filter($children, fn (MediaFolder $f) => !$f->isDeleted());
        usort($children, fn (MediaFolder $a, MediaFolder $b) => $a->position <=> $b->position);
        $data['children'] = array_map(fn (MediaFolder $f) => $this->serializeTree($f), $children);

        return $data;
    }
}
