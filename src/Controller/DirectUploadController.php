<?php

namespace App\Controller;

use App\Entity\AssetUploadIntent;
use App\Entity\User;
use App\Repository\AssetUploadIntentRepository;
use App\Repository\ProjectRepository;
use App\Service\AuditService;
use App\Service\DirectUploadService;
use App\Service\MediaSerializer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/projects/{projectUuid}/media/direct-upload', name: 'api_media_direct_upload_')]
class DirectUploadController extends AbstractController
{
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const MAX_PAYLOAD_BYTES = 524288; // 512 KB

    private const SECURITY_HEADERS = [
        'Cache-Control' => 'no-store, no-cache, must-revalidate',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly AssetUploadIntentRepository $intentRepository,
        private readonly DirectUploadService $directUploadService,
        private readonly MediaSerializer $mediaSerializer,
        #[Autowire(service: 'limiter.direct_upload_limiter')]
        private readonly ?RateLimiterFactory $directUploadLimiter = null,
        private readonly ?AuditService $auditService = null,
        private readonly ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters);
        }
        return $default ?? $id;
    }

    private function jsonResponse(array $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    #[Route('/intent', name: 'intent', methods: ['POST'])]
    public function createIntent(string $projectUuid, Request $request): JsonResponse
    {
        if ($this->directUploadLimiter !== null) {
            $identifier = $this->getUser()?->getUserIdentifier() ?? $request->getClientIp() ?: '127.0.0.1';
            if (false === $this->directUploadLimiter->create($identifier)->consume(1)->isAccepted()) {
                return $this->jsonResponse([
                    'error' => $this->trans('direct_upload.rate_limit_exceeded', [], 'Too many direct upload requests. Please retry later.'),
                    'code' => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            }
        }

        if (!$this->getUser() && !$request->attributes->get('_project')) {
            return $this->jsonResponse([
                'error' => $this->trans('direct_upload.auth_required', [], 'Authentication required'),
                'code' => 'AUTH_REQUIRED',
            ], 401);
        }

        if (!preg_match(self::UUID_REGEX, $projectUuid)) {
            return $this->jsonResponse([
                'error' => $this->trans('project.not_found', [], 'Project not found'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->jsonResponse([
                'error' => $this->trans('project.not_found', [], 'Project not found'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404);
        }
        if (!$project) {
            return $this->jsonResponse([
                'error' => $this->trans('project.not_found', [], 'Project not found'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.create', $project);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->jsonResponse([
                'error' => $this->trans('direct_upload.payload_too_large', [], 'Direct upload payload too large (max 512KB).'),
                'code' => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $payload = $request->toArray();
        } catch (\Throwable) {
            return $this->jsonResponse([
                'error' => $this->trans('direct_upload.invalid_json', [], 'Invalid JSON body'),
                'code' => 'INVALID_JSON',
            ], 400);
        }

        try {
            // Nettoyage opportuniste des intentions orphelines expirées pour ce projet
            try {
                $this->directUploadService->cleanupExpiredIntents($project, 10);
            } catch (\Throwable) {}

            $intent = $this->directUploadService->createIntent($project, $payload);
            $response = ['data' => $intent->toArray()];

            if ($intent->mode === AssetUploadIntent::MODE_SINGLE_PUT) {
                $response['presigned_url'] = $this->directUploadService->getPresignedPutUrl($intent);
            } else {
                $response['s3_multipart_upload_id'] = $intent->s3MultipartUploadId;
            }

            $this->auditService?->log('direct_upload_intent_create', $project, [
                'intent_uuid' => $intent->uuid?->toRfc4122(),
                'mode' => $intent->mode,
                'filename' => $intent->originalFileName,
                'file_size' => $intent->fileSize,
            ], null, 'success', null, $this->getUser()?->getUserIdentifier(), 'admin_api');

            return $this->jsonResponse($response, 201);
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'error' => $this->trans('direct_upload.intent_failed', ['%error%' => $e->getMessage()], $e->getMessage()),
                'code' => 'INTENT_FAILED',
            ], 400);
        }
    }

    #[Route('/sign-part', name: 'sign_part', methods: ['POST'])]
    public function signPart(string $projectUuid, Request $request): JsonResponse
    {
        if ($this->directUploadLimiter !== null) {
            $identifier = $this->getUser()?->getUserIdentifier() ?? $request->getClientIp() ?: '127.0.0.1';
            if (false === $this->directUploadLimiter->create($identifier)->consume(1)->isAccepted()) {
                return $this->jsonResponse([
                    'error' => $this->trans('direct_upload.rate_limit_exceeded', [], 'Too many direct upload requests. Please retry later.'),
                    'code' => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            }
        }

        if (!preg_match(self::UUID_REGEX, $projectUuid)) {
            return $this->jsonResponse([
                'error' => $this->trans('project.not_found', [], 'Project not found'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->jsonResponse([
                'error' => $this->trans('direct_upload.payload_too_large', [], 'Direct upload payload too large (max 512KB).'),
                'code' => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $payload = $request->toArray();
        } catch (\Throwable) {
            return $this->jsonResponse([
                'error' => $this->trans('direct_upload.invalid_json', [], 'Invalid JSON body'),
                'code' => 'INVALID_JSON',
            ], 400);
        }
        $intentUuid = $payload['intent_uuid'] ?? '';
        $partNumber = (int) ($payload['part_number'] ?? 1);

        if ($partNumber < 1 || $partNumber > 10000) {
            return $this->jsonResponse([
                'error' => $this->trans('direct_upload.invalid_part_number', [], 'Part number must be between 1 and 10000.'),
                'code' => 'INVALID_PART_NUMBER',
            ], 422);
        }

        try {
            $intent = $this->intentRepository->findPendingByUuid((string) $intentUuid);
        } catch (\Throwable) {
            return $this->jsonResponse([
                'error' => $this->trans('direct_upload.intent_not_found', [], 'Valid pending upload intent not found'),
                'code' => 'INTENT_NOT_FOUND',
            ], 404);
        }
        if (!$intent || $intent->project->uuid?->toRfc4122() !== $projectUuid) {
            return $this->jsonResponse([
                'error' => $this->trans('direct_upload.intent_not_found', [], 'Valid pending upload intent not found'),
                'code' => 'INTENT_NOT_FOUND',
            ], 404);
        }

        if (!$this->getUser() && !$request->attributes->get('_project')) {
            return $this->jsonResponse([
                'error' => $this->trans('direct_upload.auth_required', [], 'Authentication required'),
                'code' => 'AUTH_REQUIRED',
            ], 401);
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.create', $intent->project);
        }

        try {
            $url = $this->directUploadService->getPresignedPartUrl($intent, $partNumber);

            $this->auditService?->log('direct_upload_part_sign', $intent->project, [
                'intent_uuid' => $intent->uuid?->toRfc4122(),
                'part_number' => $partNumber,
            ], null, 'success', null, $this->getUser()?->getUserIdentifier(), 'admin_api');

            return $this->jsonResponse([
                'part_number'   => $partNumber,
                'presigned_url' => $url,
            ]);
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'error' => $this->trans('direct_upload.sign_part_failed', ['%error%' => $e->getMessage()], $e->getMessage()),
                'code' => 'SIGN_PART_FAILED',
            ], 400);
        }
    }

    #[Route('/complete', name: 'complete', methods: ['POST'])]
    public function complete(string $projectUuid, Request $request, #[CurrentUser] ?User $user = null): JsonResponse
    {
        if ($this->directUploadLimiter !== null) {
            $identifier = $this->getUser()?->getUserIdentifier() ?? $request->getClientIp() ?: '127.0.0.1';
            if (false === $this->directUploadLimiter->create($identifier)->consume(1)->isAccepted()) {
                return $this->jsonResponse([
                    'error' => $this->trans('direct_upload.rate_limit_exceeded', [], 'Too many direct upload requests. Please retry later.'),
                    'code' => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            }
        }

        if (!preg_match(self::UUID_REGEX, $projectUuid)) {
            return $this->jsonResponse([
                'error' => $this->trans('project.not_found', [], 'Project not found'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->jsonResponse([
                'error' => $this->trans('direct_upload.payload_too_large', [], 'Direct upload payload too large (max 512KB).'),
                'code' => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $payload = $request->toArray();
        } catch (\Throwable) {
            return $this->jsonResponse([
                'error' => $this->trans('direct_upload.invalid_json', [], 'Invalid JSON body'),
                'code' => 'INVALID_JSON',
            ], 400);
        }
        $intentUuid = $payload['intent_uuid'] ?? '';
        $parts = $payload['parts'] ?? [];
        $fileSize = isset($payload['file_size']) ? (int) $payload['file_size'] : null;

        if (!is_array($parts)) {
            return $this->jsonResponse([
                'error' => $this->trans('direct_upload.parts_invalid', [], 'Parts list must be a valid array.'),
                'code' => 'PARTS_INVALID',
            ], 422);
        }
        if ($fileSize !== null && $fileSize < 0) {
            return $this->jsonResponse([
                'error' => $this->trans('direct_upload.invalid_file_size', [], 'File size cannot be negative.'),
                'code' => 'INVALID_FILE_SIZE',
            ], 422);
        }

        try {
            $intent = $this->intentRepository->findPendingByUuid((string) $intentUuid);
        } catch (\Throwable) {
            return $this->jsonResponse([
                'error' => $this->trans('direct_upload.intent_not_found', [], 'Valid pending upload intent not found'),
                'code' => 'INTENT_NOT_FOUND',
            ], 404);
        }
        if (!$intent || $intent->project->uuid?->toRfc4122() !== $projectUuid) {
            return $this->jsonResponse([
                'error' => $this->trans('direct_upload.intent_not_found', [], 'Valid pending upload intent not found'),
                'code' => 'INTENT_NOT_FOUND',
            ], 404);
        }

        if (!$this->getUser() && !$request->attributes->get('_project')) {
            return $this->jsonResponse([
                'error' => $this->trans('direct_upload.auth_required', [], 'Authentication required'),
                'code' => 'AUTH_REQUIRED',
            ], 401);
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.create', $intent->project);
        }

        try {
            $media = $this->directUploadService->completeUpload($intent, $parts, $fileSize, $user);

            $this->auditService?->log('direct_upload_complete', $intent->project, [
                'intent_uuid' => $intent->uuid?->toRfc4122(),
                'media_id' => $media->id,
            ], ['id' => $media->id], 'success', null, $this->getUser()?->getUserIdentifier(), 'admin_api');

            return $this->jsonResponse([
                'data' => $this->mediaSerializer->serialize($media),
            ]);
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'error' => $this->trans('direct_upload.complete_failed', ['%error%' => $e->getMessage()], $e->getMessage()),
                'code' => 'COMPLETE_FAILED',
            ], 400);
        }
    }

    #[Route('/abort', name: 'abort', methods: ['POST'])]
    public function abort(string $projectUuid, Request $request): JsonResponse
    {
        if ($this->directUploadLimiter !== null) {
            $identifier = $this->getUser()?->getUserIdentifier() ?? $request->getClientIp() ?: '127.0.0.1';
            if (false === $this->directUploadLimiter->create($identifier)->consume(1)->isAccepted()) {
                return $this->jsonResponse([
                    'error' => $this->trans('direct_upload.rate_limit_exceeded', [], 'Too many direct upload requests. Please retry later.'),
                    'code' => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            }
        }

        if (!preg_match(self::UUID_REGEX, $projectUuid)) {
            return $this->jsonResponse([
                'error' => $this->trans('project.not_found', [], 'Project not found'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->jsonResponse([
                'error' => $this->trans('direct_upload.payload_too_large', [], 'Direct upload payload too large (max 512KB).'),
                'code' => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $payload = $request->toArray();
        } catch (\Throwable) {
            return $this->jsonResponse([
                'error' => $this->trans('direct_upload.invalid_json', [], 'Invalid JSON body'),
                'code' => 'INVALID_JSON',
            ], 400);
        }
        $intentUuid = $payload['intent_uuid'] ?? '';

        try {
            $intent = $this->intentRepository->findPendingByUuid((string) $intentUuid);
        } catch (\Throwable) {
            return $this->jsonResponse([
                'error' => $this->trans('direct_upload.intent_not_found', [], 'Valid pending upload intent not found'),
                'code' => 'INTENT_NOT_FOUND',
            ], 404);
        }
        if (!$intent || $intent->project->uuid?->toRfc4122() !== $projectUuid) {
            return $this->jsonResponse([
                'error' => $this->trans('direct_upload.intent_not_found', [], 'Valid pending upload intent not found'),
                'code' => 'INTENT_NOT_FOUND',
            ], 404);
        }

        if (!$this->getUser() && !$request->attributes->get('_project')) {
            return $this->jsonResponse([
                'error' => $this->trans('direct_upload.auth_required', [], 'Authentication required'),
                'code' => 'AUTH_REQUIRED',
            ], 401);
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.create', $intent->project);
        }

        try {
            $this->directUploadService->abortUpload($intent);

            $this->auditService?->log('direct_upload_abort', $intent->project, [
                'intent_uuid' => $intent->uuid?->toRfc4122(),
            ], null, 'success', null, $this->getUser()?->getUserIdentifier(), 'admin_api');

            return $this->jsonResponse(['status' => 'aborted']);
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'error' => $this->trans('direct_upload.abort_failed', ['%error%' => $e->getMessage()], $e->getMessage()),
                'code' => 'ABORT_FAILED',
            ], 400);
        }
    }

    #[Route('/cleanup-orphans', name: 'cleanup_orphans', methods: ['POST'])]
    public function cleanupOrphans(string $projectUuid, Request $request): JsonResponse
    {
        if ($this->directUploadLimiter !== null) {
            $identifier = $this->getUser()?->getUserIdentifier() ?? $request->getClientIp() ?: '127.0.0.1';
            if (false === $this->directUploadLimiter->create($identifier)->consume(1)->isAccepted()) {
                return $this->jsonResponse([
                    'error' => $this->trans('direct_upload.rate_limit_exceeded', [], 'Too many direct upload requests. Please retry later.'),
                    'code' => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            }
        }

        if (!$this->getUser() && !$request->attributes->get('_project')) {
            return $this->jsonResponse([
                'error' => $this->trans('direct_upload.auth_required', [], 'Authentication required'),
                'code' => 'AUTH_REQUIRED',
            ], 401);
        }

        if (!preg_match(self::UUID_REGEX, $projectUuid)) {
            return $this->jsonResponse([
                'error' => $this->trans('project.not_found', [], 'Project not found'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->jsonResponse([
                'error' => $this->trans('project.not_found', [], 'Project not found'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404);
        }
        if (!$project) {
            return $this->jsonResponse([
                'error' => $this->trans('project.not_found', [], 'Project not found'),
                'code' => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('content.create', $project);
        }

        try {
            $count = $this->directUploadService->cleanupExpiredIntents($project, 50);

            $this->auditService?->log('direct_upload_cleanup', $project, [
                'cleaned_count' => $count,
            ], null, 'success', null, $this->getUser()?->getUserIdentifier(), 'admin_api');

            return $this->jsonResponse([
                'status'        => 'success',
                'cleaned_count' => $count,
            ]);
        } catch (\Throwable $e) {
            return $this->jsonResponse([
                'error' => $this->trans('direct_upload.cleanup_failed', ['%error%' => $e->getMessage()], $e->getMessage()),
                'code' => 'CLEANUP_FAILED',
            ], 400);
        }
    }
}
