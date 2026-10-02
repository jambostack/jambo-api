<?php

namespace App\Controller;

use App\Entity\AssetUploadIntent;
use App\Entity\User;
use App\Repository\AssetUploadIntentRepository;
use App\Repository\ProjectRepository;
use App\Service\DirectUploadService;
use App\Service\MediaSerializer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/projects/{projectUuid}/media/direct-upload', name: 'api_media_direct_upload_')]
class DirectUploadController extends AbstractController
{
    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly AssetUploadIntentRepository $intentRepository,
        private readonly DirectUploadService $directUploadService,
        private readonly MediaSerializer $mediaSerializer,
    ) {}

    #[Route('/intent', name: 'intent', methods: ['POST'])]
    public function createIntent(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return new JsonResponse(['error' => 'Project not found'], 404);
        }

        $payload = json_decode($request->getContent(), true) ?? [];

        try {
            $intent = $this->directUploadService->createIntent($project, $payload);
            $response = ['data' => $intent->toArray()];

            if ($intent->mode === AssetUploadIntent::MODE_SINGLE_PUT) {
                $response['presigned_url'] = $this->directUploadService->getPresignedPutUrl($intent);
            } else {
                $response['s3_multipart_upload_id'] = $intent->s3MultipartUploadId;
            }

            return new JsonResponse($response, 201);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    #[Route('/sign-part', name: 'sign_part', methods: ['POST'])]
    public function signPart(string $projectUuid, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?? [];
        $intentUuid = $payload['intent_uuid'] ?? '';
        $partNumber = (int) ($payload['part_number'] ?? 1);

        $intent = $this->intentRepository->findPendingByUuid($intentUuid);
        if (!$intent || $intent->project->uuid?->toRfc4122() !== $projectUuid) {
            return new JsonResponse(['error' => 'Valid pending upload intent not found'], 404);
        }

        try {
            $url = $this->directUploadService->getPresignedPartUrl($intent, $partNumber);
            return new JsonResponse([
                'part_number'   => $partNumber,
                'presigned_url' => $url,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    #[Route('/complete', name: 'complete', methods: ['POST'])]
    public function complete(string $projectUuid, Request $request, #[CurrentUser] ?User $user = null): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?? [];
        $intentUuid = $payload['intent_uuid'] ?? '';
        $parts = $payload['parts'] ?? [];
        $fileSize = isset($payload['file_size']) ? (int) $payload['file_size'] : null;

        $intent = $this->intentRepository->findPendingByUuid($intentUuid);
        if (!$intent || $intent->project->uuid?->toRfc4122() !== $projectUuid) {
            return new JsonResponse(['error' => 'Valid pending upload intent not found'], 404);
        }

        try {
            $media = $this->directUploadService->completeUpload($intent, $parts, $fileSize, $user);
            return new JsonResponse([
                'data' => $this->mediaSerializer->serialize($media),
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    #[Route('/abort', name: 'abort', methods: ['POST'])]
    public function abort(string $projectUuid, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true) ?? [];
        $intentUuid = $payload['intent_uuid'] ?? '';

        $intent = $this->intentRepository->findPendingByUuid($intentUuid);
        if (!$intent || $intent->project->uuid?->toRfc4122() !== $projectUuid) {
            return new JsonResponse(['error' => 'Valid pending upload intent not found'], 404);
        }

        $this->directUploadService->abortUpload($intent);
        return new JsonResponse(['status' => 'aborted']);
    }
}
