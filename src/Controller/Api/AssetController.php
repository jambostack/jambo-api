<?php

namespace App\Controller\Api;

use App\Entity\Project;
use App\Repository\MediaRepository;
use App\Repository\ProjectRepository;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'Assets')]
#[Route('/api/{projectId}/files', name: 'public_api_files_')]
class AssetController extends AbstractController
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private MediaRepository $mediaRepository,
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
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, string $projectId): JsonResponse
    {
        $project = $this->resolvePublicProject($projectId);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $page    = max(1, $request->query->getInt('page', 1));
        $perPage = min(100, max(1, $request->query->getInt('per_page', 20)));

        $media = $this->mediaRepository->findByProjectPaginated($project, $page, $perPage);
        $total = $this->mediaRepository->countByProject($project);

        return $this->json([
            'data' => array_map(fn ($m) => $this->serializeMedia($m), $media),
            'meta' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int) ceil($total / $perPage),
            ],
        ]);
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
    #[Route('/{identifier}', name: 'show', methods: ['GET'])]
    public function show(Request $_request, string $projectId, string $identifier): JsonResponse
    {
        $project = $this->resolvePublicProject($projectId);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $media = $this->mediaRepository->findOneBy(['uuid' => $identifier, 'project' => $project, 'deletedAt' => null]);

        if ($media === null) {
            return $this->json(['error' => 'File not found.'], 404);
        }

        return $this->json($this->serializeMedia($media));
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
