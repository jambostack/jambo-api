<?php

namespace App\Controller\Api;

use App\Entity\Project;
use App\Repository\MediaRepository;
use App\Repository\ProjectRepository;
use App\Service\ApiTokenChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/{projectId}/files', name: 'public_api_files_')]
class AssetController extends AbstractController
{
    public function __construct(
        private ApiTokenChecker $tokenChecker,
        private ProjectRepository $projectRepository,
        private MediaRepository $mediaRepository,
    ) {}

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

    #[Route('/{identifier}', name: 'show', methods: ['GET'])]
    public function show(Request $request, string $projectId, string $identifier): JsonResponse
    {
        $project = $this->resolvePublicProject($projectId);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        // UUID-only lookup for security
        $media = $this->mediaRepository->findOneBy(['uuid' => $identifier, 'project' => $project, 'deletedAt' => null]);

        if ($media === null) {
            return $this->json(['error' => 'File not found.'], 404);
        }

        return $this->json($this->serializeMedia($media));
    }

    /**
     * Resolves a project by UUID and enforces the publicApi gate for GET endpoints.
     * Returns the Project on success, or a JsonResponse on failure.
     */
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
