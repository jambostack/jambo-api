<?php

namespace App\Controller;

use App\Entity\Media;
use App\Entity\MediaFolder;
use App\Entity\Project;
use App\Repository\MediaFolderRepository;
use App\Repository\MediaRepository;
use App\Repository\ProjectRepository;
use App\Service\AuditService;
use App\Service\MediaSerializer;
use App\Service\MercurePublisher;
use App\Service\UploadSecurityValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Vich\UploaderBundle\Handler\UploadHandler;

#[Route('/api/projects/{projectUuid}/media', name: 'api_media_')]
class MediaController extends AbstractController
{
    private const SECURITY_HEADERS = [
        'Cache-Control' => 'no-store, no-cache, must-revalidate',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private MediaSerializer $mediaSerializer,
        private UploadHandler $uploadHandler,
        private ProjectRepository $projectRepository,
        private MediaRepository $mediaRepository,
        private MediaFolderRepository $folderRepository,
        private MercurePublisher $mercure,
        private UploadSecurityValidator $uploadValidator,
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
                    'error' => $this->trans('media.rate_limit_exceeded', [], 'Too many media requests. Please retry later.'),
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
            return new JsonResponse(['error' => $this->trans('media.project_not_found', [], 'Project not found')], 404, self::SECURITY_HEADERS);
        }
        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('project.view', $project);
        }

        $page    = max(1, (int) $request->query->get('page', 1));
        $perPage = min(100, max(1, (int) $request->query->get('per_page', 20)));

        // Construire les conditions WHERE
        $where = 'm.project = :project AND m.deletedAt IS NULL';
        $params = ['project' => $project];

        // Filtre par dossier : folder_id=null → racine (sans dossier)
        if ($request->query->has('folder_id')) {
            $folderId = $request->query->get('folder_id');
            if ($folderId === '' || $folderId === null || $folderId === 'null') {
                $where .= ' AND m.folder IS NULL';
            } else {
                $where .= ' AND m.folder = :folderId';
                $params['folderId'] = (int) $folderId;
            }
        }

        // Filtre par recherche texte
        $rawSearch = (string) $request->query->get('search', '');
        $search = mb_substr(trim($rawSearch), 0, 100);
        if ($search !== '') {
            $where .= ' AND (m.originalName LIKE :search OR m.fileName LIKE :search OR m.alt LIKE :search OR m.caption LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        // Filtre par type (mime_type)
        $type = $request->query->get('type', '');
        if ($type !== '' && $type !== 'all') {
            if ($type === 'image') {
                $where .= ' AND m.mimeType LIKE :type';
                $params['type'] = 'image/%';
            } elseif ($type === 'video') {
                $where .= ' AND m.mimeType LIKE :type';
                $params['type'] = 'video/%';
            } elseif ($type === 'audio') {
                $where .= ' AND m.mimeType LIKE :type';
                $params['type'] = 'audio/%';
            } elseif ($type === 'document') {
                $where .= " AND (m.mimeType LIKE :type1 OR m.mimeType LIKE :type2 OR m.mimeType LIKE :type3 OR m.mimeType LIKE :type4 OR m.mimeType LIKE :type5)";
                $params['type1'] = 'application/pdf';
                $params['type2'] = 'application/msword';
                $params['type3'] = 'application/vnd.%';
                $params['type4'] = 'text/plain';
                $params['type5'] = 'text/csv';
            } else {
                // other
                $where .= ' AND m.mimeType NOT LIKE :timg AND m.mimeType NOT LIKE :tvid AND m.mimeType NOT LIKE :taud';
                $params['timg'] = 'image/%';
                $params['tvid'] = 'video/%';
                $params['taud'] = 'audio/%';
            }
        }

        // Filtre par date
        $dateFilter = $request->query->get('date_filter', '');
        if ($dateFilter !== '') {
            $since = match ($dateFilter) {
                'today'   => new \DateTimeImmutable('today'),
                'week'    => new \DateTimeImmutable('-7 days'),
                'month'   => new \DateTimeImmutable('-30 days'),
                'quarter' => new \DateTimeImmutable('-90 days'),
                default   => null,
            };
            if ($since !== null) {
                $where .= ' AND m.createdAt >= :dateSince';
                $params['dateSince'] = $since;
            }
        }

        // Tri
        $sort = $request->query->get('sort', 'newest');
        $orderParts = match ($sort) {
            'oldest'    => ['m.createdAt', 'ASC'],
            'name'      => ['m.originalName', 'ASC'],
            'size'      => ['m.fileSize', 'DESC'],
            default     => ['m.createdAt', 'DESC'],
        };

        $qb = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Media::class, 'm')
            ->where($where)
            ->orderBy($orderParts[0], $orderParts[1])
            ->setMaxResults($perPage)
            ->setFirstResult(($page - 1) * $perPage);
        foreach ($params as $key => $value) {
            $qb->setParameter($key, $value);
        }

        $countQb = $this->em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(Media::class, 'm')
            ->where($where);
        foreach ($params as $key => $value) {
            $countQb->setParameter($key, $value);
        }
        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        $media = $qb->getQuery()->getResult();

        return new JsonResponse([
            'data'         => array_map(fn ($m) => $this->mediaSerializer->serialize($m), $media),
            'total'        => $total,
            'current_page' => $page,
            'last_page'    => max(1, (int) ceil($total / $perPage)),
            'per_page'     => $perPage,
        ], 200, self::SECURITY_HEADERS);
    }

    #[Route('', name: 'upload', methods: ['POST'])]
    public function upload(string $projectUuid, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return new JsonResponse(['error' => $this->trans('media.project_not_found', [], 'Project not found')], 404, self::SECURITY_HEADERS);
        }
        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.create', $project);
        }

        $uploadedFile = $request->files->get('file');
        if (!$uploadedFile) {
            return new JsonResponse(['error' => $this->trans('media.no_file_uploaded', [], 'No file uploaded')], 422, self::SECURITY_HEADERS);
        }

        try {
            $this->uploadValidator->validateUploadedFile($uploadedFile, $project);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422, self::SECURITY_HEADERS);
        }

        $rawAlt = $request->request->get('alt');
        $alt = is_scalar($rawAlt) ? trim((string) $rawAlt) : null;
        if ($alt !== null) {
            if (mb_strlen($alt) > 500) {
                return new JsonResponse(['error' => $this->trans('media.alt_too_long', ['%max%' => 500], 'Alt text cannot exceed 500 characters.')], 422, self::SECURITY_HEADERS);
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $alt)) {
                return new JsonResponse(['error' => $this->trans('media.alt_control_chars', [], 'Alt text contains invalid control characters.')], 422, self::SECURITY_HEADERS);
            }
        }

        $rawCaption = $request->request->get('caption');
        $caption = is_scalar($rawCaption) ? trim((string) $rawCaption) : null;
        if ($caption !== null) {
            if (mb_strlen($caption) > 2000) {
                return new JsonResponse(['error' => $this->trans('media.caption_too_long', ['%max%' => 2000], 'Caption cannot exceed 2000 characters.')], 422, self::SECURITY_HEADERS);
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $caption)) {
                return new JsonResponse(['error' => $this->trans('media.caption_control_chars', [], 'Caption contains invalid control characters.')], 422, self::SECURITY_HEADERS);
            }
        }

        $cleanFilename = $this->uploadValidator->sanitizeAndValidateFilename($uploadedFile->getClientOriginalName());

        $media               = new Media();
        $media->project      = $project;
        $media->alt          = $alt;
        $media->caption      = $caption;
        $media->originalName = $cleanFilename;
        $media->setFile($uploadedFile);

        // Dossier cible optionnel
        $folderId = $request->request->get('folder_id');
        if ($folderId !== null && $folderId !== '') {
            if (is_numeric($folderId) && (int) $folderId > 0) {
                $folder = $this->folderRepository->findOneBy(['id' => (int) $folderId, 'project' => $project]);
                if ($folder && !$folder->isDeleted()) {
                    $media->folder = $folder;
                }
            }
        }

        try {
            $this->em->wrapInTransaction(function () use ($media, $project, $uploadedFile) {
                // Vérification atomique du quota au moment du commit
                $this->uploadValidator->validateSizeAndQuota($project, $uploadedFile->getSize());
                $this->em->persist($media);
                $this->em->flush();
            });
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422, self::SECURITY_HEADERS);
        }

        $this->auditService?->log('media_upload', $project, ['file' => $cleanFilename], ['id' => $media->id], 'success', null, $this->getUser()?->getUserIdentifier(), 'admin_api');

        // Notification temps réel
        $serialized = $this->mediaSerializer->serialize($media);
        $this->mercure->mediaUploaded($projectUuid, $serialized);

        return new JsonResponse(['data' => $serialized], 201, self::SECURITY_HEADERS);
    }

    #[Route('/bulk-destroy', name: 'bulk_destroy', methods: ['DELETE'], priority: 10)]
    public function bulkDestroy(string $projectUuid, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return new JsonResponse(['error' => $this->trans('media.project_not_found', [], 'Project not found')], 404, self::SECURITY_HEADERS);
        }
        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.delete', $project);
        }

        if (strlen($request->getContent()) > 65536) {
            return new JsonResponse(['error' => $this->trans('media.payload_too_large', [], 'Payload too large')], 413, self::SECURITY_HEADERS);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            $data = $request->request->all();
        }

        $ids = $data['asset_ids'] ?? $data['ids'] ?? $data['uuids'] ?? [];
        if (!is_array($ids)) {
            return new JsonResponse(['error' => $this->trans('media.invalid_json', [], 'Invalid JSON body')], 400, self::SECURITY_HEADERS);
        }
        if (count($ids) > 100) {
            return new JsonResponse(['error' => $this->trans('media.bulk_limit_exceeded', ['%max%' => 100], 'Cannot delete more than 100 media items at once.')], 422, self::SECURITY_HEADERS);
        }

        $deletedCount = 0;
        foreach ($ids as $identifier) {
            $criteria = is_numeric($identifier)
                ? ['id' => (int) $identifier, 'project' => $project]
                : ['uuid' => Uuid::fromString((string) $identifier), 'project' => $project];
            try {
                $media = $this->mediaRepository->findOneBy($criteria);
            } catch (\Throwable) {
                $media = null;
            }

            if ($media) {
                $filename = $media->originalName ?? $media->fileName ?? (string) $identifier;
                $this->uploadHandler->remove($media, 'file');
                $this->em->remove($media);
                $this->mercure->mediaDeleted($projectUuid, $filename);
                $deletedCount++;
            }
        }
        $this->em->flush();

        $this->auditService?->log('media_bulk_destroy', $project, ['count' => $deletedCount], null, 'success', null, $this->getUser()?->getUserIdentifier(), 'admin_api');

        return new JsonResponse(null, 204, self::SECURITY_HEADERS);
    }

    #[Route('/{uuid}', name: 'show', methods: ['GET'])]
    public function show(string $projectUuid, string $uuid, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $media = $this->findMedia($projectUuid, $uuid);
        if ($media instanceof JsonResponse) {
            return $media;
        }
        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('project.view', $media->project);
        }

        return new JsonResponse(['data' => $this->mediaSerializer->serialize($media)], 200, self::SECURITY_HEADERS);
    }

    #[Route('/{uuid}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(string $projectUuid, string $uuid, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $media = $this->findMedia($projectUuid, $uuid);
        if ($media instanceof JsonResponse) {
            return $media;
        }
        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.update', $media->project);
        }

        if (strlen($request->getContent()) > 16384) {
            return new JsonResponse(['error' => $this->trans('media.payload_too_large', [], 'Payload too large')], 413, self::SECURITY_HEADERS);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse(['error' => $this->trans('media.invalid_json', [], 'Invalid JSON body')], 400, self::SECURITY_HEADERS);
        }

        if (array_key_exists('alt', $data)) {
            $alt = is_scalar($data['alt']) ? trim((string) $data['alt']) : null;
            if ($alt !== null) {
                if (mb_strlen($alt) > 500) {
                    return new JsonResponse(['error' => $this->trans('media.alt_too_long', ['%max%' => 500], 'Alt text cannot exceed 500 characters.')], 422, self::SECURITY_HEADERS);
                }
                if (preg_match('/[\x00-\x1F\x7F]/', $alt)) {
                    return new JsonResponse(['error' => $this->trans('media.alt_control_chars', [], 'Alt text contains invalid control characters.')], 422, self::SECURITY_HEADERS);
                }
            }
            $media->alt = $alt;
        }
        if (array_key_exists('caption', $data)) {
            $caption = is_scalar($data['caption']) ? trim((string) $data['caption']) : null;
            if ($caption !== null) {
                if (mb_strlen($caption) > 2000) {
                    return new JsonResponse(['error' => $this->trans('media.caption_too_long', ['%max%' => 2000], 'Caption cannot exceed 2000 characters.')], 422, self::SECURITY_HEADERS);
                }
                if (preg_match('/[\x00-\x1F\x7F]/', $caption)) {
                    return new JsonResponse(['error' => $this->trans('media.caption_control_chars', [], 'Caption contains invalid control characters.')], 422, self::SECURITY_HEADERS);
                }
            }
            $media->caption = $caption;
        }

        $this->em->flush();

        $this->auditService?->log('media_update', $media->project, ['uuid' => $uuid], ['id' => $media->id], 'success', null, $this->getUser()?->getUserIdentifier(), 'admin_api');

        return new JsonResponse(['data' => $this->mediaSerializer->serialize($media)], 200, self::SECURITY_HEADERS);
    }

    #[Route('/{uuid}/move', name: 'move', methods: ['PUT', 'PATCH'])]
    public function move(string $projectUuid, string $uuid, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $media = $this->findMedia($projectUuid, $uuid);
        if ($media instanceof JsonResponse) {
            return $media;
        }
        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.update', $media->project);
        }

        if (strlen($request->getContent()) > 8192) {
            return new JsonResponse(['error' => $this->trans('media.payload_too_large', [], 'Payload too large')], 413, self::SECURITY_HEADERS);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse(['error' => $this->trans('media.invalid_json', [], 'Invalid JSON body')], 400, self::SECURITY_HEADERS);
        }

        if (!array_key_exists('folder_id', $data)) {
            return new JsonResponse(['error' => $this->trans('media.folder_id_required', [], 'folder_id is required')], 422, self::SECURITY_HEADERS);
        }

        $folderId = $data['folder_id'];
        if ($folderId === null || $folderId === 'null' || $folderId === '') {
            $media->folder = null;
        } else {
            if (!is_numeric($folderId) || (int) $folderId <= 0) {
                return new JsonResponse(['error' => $this->trans('media.folder_not_found', [], 'Folder not found')], 404, self::SECURITY_HEADERS);
            }
            $project = $this->resolveProject($projectUuid);
            if (!$project) {
                return new JsonResponse(['error' => $this->trans('media.project_not_found', [], 'Project not found')], 404, self::SECURITY_HEADERS);
            }
            $folder = $this->folderRepository->findOneBy(['id' => (int) $folderId, 'project' => $project]);
            if (!$folder || $folder->isDeleted()) {
                return new JsonResponse(['error' => $this->trans('media.folder_not_found', [], 'Folder not found')], 404, self::SECURITY_HEADERS);
            }
            $media->folder = $folder;
        }

        $this->em->flush();

        $this->auditService?->log('media_move', $media->project, ['uuid' => $uuid, 'folder_id' => $folderId], ['id' => $media->id], 'success', null, $this->getUser()?->getUserIdentifier(), 'admin_api');

        return new JsonResponse(['data' => $this->mediaSerializer->serialize($media)], 200, self::SECURITY_HEADERS);
    }

    #[Route('/{uuid}/crop', name: 'crop', methods: ['POST'])]
    public function crop(string $projectUuid, string $uuid, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $media = $this->findMedia($projectUuid, $uuid);
        if ($media instanceof JsonResponse) {
            return $media;
        }
        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.update', $media->project);
        }

        $uploadedFile = $request->files->get('file');
        if (!$uploadedFile) {
            return new JsonResponse(['error' => $this->trans('media.no_file_provided', [], 'No file provided')], 422, self::SECURITY_HEADERS);
        }

        $clientName = $uploadedFile->getClientOriginalName();
        $ext = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));
        $allowedCropExts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];
        if (!in_array($ext, $allowedCropExts, true)) {
            $allowedStr = implode(', ', $allowedCropExts);
            return new JsonResponse(['error' => $this->trans('media.crop_image_required', ['%allowed%' => $allowedStr], sprintf('Cropping requires an image file (allowed: %s).', $allowedStr))], 422, self::SECURITY_HEADERS);
        }

        try {
            $this->uploadValidator->validateUploadedFile($uploadedFile, $media->project);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422, self::SECURITY_HEADERS);
        }

        $cleanFilename = $this->uploadValidator->sanitizeAndValidateFilename($clientName);

        $media->setFile($uploadedFile);
        $media->originalName = $cleanFilename;
        $this->em->flush();

        $this->auditService?->log('media_crop', $media->project, ['uuid' => $uuid], ['id' => $media->id], 'success', null, $this->getUser()?->getUserIdentifier(), 'admin_api');

        return new JsonResponse(['data' => $this->mediaSerializer->serialize($media)], 200, self::SECURITY_HEADERS);
    }

    #[Route('/{uuid}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $projectUuid, string $uuid, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $media = $this->findMedia($projectUuid, $uuid);
        if ($media instanceof JsonResponse) {
            return $media;
        }
        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.delete', $media->project);
        }

        $filename = $media->originalName ?? $media->fileName ?? $uuid;
        $this->uploadHandler->remove($media, 'file');
        $this->em->remove($media);
        $this->em->flush();

        $this->auditService?->log('media_delete', $media->project, ['uuid' => $uuid, 'filename' => $filename], null, 'success', null, $this->getUser()?->getUserIdentifier(), 'admin_api');

        $this->mercure->mediaDeleted($projectUuid, $filename);

        return new JsonResponse(null, 204, self::SECURITY_HEADERS);
    }

    private function resolveProject(string $projectUuid): ?Project
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

    private function findMedia(string $projectUuid, string $uuid): Media|JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return $this->json(['error' => $this->trans('media.project_not_found', [], 'Project not found')], 404);
        }
        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('project.view', $project);
        }

        try {
            $media = $this->mediaRepository->findOneBy([
                'uuid'    => Uuid::fromString($uuid),
                'project' => $project,
            ]);
        } catch (\Throwable) {
            $media = is_numeric($uuid)
                ? $this->mediaRepository->findOneBy(['id' => (int) $uuid, 'project' => $project])
                : null;
        }

        if (!$media || $media->deletedAt !== null) {
            return $this->json(['error' => $this->trans('media.not_found', [], 'Media not found')], 404);
        }

        return $media;
    }
}
