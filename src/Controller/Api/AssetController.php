<?php

namespace App\Controller\Api;

use App\Entity\Media;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\MediaRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Repository\ProjectStorageProfileRepository;
use App\Repository\StorageRuleRepository;
use App\Service\ApiTokenChecker;
use App\Service\AuditService;
use App\Service\StorageDriverFactory;
use App\Service\StorageManager;
use App\Service\UploadSecurityValidator;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[OA\Tag(name: 'Assets')]
#[Route('/api/{projectId}/files', name: 'public_api_files_')]
class AssetController extends AbstractController
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private MediaRepository $mediaRepository,
        private EntityManagerInterface $em,
        private ApiTokenChecker $tokenChecker,
        private ProjectMemberRepository $memberRepo,
        private Security $security,
        private ProjectStorageProfileRepository $profileRepo,
        private StorageRuleRepository $ruleRepo,
        private StorageDriverFactory $driverFactory,
        private ?UploadSecurityValidator $uploadValidator = null,
        private ?TranslatorInterface $translator = null,
        #[Autowire(service: 'limiter.media_limiter')]
        private ?RateLimiterFactory $mediaLimiter = null,
        private ?AuditService $auditService = null,
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

    #[OA\Get(
        path: '/api/{projectId}/files',
        summary: 'List media files',
        parameters: [
            new OA\Parameter(name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated list of files', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MediaFile')),
                new OA\Property(property: 'meta', ref: '#/components/schemas/PaginatedMeta'),
            ])),
            new OA\Response(response: 403, description: 'Public API disabled', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('', name: 'index', methods: ['GET', 'OPTIONS'])]
    public function index(Request $request, string $projectId): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return $this->corsResponse(new JsonResponse(null, 204));
        }
        $project = $this->resolvePublicProject($projectId);
        if ($project instanceof JsonResponse) {
            return $this->corsResponse($project);
        }

        $page    = max(1, $request->query->getInt('page', 1));
        $perPage = min(100, max(1, $request->query->getInt('per_page', 20)));

        $media = $this->mediaRepository->findByProjectPaginated($project, $page, $perPage);
        $total = $this->mediaRepository->countByProject($project);

        return $this->corsResponse($this->json([
            'data' => array_map(fn ($m) => $this->serializeMedia($m), $media),
            'meta' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int) ceil($total / $perPage),
            ],
        ]));
    }

    #[OA\Get(
        path: '/api/{projectId}/files/{identifier}',
        summary: 'Get a single media file by UUID',
        parameters: [
            new OA\Parameter(name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'identifier', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'File found', content: new OA\JsonContent(ref: '#/components/schemas/MediaFile')),
            new OA\Response(response: 404, description: 'File not found', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('/{identifier}', name: 'show', methods: ['GET', 'OPTIONS'])]
    public function show(Request $request, string $projectId, string $identifier): JsonResponse
    {
        if ($request->getMethod() === 'OPTIONS') {
            return $this->corsResponse(new JsonResponse(null, 204));
        }
        $project = $this->resolvePublicProject($projectId);
        if ($project instanceof JsonResponse) {
            return $this->corsResponse($project);
        }

        try {
            $media = $this->mediaRepository->findOneBy(['uuid' => $identifier, 'project' => $project, 'deletedAt' => null]);
        } catch (\Throwable) {
            return $this->corsResponse($this->json(['error' => $this->trans('asset.file_not_found', [], 'File not found.')], 404));
        }

        if ($media === null || $media->isQuarantined) {
            return $this->corsResponse($this->json(['error' => $this->trans('asset.file_not_found', [], 'File not found.')], 404));
        }

        return $this->corsResponse($this->json($this->serializeMedia($media)));
    }

    #[OA\Post(
        path: '/api/{projectId}/files',
        summary: 'Upload a media file',
        security: [['ApiToken' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(properties: [
                new OA\Property(property: 'file', type: 'string', format: 'binary'),
                new OA\Property(property: 'alt', type: 'string', nullable: true),
                new OA\Property(property: 'caption', type: 'string', nullable: true),
            ]))
        ),
        responses: [
            new OA\Response(response: 201, description: 'File uploaded', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/MediaFile'),
            ])),
            new OA\Response(response: 422, description: 'No file provided or invalid type'),
            new OA\Response(response: 401, description: 'Authentication required'),
            new OA\Response(response: 403, description: 'Public API disabled'),
        ]
    )]
    #[Route('', name: 'upload', methods: ['POST'])]
    public function upload(Request $request, string $projectId): JsonResponse
    {
        // CORS preflight
        if ($request->getMethod() === 'OPTIONS') {
            return $this->corsResponse(new JsonResponse(null, 204));
        }

        if ($this->mediaLimiter !== null) {
            $identifier = $this->security->getUser()?->getUserIdentifier() ?? $request->getClientIp() ?: '127.0.0.1';
            if (false === $this->mediaLimiter->create($identifier)->consume(1)->isAccepted()) {
                return $this->corsResponse(new JsonResponse([
                    'error' => $this->trans('media.rate_limit_exceeded', [], 'Too many media requests. Please retry later.'),
                    'code' => 'RATE_LIMIT_EXCEEDED',
                ], 429));
            }
        }

        $project = $this->resolvePublicProject($projectId);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        // Authentification via token API
        if (!$this->isAuthorized($request, $project)) {
            return $this->corsResponse($this->json(['error' => $this->trans('asset.auth_required', [], 'Authentication required')], 401));
        }

        $uploadedFile = $request->files->get('file');
        if (!$uploadedFile) {
            return $this->corsResponse($this->json(['error' => $this->trans('asset.no_file_uploaded', [], 'No file uploaded')], 422));
        }

        // Validation sécurité et quotas via UploadSecurityValidator
        if ($this->uploadValidator !== null) {
            try {
                $this->uploadValidator->validateUploadedFile($uploadedFile, $project);
            } catch (\InvalidArgumentException $e) {
                return $this->corsResponse(new JsonResponse(['error' => $e->getMessage()], 422));
            }
        } else {
            // Validation fallback taille: 10 Mo max
            if ($uploadedFile->getSize() > 10 * 1024 * 1024) {
                return $this->corsResponse($this->json(['error' => $this->trans('asset.file_too_large', [], 'File too large. Maximum size is 10 MB.')], 422));
            }
        }

        $rawAlt = $request->request->get('alt');
        $alt = is_scalar($rawAlt) ? trim((string) $rawAlt) : null;
        if ($alt !== null) {
            if (mb_strlen($alt) > 500) {
                return $this->corsResponse(new JsonResponse(['error' => $this->trans('media.alt_too_long', ['%max%' => 500], 'Alt text cannot exceed 500 characters.')], 422));
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $alt)) {
                return $this->corsResponse(new JsonResponse(['error' => $this->trans('media.alt_control_chars', [], 'Alt text contains invalid control characters.')], 422));
            }
        }

        $rawCaption = $request->request->get('caption');
        $caption = is_scalar($rawCaption) ? trim((string) $rawCaption) : null;
        if ($caption !== null) {
            if (mb_strlen($caption) > 2000) {
                return $this->corsResponse(new JsonResponse(['error' => $this->trans('media.caption_too_long', ['%max%' => 2000], 'Caption cannot exceed 2000 characters.')], 422));
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $caption)) {
                return $this->corsResponse(new JsonResponse(['error' => $this->trans('media.caption_control_chars', [], 'Caption contains invalid control characters.')], 422));
            }
        }

        $cleanFilename = $this->uploadValidator !== null
            ? $this->uploadValidator->sanitizeAndValidateFilename($uploadedFile->getClientOriginalName())
            : basename($uploadedFile->getClientOriginalName());

        $media = new Media();
        $media->project = $project;
        $media->alt     = $alt;
        $media->caption = $caption;
        $media->originalName = $cleanFilename;
        $media->setFile($uploadedFile);

        $this->em->wrapInTransaction(function () use ($media, $project, $uploadedFile) {
            if ($this->uploadValidator !== null) {
                $this->uploadValidator->validateSizeAndQuota($project, $uploadedFile->getSize());
            }
            $this->em->persist($media);
            $this->em->flush();
        });

        // ── Écrire sur le(s) storage(s) via StorageManager ──
        $storageManager = new StorageManager($project, $this->profileRepo, $this->ruleRepo, $this->driverFactory);
        $localPath = $this->getParameter('kernel.project_dir') . '/public/uploads/media/' . (string) $project->uuid . '/' . $media->fileName;
        if (!is_file($localPath)) {
            $localPath = $uploadedFile->getRealPath() ?: $uploadedFile->getPathname();
        }
        $stream = fopen($localPath, 'r');
        $basePath = 'projects/' . $project->uuid->toRfc4122() . '/' . basename($media->fileName);

        $storageOpts = [
            'mime_type' => $media->mimeType,
            'filename'  => $media->fileName,
            'size'      => $media->fileSize,
        ];

        $paths = $storageManager->write($basePath, $stream, $storageOpts);
        fclose($stream);

        $media->storagePaths = $paths;
        $primaryUuid = array_key_first($paths);
        $primaryProfile = $this->profileRepo->findOneBy(['uuid' => $primaryUuid, 'project' => $project]);
        $media->storageProfile = $primaryProfile;

        $this->em->flush();

        $this->auditService?->log('asset_upload', $project, ['file' => $cleanFilename], ['id' => $media->id], 'success', null, $this->security->getUser()?->getUserIdentifier(), 'public_api');

        return $this->corsResponse($this->json(['data' => $this->serializeMedia($media)], 201));
    }

    #[Route('/{uuid}', name: 'delete', methods: ['DELETE'])]
    public function delete(Request $request, string $projectId, string $uuid): Response
    {
        if ($this->mediaLimiter !== null) {
            $identifier = $this->security->getUser()?->getUserIdentifier() ?? $request->getClientIp() ?: '127.0.0.1';
            if (false === $this->mediaLimiter->create($identifier)->consume(1)->isAccepted()) {
                return $this->corsResponse(new JsonResponse([
                    'error' => $this->trans('media.rate_limit_exceeded', [], 'Too many media requests. Please retry later.'),
                    'code' => 'RATE_LIMIT_EXCEEDED',
                ], 429));
            }
        }

        $project = $this->resolvePublicProject($projectId);
        if ($project instanceof JsonResponse) {
            return $this->corsResponse($project);
        }

        // Suppression : ability « delete » requise (voie publique §4, comme le contenu).
        $token = $this->tokenChecker->resolve($request);
        if ($token === null || !$token->can('delete') || $token->project?->id !== $project->id) {
            return $this->corsResponse($this->json(['error' => $this->trans('asset.delete_unauthorized', [], 'Unauthorized. Token requires delete ability.')], 401));
        }

        try {
            $media = $this->mediaRepository->findOneBy(['uuid' => $uuid, 'project' => $project, 'deletedAt' => null]);
        } catch (\Throwable) {
            return $this->corsResponse($this->json(['error' => $this->trans('asset.file_not_found', [], 'File not found.')], 404));
        }
        if ($media === null) {
            return $this->corsResponse($this->json(['error' => $this->trans('asset.file_not_found', [], 'File not found.')], 404));
        }

        if (!empty($media->storagePaths)) {
            try {
                $storageManager = new StorageManager($project, $this->profileRepo, $this->ruleRepo, $this->driverFactory);
                $storageManager->delete($media->storagePaths);
            } catch (\Throwable) {}
        }

        $media->deletedAt = new \DateTimeImmutable();
        $this->em->flush();

        $this->auditService?->log('asset_delete', $project, ['uuid' => $uuid], ['id' => $media->id], 'success', null, $this->security->getUser()?->getUserIdentifier(), 'public_api');

        return $this->corsResponse(new JsonResponse(null, 204));
    }

    private function isAuthorized(Request $request, Project $project): bool
    {
        $user = $this->security->getUser();
        if ($user instanceof User) {
            if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
                return true;
            }
            return $this->memberRepo->findActiveByUserAndProject($user, $project) !== null;
        }
        $token = $this->tokenChecker->resolve($request);
        if ($token !== null && $token->project?->id === $project->id) {
            return $this->tokenChecker->isAllowedForMethod($token, $request->getMethod());
        }
        return false;
    }

    private function corsResponse(JsonResponse|Response $response): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, If-None-Match');
        $response->headers->set('Access-Control-Max-Age', '3600');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        return $response;
    }

    private function resolvePublicProject(string $projectId): Project|JsonResponse
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $projectId)) {
            return new JsonResponse(['error' => $this->trans('asset.invalid_project_uuid', [], 'Invalid project UUID format')], 400);
        }
        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectId]);
        } catch (\Throwable) {
            return new JsonResponse(['error' => $this->trans('asset.project_not_found', [], 'Project not found.')], 404);
        }
        if ($project === null) {
            return new JsonResponse(['error' => $this->trans('asset.project_not_found', [], 'Project not found.')], 404);
        }

        if (!$project->publicApi) {
            return $this->json(['error' => $this->trans('asset.public_api_disabled', [], 'Public API access is disabled for this project.')], 403);
        }

        return $project;
    }

    private function serializeMedia(\App\Entity\Media $media): array
    {
        return [
            'uuid'         => $media->uuid?->toString(),
            'fileName'     => $media->fileName,
            'originalName' => $media->originalName,
            'mimeType'     => $media->mimeType,
            'fileSize'     => $media->fileSize,
            'url'          => $media->getPublicUrl(),
            'alt'          => $media->alt,
            'caption'      => $media->caption,
            'width'        => $media->metadata?->width,
            'height'       => $media->metadata?->height,
            'createdAt'    => $media->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
