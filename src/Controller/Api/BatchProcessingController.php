<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Collection;
use App\Entity\Project;
use App\Message\BatchImportMessage;
use App\Repository\CollectionRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Service\ApiTokenChecker;
use App\Service\AuditService;
use App\Service\ImportService;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

#[OA\Tag(name: 'Batch Processing')]
#[Route('/api/projects/{uuid}/batch', name: 'api_batch_')]
class BatchProcessingController extends AbstractController
{
    use ProjectAwareControllerTrait;

    private const SECURITY_HEADERS = [
        'Cache-Control'             => 'no-store, no-cache, must-revalidate, private',
        'Pragma'                    => 'no-cache',
        'X-Content-Type-Options'    => 'nosniff',
        'X-Frame-Options'           => 'DENY',
        'Referrer-Policy'           => 'no-referrer',
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        'Content-Security-Policy'   => "default-src 'none'; frame-ancestors 'none';",
    ];

    public function __construct(
        private ProjectRepository $projectRepository,
        private ProjectMemberRepository $memberRepo,
        private ApiTokenChecker $tokenChecker,
        private Security $security,
        private CollectionRepository $collectionRepo,
        private ImportService $importService,
        private MessageBusInterface $messageBus,
        private EntityManagerInterface $em,
        private ?RateLimiterFactory $importLimiter = null,
        private ?TranslatorInterface $translator = null,
        private ?AuditService $auditService = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            $translated = $this->translator->trans($id, $parameters, 'messages');
            if ($translated !== $id) {
                return $translated;
            }
        }
        return $default ?? $id;
    }

    private function resolveUserIdentifier(Request $request): string
    {
        try {
            $user = $this->getUser();
            if ($user !== null) {
                return $user->getUserIdentifier();
            }
        } catch (\Throwable) {}

        return $request->getClientIp() ?? 'anonymous';
    }

    #[Route('/{collectionSlug}/import', name: 'import', methods: ['POST'])]
    public function import(string $uuid, string $collectionSlug, Request $request): Response
    {
        $projectOrResponse = $this->resolveProject($uuid, $request, true);
        if ($projectOrResponse instanceof JsonResponse) {
            return $projectOrResponse;
        }
        /** @var Project $project */
        $project = $projectOrResponse;

        // Rate limiting check
        $identifier = $this->resolveUserIdentifier($request);
        if ($this->importLimiter !== null) {
            $limit = $this->importLimiter->create($identifier)->consume(1);
            if (!$limit->isAccepted()) {
                $retryAfter = max(1, ($limit->getRetryAfter()?->getTimestamp() ?? (time() + 60)) - time());
                return new JsonResponse([
                    'error' => $this->trans('admin_api.import.too_many_requests', [], 'Too many import requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429, array_merge(self::SECURITY_HEADERS, [
                    'Retry-After'           => (string) $retryAfter,
                    'X-RateLimit-Limit'     => (string) $limit->getLimit(),
                    'X-RateLimit-Remaining' => (string) $limit->getRemainingTokens(),
                    'X-RateLimit-Reset'     => (string) ($limit->getRetryAfter()?->getTimestamp() ?? (time() + 60)),
                ]));
            }
        }

        // Validate collectionSlug format
        if (!preg_match('/^[a-z0-9_-]{1,64}$/i', $collectionSlug)) {
            return new JsonResponse([
                'error' => $this->trans('admin_api.collection.invalid_slug_format', [], 'Collection slug can only contain letters, numbers, underscores and hyphens.'),
                'code'  => 'INVALID_SLUG_FORMAT',
            ], 422, self::SECURITY_HEADERS);
        }

        // Tenant isolation: verify collection strictly belongs to this project
        $collection = $this->collectionRepo->findOneBy([
            'project' => $project,
            'slug'    => $collectionSlug,
        ]);
        if (!$collection) {
            return new JsonResponse([
                'error' => $this->trans('batch.import.tenant_mismatch', [], 'Target collection does not belong to the project tenant.'),
                'code'  => 'COLLECTION_NOT_FOUND',
            ], 404, self::SECURITY_HEADERS);
        }

        // Extract upload file or raw payload
        $uploadedFile = $request->files->get('file');
        $format = 'csv';
        $tempSourcePath = null;
        $isTemporaryCreated = false;

        if ($uploadedFile instanceof UploadedFile) {
            if (!$uploadedFile->isValid()) {
                return new JsonResponse([
                    'error' => $this->trans('project.export_import.upload_error', ['%error%' => $uploadedFile->getErrorMessage()], 'Upload error.'),
                ], 422, self::SECURITY_HEADERS);
            }

            if ($uploadedFile->getSize() > ImportService::MAX_FILE_SIZE) {
                return new JsonResponse([
                    'error' => $this->trans('batch.import.max_file_size_exceeded', ['%max%' => '50'], 'File exceeds max size of 50MB.'),
                ], 413, self::SECURITY_HEADERS);
            }

            $ext = strtolower($uploadedFile->getClientOriginalExtension());
            $format = match ($ext) {
                'jsonl', 'ndjson' => 'jsonl',
                'csv'             => 'csv',
                'zip'             => 'zip',
                'gz', 'gzip'      => 'gz',
                default           => 'unknown',
            };

            if ($format === 'unknown') {
                return new JsonResponse([
                    'error' => $this->trans('batch.import.invalid_format', [], 'Unsupported batch format. Allowed: csv, jsonl, zip, gz.'),
                    'code'  => 'INVALID_FORMAT',
                ], 422, self::SECURITY_HEADERS);
            }

            $tempSourcePath = $uploadedFile->getPathname();
        } else {
            // Raw body payload (CSV or JSONL)
            $contentType = strtolower((string) $request->headers->get('Content-Type', ''));
            if (str_contains($contentType, 'jsonl') || str_contains($contentType, 'ndjson')) {
                $format = 'jsonl';
            } elseif (str_contains($contentType, 'csv') || str_contains($contentType, 'text/plain')) {
                $format = 'csv';
            } elseif (str_contains($contentType, 'application/json')) {
                $format = 'jsonl';
            } else {
                return new JsonResponse([
                    'error' => $this->trans('batch.import.invalid_format', [], 'Unsupported batch format. Allowed: csv, jsonl, zip, gz.'),
                    'code'  => 'INVALID_FORMAT',
                ], 415, self::SECURITY_HEADERS);
            }

            $content = $request->getContent();
            if ($content === '' || $content === false) {
                return new JsonResponse([
                    'error' => $this->trans('project.export_import.no_file', [], 'Empty request payload.'),
                ], 422, self::SECURITY_HEADERS);
            }

            if (strlen($content) > ImportService::MAX_FILE_SIZE) {
                return new JsonResponse([
                    'error' => $this->trans('batch.import.max_file_size_exceeded', ['%max%' => '50'], 'File exceeds max size of 50MB.'),
                ], 413, self::SECURITY_HEADERS);
            }

            $tempSourcePath = sys_get_temp_dir() . '/jambo-batch-raw-' . uniqid() . '.' . $format;
            file_put_contents($tempSourcePath, $content);
            $isTemporaryCreated = true;
        }

        $batchId = Uuid::v4()->toString();
        $targetUploadPath = $this->importService->getUploadDir() . '/' . $batchId . '.' . $format;

        // Move or copy file to the secure batch uploads directory
        if ($uploadedFile instanceof UploadedFile) {
            $uploadedFile->move($this->importService->getUploadDir(), $batchId . '.' . $format);
        } else {
            rename($tempSourcePath, $targetUploadPath);
            $isTemporaryCreated = false;
        }

        $options = $request->request->all();
        $fileSize = filesize($targetUploadPath);
        $isAsync = $request->query->getBoolean('async') ||
                   $request->headers->get('X-Async') === 'true' ||
                   $fileSize > 2097152; // Files > 2MB automatically processed asynchronously

        $this->importService->initBatchStatus($batchId, $project->uuid->toString(), $collection->slug, 0, $format);

        if ($isAsync) {
            // Asynchronous queue execution via Symfony Messenger
            $message = new BatchImportMessage(
                batchId: $batchId,
                projectUuid: $project->uuid->toString(),
                collectionSlug: $collection->slug,
                filePath: $targetUploadPath,
                format: $format,
                options: $options,
                userId: $identifier,
            );
            $this->messageBus->dispatch($message);

            return new JsonResponse([
                'data' => [
                    'batch_id'   => $batchId,
                    'status'     => 'pending',
                    'format'     => $format,
                    'status_url' => sprintf('/api/projects/%s/batch/%s/status', $project->uuid->toString(), $batchId),
                ],
            ], 202, self::SECURITY_HEADERS);
        }

        // Synchronous execution for small files
        try {
            $result = $this->importService->processBatchFile(
                batchId: $batchId,
                project: $project,
                collection: $collection,
                filePath: $targetUploadPath,
                format: $format,
                options: $options,
                userId: $identifier,
            );

            return new JsonResponse([
                'data' => [
                    'batch_id' => $batchId,
                    'status'   => 'completed',
                    'created'  => $result['created'],
                    'errors'   => $result['errors'],
                    'total'    => $result['total'],
                ],
            ], 201, self::SECURITY_HEADERS);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => $e->getMessage(),
                'code'  => 'IMPORT_ERROR',
            ], 422, self::SECURITY_HEADERS);
        } finally {
            if (file_exists($targetUploadPath)) {
                @unlink($targetUploadPath);
            }
            if ($isTemporaryCreated && $tempSourcePath && file_exists($tempSourcePath)) {
                @unlink($tempSourcePath);
            }
        }
    }

    #[Route('/{batchId}/status', name: 'status', methods: ['GET'])]
    public function status(string $uuid, string $batchId, Request $request): JsonResponse
    {
        $projectOrResponse = $this->resolveProject($uuid, $request, false);
        if ($projectOrResponse instanceof JsonResponse) {
            return $projectOrResponse;
        }
        /** @var Project $project */
        $project = $projectOrResponse;

        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $batchId)) {
            return new JsonResponse([
                'error' => $this->trans('batch.import.batch_not_found', [], 'Batch job not found or does not belong to this project.'),
                'code'  => 'BATCH_NOT_FOUND',
            ], 404, self::SECURITY_HEADERS);
        }

        // Multi-tenant check: verify batch belongs to this project
        $status = $this->importService->getBatchStatus($batchId, $project->uuid->toString());
        if ($status === null) {
            return new JsonResponse([
                'error' => $this->trans('batch.import.batch_not_found', [], 'Batch job not found or does not belong to this project.'),
                'code'  => 'BATCH_NOT_FOUND',
            ], 404, self::SECURITY_HEADERS);
        }

        return new JsonResponse(['data' => $status], 200, self::SECURITY_HEADERS);
    }

    #[Route('/{collectionSlug}/export', name: 'export', methods: ['GET', 'POST'])]
    public function export(string $uuid, string $collectionSlug, Request $request): Response
    {
        $projectOrResponse = $this->resolveProject($uuid, $request, false);
        if ($projectOrResponse instanceof JsonResponse) {
            return $projectOrResponse;
        }
        /** @var Project $project */
        $project = $projectOrResponse;

        if (!preg_match('/^[a-z0-9_-]{1,64}$/i', $collectionSlug)) {
            return new JsonResponse([
                'error' => $this->trans('admin_api.collection.invalid_slug_format', [], 'Invalid collection slug format.'),
                'code'  => 'INVALID_SLUG_FORMAT',
            ], 422, self::SECURITY_HEADERS);
        }

        $collection = $this->collectionRepo->findOneBy([
            'project' => $project,
            'slug'    => $collectionSlug,
        ]);
        if (!$collection) {
            return new JsonResponse([
                'error' => $this->trans('batch.import.tenant_mismatch', [], 'Collection not found in this project.'),
                'code'  => 'COLLECTION_NOT_FOUND',
            ], 404, self::SECURITY_HEADERS);
        }

        $format = strtolower((string) ($request->query->get('format') ?? $request->request->get('format') ?? 'csv'));
        if (!in_array($format, ['csv', 'jsonl'], true)) {
            return new JsonResponse([
                'error' => $this->trans('batch.export.invalid_format', [], 'Unsupported export format. Allowed: csv, jsonl.'),
                'code'  => 'INVALID_FORMAT',
            ], 422, self::SECURITY_HEADERS);
        }

        $filename = sprintf('export-%s-%s-%s.%s',
            preg_replace('/[^a-zA-Z0-9_-]/', '-', $collection->slug),
            $format,
            (new \DateTimeImmutable())->format('Y-m-d-His'),
            $format === 'jsonl' ? 'jsonl' : 'csv'
        );

        $contentType = $format === 'jsonl' ? 'application/x-ndjson; charset=UTF-8' : 'text/csv; charset=UTF-8';

        $response = new StreamedResponse(function () use ($collection, $format) {
            $this->importService->exportCollection(
                collection: $collection,
                format: $format,
                writeChunk: function (string $chunk) {
                    echo $chunk;
                    flush();
                }
            );
        });

        foreach (self::SECURITY_HEADERS as $k => $v) {
            $response->headers->set($k, $v);
        }
        $response->headers->set('Content-Type', $contentType);
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }
}
