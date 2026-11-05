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
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/projects/{projectUuid}/media-folders', name: 'api_media_folder_')]
class MediaFolderController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projectRepository,
        private MediaFolderRepository $folderRepository,
        private ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters);
        }
        if ($default !== null) {
            return strtr($default, $parameters);
        }
        return $id;
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $projectUuid): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return $this->json(['error' => $this->trans('media_folder.project_not_found', [], 'Project not found')], 404);
        }
        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('project.view', $project);
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
            return $this->json(['error' => $this->trans('media_folder.project_not_found', [], 'Project not found')], 404);
        }
        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('project.view', $project);
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
            return $this->json(['error' => $this->trans('media_folder.project_not_found', [], 'Project not found')], 404);
        }
        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.create', $project);
        }

        if (strlen($request->getContent()) > 16384) {
            return $this->json(['error' => $this->trans('media_folder.payload_too_large', [], 'Payload too large')], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('media_folder.invalid_json', [], 'Invalid JSON body')], 400);
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return $this->json(['error' => $this->trans('media_folder.name_required', [], 'Name is required')], 422);
        }
        if (mb_strlen($name) > 150) {
            return $this->json(['error' => $this->trans('media_folder.name_too_long', [], 'Name cannot exceed 150 characters')], 422);
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
            return $this->json(['error' => $this->trans('media_folder.name_control_characters', [], 'Name contains forbidden control characters')], 422);
        }
        if (str_contains($name, '..') || str_contains($name, '/') || str_contains($name, '\\')) {
            return $this->json(['error' => $this->trans('media_folder.name_invalid_traversal', [], 'Name cannot contain path traversal sequences or slashes')], 422);
        }

        $folder = new MediaFolder();
        $folder->name = $name;
        $folder->slug = (new AsciiSlugger())->slug($name)->lower()->toString();
        $folder->project = $project;

        // Position après le dernier dossier du même niveau
        $parentId = $data['parent_id'] ?? null;
        if ($parentId !== null) {
            if (!is_numeric($parentId) || (int) $parentId <= 0) {
                return $this->json(['error' => $this->trans('media_folder.invalid_parent_id', [], 'Invalid parent folder ID')], 422);
            }
            $parent = $this->folderRepository->findOneBy(['id' => (int) $parentId, 'project' => $project]);
            if (!$parent || $parent->isDeleted()) {
                return $this->json(['error' => $this->trans('media_folder.parent_not_found', [], 'Parent folder not found')], 404);
            }

            // Hierarchy depth check (prevent unbounded tree nesting)
            $depth = 1;
            $ancestor = $parent;
            while ($ancestor !== null) {
                $depth++;
                if ($depth > 15) {
                    return $this->json(['error' => $this->trans('media_folder.max_depth_exceeded', [], 'Maximum folder hierarchy depth exceeded')], 422);
                }
                $ancestor = $ancestor->parent;
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
            return $this->json(['error' => $this->trans('media_folder.project_not_found', [], 'Project not found')], 404);
        }
        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.update', $project);
        }

        $folder = $this->folderRepository->findOneBy(['id' => $id, 'project' => $project]);
        if (!$folder || $folder->isDeleted()) {
            return $this->json(['error' => $this->trans('media_folder.not_found', [], 'Folder not found')], 404);
        }

        if (strlen($request->getContent()) > 16384) {
            return $this->json(['error' => $this->trans('media_folder.payload_too_large', [], 'Payload too large')], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('media_folder.invalid_json', [], 'Invalid JSON body')], 400);
        }
        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return $this->json(['error' => $this->trans('media_folder.name_empty', [], 'Name cannot be empty')], 422);
            }
            if (mb_strlen($name) > 150) {
                return $this->json(['error' => $this->trans('media_folder.name_too_long', [], 'Name cannot exceed 150 characters')], 422);
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
                return $this->json(['error' => $this->trans('media_folder.name_control_characters', [], 'Name contains forbidden control characters')], 422);
            }
            if (str_contains($name, '..') || str_contains($name, '/') || str_contains($name, '\\')) {
                return $this->json(['error' => $this->trans('media_folder.name_invalid_traversal', [], 'Name cannot contain path traversal sequences or slashes')], 422);
            }
            $folder->name = $name;
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
            return $this->json(['error' => $this->trans('media_folder.project_not_found', [], 'Project not found')], 404);
        }
        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.update', $project);
        }

        $folder = $this->folderRepository->findOneBy(['id' => $id, 'project' => $project]);
        if (!$folder || $folder->isDeleted()) {
            return $this->json(['error' => $this->trans('media_folder.not_found', [], 'Folder not found')], 404);
        }

        if (strlen($request->getContent()) > 16384) {
            return $this->json(['error' => $this->trans('media_folder.payload_too_large', [], 'Payload too large')], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('media_folder.invalid_json', [], 'Invalid JSON body')], 400);
        }
        if (!array_key_exists('parent_id', $data)) {
            return $this->json(['error' => $this->trans('media_folder.parent_id_required', [], 'parent_id is required')], 422);
        }

        $parentId = $data['parent_id'];
        if ($parentId !== null) {
            if (!is_numeric($parentId) || (int) $parentId <= 0) {
                return $this->json(['error' => $this->trans('media_folder.invalid_parent_id', [], 'Invalid parent folder ID')], 422);
            }
            // Empêcher le déplacement vers soi-même
            if ((int) $parentId === $folder->id) {
                return $this->json(['error' => $this->trans('media_folder.cannot_move_into_itself', [], 'Cannot move a folder into itself')], 422);
            }
            $parent = $this->folderRepository->findOneBy(['id' => (int) $parentId, 'project' => $project]);
            if (!$parent || $parent->isDeleted()) {
                return $this->json(['error' => $this->trans('media_folder.parent_not_found', [], 'Parent folder not found')], 404);
            }

            // Empêcher le déplacement vers un descendant (boucle cyclique) et borner la profondeur
            $depth = 1;
            $ancestor = $parent;
            while ($ancestor !== null) {
                if ($ancestor->id === $folder->id) {
                    return $this->json(['error' => $this->trans('media_folder.cannot_move_into_descendants', [], 'Cannot move a folder into one of its descendants')], 422);
                }
                $depth++;
                if ($depth > 15) {
                    return $this->json(['error' => $this->trans('media_folder.max_depth_exceeded', [], 'Maximum folder hierarchy depth exceeded')], 422);
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
            return $this->json(['error' => $this->trans('media_folder.project_not_found', [], 'Project not found')], 404);
        }
        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.delete', $project);
        }

        $folder = $this->folderRepository->findOneBy(['id' => $id, 'project' => $project]);
        if (!$folder || $folder->isDeleted()) {
            return $this->json(['error' => $this->trans('media_folder.not_found', [], 'Folder not found')], 404);
        }

        // Soft-delete : les enfants et les médias restent, leur FK est SET NULL
        $folder->deletedAt = new \DateTimeImmutable();
        $this->em->flush();

        return $this->json(null, 204);
    }

    private function resolveProject(string $projectUuid): ?\App\Entity\Project
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $projectUuid)) {
            return null;
        }
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
