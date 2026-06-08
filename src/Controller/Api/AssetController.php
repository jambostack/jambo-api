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
use App\Service\StorageDriverFactory;
use App\Service\StorageManager;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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
    ) {}

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

        $media = $this->mediaRepository->findOneBy(['uuid' => $identifier, 'project' => $project, 'deletedAt' => null]);

        if ($media === null) {
            return $this->corsResponse($this->json(['error' => 'File not found.'], 404));
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

        $project = $this->resolvePublicProject($projectId);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        // Authentification via token API
        if (!$this->isAuthorized($request, $project)) {
            return $this->corsResponse($this->json(['error' => 'Authentication required'], 401));
        }

        $uploadedFile = $request->files->get('file');
        if (!$uploadedFile) {
            return $this->corsResponse($this->json(['error' => 'No file uploaded'], 422));
        }

        // Validation: 10 Mo max
        if ($uploadedFile->getSize() > 10 * 1024 * 1024) {
            return $this->corsResponse($this->json(['error' => 'File too large. Maximum size is 10 MB.'], 422));
        }

        $media = new Media();
        $media->project = $project;
        $media->alt     = $request->request->get('alt');
        $media->caption = $request->request->get('caption');
        $media->originalName = $uploadedFile->getClientOriginalName();
        $media->setFile($uploadedFile);

        $this->em->persist($media);
        $this->em->flush();

        // ── Écrire sur le(s) storage(s) via StorageManager ──
        $storageManager = new StorageManager($project, $this->profileRepo, $this->ruleRepo, $this->driverFactory);
        $stream = fopen($uploadedFile->getRealPath(), 'r');
        $basePath = 'projects/' . $project->uuid->toRfc4122() . '/' . $media->fileName;

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

        return $this->corsResponse($this->json(['data' => $this->serializeMedia($media)], 201));
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
        return $token !== null && $token->project?->id === $project->id;
    }

    private function corsResponse(JsonResponse|Response $response): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, If-None-Match');
        $response->headers->set('Access-Control-Max-Age', '3600');
        return $response;
    }

    private function resolvePublicProject(string $projectId): Project|JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectId]);
        if ($project === null) {
            return $this->json(['error' => 'Project not found.'], 404);
        }

        if (!$project->publicApi) {
            return $this->json(['error' => 'Public API access is disabled for this project.'], 403);
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
