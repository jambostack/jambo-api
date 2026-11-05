<?php

namespace App\Controller;

use App\Entity\MediaFolder;
use App\Repository\MediaFolderRepository;
use App\Repository\ProjectRepository;
use App\Service\AuditService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/projects/{projectUuid}/media-folders', name: 'api_media_folder_')]
class MediaFolderController extends AbstractController
{
    public const MAX_FOLDERS_PER_PROJECT = 200;

    private const SECURITY_HEADERS = [
        'Cache-Control' => 'no-store, no-cache, must-revalidate',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projectRepository,
        private MediaFolderRepository $folderRepository,
        #[Autowire(service: 'limiter.media_limiter')]
        private ?RateLimiterFactory $mediaLimiter = null,
        private ?AuditService $auditService = null,
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

    private function checkRateLimit(Request $request): ?JsonResponse
    {
        if ($this->mediaLimiter !== null) {
            $identifier = $this->getUser()?->getUserIdentifier() ?? $request->getClientIp() ?: '127.0.0.1';
            if (false === $this->mediaLimiter->create($identifier)->consume(1)->isAccepted()) {
                return new JsonResponse([
                    'error' => $this->trans('media_folder.rate_limit_exceeded', [], 'Too many folder requests. Please retry later.'),
                    'code' => 'RATE_LIMIT_EXCEEDED',
                ], 429, self::SECURITY_HEADERS);
            }
        }
        return null;
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $projectUuid, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return new JsonResponse(['error' => $this->trans('media_folder.project_not_found', [], 'Project not found')], 404, self::SECURITY_HEADERS);
        }
        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('project.view', $project);
        }

        $folders = $this->folderRepository->findByProject($project);

        return new JsonResponse([
            'data' => array_map(fn (MediaFolder $f) => $this->serializeFolder($f), $folders),
        ], 200, self::SECURITY_HEADERS);
    }

    #[Route('/tree', name: 'tree', methods: ['GET'])]
    public function tree(string $projectUuid, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return new JsonResponse(['error' => $this->trans('media_folder.project_not_found', [], 'Project not found')], 404, self::SECURITY_HEADERS);
        }
        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('project.view', $project);
        }

        $roots = $this->folderRepository->findTree($project);

        return new JsonResponse([
            'data' => array_map(fn (MediaFolder $f) => $this->serializeTree($f), $roots),
        ], 200, self::SECURITY_HEADERS);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(string $projectUuid, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return new JsonResponse(['error' => $this->trans('media_folder.project_not_found', [], 'Project not found')], 404, self::SECURITY_HEADERS);
        }
        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.create', $project);
        }

        if (strlen($request->getContent()) > 16384) {
            return new JsonResponse(['error' => $this->trans('media_folder.payload_too_large', [], 'Payload too large')], 413, self::SECURITY_HEADERS);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse(['error' => $this->trans('media_folder.invalid_json', [], 'Invalid JSON body')], 400, self::SECURITY_HEADERS);
        }

        $allFolders = $this->folderRepository->findByProject($project);
        if (count($allFolders) >= self::MAX_FOLDERS_PER_PROJECT) {
            return new JsonResponse([
                'error' => $this->trans('media_folder.max_folders_exceeded', ['%max%' => self::MAX_FOLDERS_PER_PROJECT], sprintf('Maximum folder quota reached (limit: %d).', self::MAX_FOLDERS_PER_PROJECT)),
                'code' => 'MAX_FOLDERS_EXCEEDED',
            ], 422, self::SECURITY_HEADERS);
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return new JsonResponse(['error' => $this->trans('media_folder.name_required', [], 'Name is required')], 422, self::SECURITY_HEADERS);
        }
        if (mb_strlen($name) > 150) {
            return new JsonResponse(['error' => $this->trans('media_folder.name_too_long', [], 'Name cannot exceed 150 characters')], 422, self::SECURITY_HEADERS);
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
            return new JsonResponse(['error' => $this->trans('media_folder.name_control_characters', [], 'Name contains forbidden control characters')], 422, self::SECURITY_HEADERS);
        }
        if (str_contains($name, '..') || str_contains($name, '/') || str_contains($name, '\\')) {
            return new JsonResponse(['error' => $this->trans('media_folder.name_invalid_traversal', [], 'Name cannot contain path traversal sequences or slashes')], 422, self::SECURITY_HEADERS);
        }

        $folder = new MediaFolder();
        $folder->name = $name;
        $folder->slug = (new AsciiSlugger())->slug($name)->lower()->toString();
        $folder->project = $project;

        // Position après le dernier dossier du même niveau
        $parentId = $data['parent_id'] ?? null;
        if ($parentId !== null) {
            if (!is_numeric($parentId) || (int) $parentId <= 0) {
                return new JsonResponse(['error' => $this->trans('media_folder.invalid_parent_id', [], 'Invalid parent folder ID')], 422, self::SECURITY_HEADERS);
            }
            $parent = $this->folderRepository->findOneBy(['id' => (int) $parentId, 'project' => $project]);
            if (!$parent || $parent->isDeleted()) {
                return new JsonResponse(['error' => $this->trans('media_folder.parent_not_found', [], 'Parent folder not found')], 404, self::SECURITY_HEADERS);
            }

            // Hierarchy depth check (prevent unbounded tree nesting)
            $depth = 1;
            $ancestor = $parent;
            while ($ancestor !== null) {
                $depth++;
                if ($depth > 15) {
                    return new JsonResponse(['error' => $this->trans('media_folder.max_depth_exceeded', [], 'Maximum folder hierarchy depth exceeded')], 422, self::SECURITY_HEADERS);
                }
                $ancestor = $ancestor->parent;
            }

            $folder->parent = $parent;
        }

        // Calculer la position max parmi les siblings
        $maxPos = 0;
        foreach ($allFolders as $f) {
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

        $this->auditService?->log('media_folder_create', $project, ['name' => $name, 'parent_id' => $parentId], ['id' => $folder->id], 'success', null, $this->getUser()?->getUserIdentifier(), 'admin_api');

        return new JsonResponse(['data' => $this->serializeFolder($folder)], 201, self::SECURITY_HEADERS);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(string $projectUuid, int $id, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return new JsonResponse(['error' => $this->trans('media_folder.project_not_found', [], 'Project not found')], 404, self::SECURITY_HEADERS);
        }
        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.update', $project);
        }

        $folder = $this->folderRepository->findOneBy(['id' => $id, 'project' => $project]);
        if (!$folder || $folder->isDeleted()) {
            return new JsonResponse(['error' => $this->trans('media_folder.not_found', [], 'Folder not found')], 404, self::SECURITY_HEADERS);
        }

        if (strlen($request->getContent()) > 16384) {
            return new JsonResponse(['error' => $this->trans('media_folder.payload_too_large', [], 'Payload too large')], 413, self::SECURITY_HEADERS);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse(['error' => $this->trans('media_folder.invalid_json', [], 'Invalid JSON body')], 400, self::SECURITY_HEADERS);
        }
        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return new JsonResponse(['error' => $this->trans('media_folder.name_empty', [], 'Name cannot be empty')], 422, self::SECURITY_HEADERS);
            }
            if (mb_strlen($name) > 150) {
                return new JsonResponse(['error' => $this->trans('media_folder.name_too_long', [], 'Name cannot exceed 150 characters')], 422, self::SECURITY_HEADERS);
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
                return new JsonResponse(['error' => $this->trans('media_folder.name_control_characters', [], 'Name contains forbidden control characters')], 422, self::SECURITY_HEADERS);
            }
            if (str_contains($name, '..') || str_contains($name, '/') || str_contains($name, '\\')) {
                return new JsonResponse(['error' => $this->trans('media_folder.name_invalid_traversal', [], 'Name cannot contain path traversal sequences or slashes')], 422, self::SECURITY_HEADERS);
            }
            $folder->name = $name;
            $folder->slug = (new AsciiSlugger())->slug($folder->name)->lower()->toString();
        }

        $this->em->flush();

        $this->auditService?->log('media_folder_update', $project, ['id' => $id, 'name' => $folder->name], ['id' => $folder->id], 'success', null, $this->getUser()?->getUserIdentifier(), 'admin_api');

        return new JsonResponse(['data' => $this->serializeFolder($folder)], 200, self::SECURITY_HEADERS);
    }

    #[Route('/{id}/move', name: 'move', methods: ['PATCH'])]
    public function move(string $projectUuid, int $id, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return new JsonResponse(['error' => $this->trans('media_folder.project_not_found', [], 'Project not found')], 404, self::SECURITY_HEADERS);
        }
        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.update', $project);
        }

        $folder = $this->folderRepository->findOneBy(['id' => $id, 'project' => $project]);
        if (!$folder || $folder->isDeleted()) {
            return new JsonResponse(['error' => $this->trans('media_folder.not_found', [], 'Folder not found')], 404, self::SECURITY_HEADERS);
        }

        if (strlen($request->getContent()) > 16384) {
            return new JsonResponse(['error' => $this->trans('media_folder.payload_too_large', [], 'Payload too large')], 413, self::SECURITY_HEADERS);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse(['error' => $this->trans('media_folder.invalid_json', [], 'Invalid JSON body')], 400, self::SECURITY_HEADERS);
        }
        if (!array_key_exists('parent_id', $data)) {
            return new JsonResponse(['error' => $this->trans('media_folder.parent_id_required', [], 'parent_id is required')], 422, self::SECURITY_HEADERS);
        }

        $parentId = $data['parent_id'];
        if ($parentId !== null) {
            if (!is_numeric($parentId) || (int) $parentId <= 0) {
                return new JsonResponse(['error' => $this->trans('media_folder.invalid_parent_id', [], 'Invalid parent folder ID')], 422, self::SECURITY_HEADERS);
            }
            // Empêcher le déplacement vers soi-même
            if ((int) $parentId === $folder->id) {
                return new JsonResponse(['error' => $this->trans('media_folder.cannot_move_into_itself', [], 'Cannot move a folder into itself')], 422, self::SECURITY_HEADERS);
            }
            $parent = $this->folderRepository->findOneBy(['id' => (int) $parentId, 'project' => $project]);
            if (!$parent || $parent->isDeleted()) {
                return new JsonResponse(['error' => $this->trans('media_folder.parent_not_found', [], 'Parent folder not found')], 404, self::SECURITY_HEADERS);
            }

            // Empêcher le déplacement vers un descendant (boucle cyclique) et borner la profondeur
            $depth = 1;
            $ancestor = $parent;
            while ($ancestor !== null) {
                if ($ancestor->id === $folder->id) {
                    return new JsonResponse(['error' => $this->trans('media_folder.cannot_move_into_descendants', [], 'Cannot move a folder into one of its descendants')], 422, self::SECURITY_HEADERS);
                }
                $depth++;
                if ($depth > 15) {
                    return new JsonResponse(['error' => $this->trans('media_folder.max_depth_exceeded', [], 'Maximum folder hierarchy depth exceeded')], 422, self::SECURITY_HEADERS);
                }
                $ancestor = $ancestor->parent;
            }

            $folder->parent = $parent;
        } else {
            $folder->parent = null;
        }

        $this->em->flush();

        $this->auditService?->log('media_folder_move', $project, ['id' => $id, 'parent_id' => $parentId], ['id' => $folder->id], 'success', null, $this->getUser()?->getUserIdentifier(), 'admin_api');

        return new JsonResponse(['data' => $this->serializeFolder($folder)], 200, self::SECURITY_HEADERS);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $projectUuid, int $id, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return new JsonResponse(['error' => $this->trans('media_folder.project_not_found', [], 'Project not found')], 404, self::SECURITY_HEADERS);
        }
        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.delete', $project);
        }

        $folder = $this->folderRepository->findOneBy(['id' => $id, 'project' => $project]);
        if (!$folder || $folder->isDeleted()) {
            return new JsonResponse(['error' => $this->trans('media_folder.not_found', [], 'Folder not found')], 404, self::SECURITY_HEADERS);
        }

        // Soft-delete : les enfants et les médias restent, leur FK est SET NULL
        $folderName = $folder->name;
        $folder->deletedAt = new \DateTimeImmutable();
        $this->em->flush();

        $this->auditService?->log('media_folder_delete', $project, ['id' => $id, 'name' => $folderName], null, 'success', null, $this->getUser()?->getUserIdentifier(), 'admin_api');

        return new JsonResponse(null, 204, self::SECURITY_HEADERS);
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
