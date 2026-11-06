<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\BatchImportMessage;
use App\Repository\CollectionRepository;
use App\Repository\ProjectRepository;
use App\Service\AuditService;
use App\Service\ImportService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class BatchImportMessageHandler
{
    public function __construct(
        private ProjectRepository $projectRepo,
        private CollectionRepository $collectionRepo,
        private ImportService $importService,
        private ?AuditService $auditService = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function __invoke(BatchImportMessage $message): void
    {
        // 1. Expiration / TTL check
        if ($message->dispatchedAt > 0 && (time() - $message->dispatchedAt) > $message->ttlSeconds) {
            $msg = $this->importService->trans('batch.import.batch_expired', [], 'Batch import job has expired.');
            $this->logger->warning('Batch import job skipped due to expired TTL', [
                'batch_id'     => $message->batchId,
                'project_uuid' => $message->projectUuid,
            ]);
            $this->importService->failBatch($message->batchId, $msg, 'BATCH_EXPIRED', $message->projectUuid);
            $this->cleanupUploadFile($message->filePath);
            return;
        }

        // 2. Tenant isolation verification: find project
        $project = $this->projectRepo->findOneBy(['uuid' => $message->projectUuid]);
        if (!$project) {
            $msg = $this->importService->trans('project.not_found', [], 'Project not found.');
            $this->importService->failBatch($message->batchId, $msg, 'PROJECT_NOT_FOUND', $message->projectUuid);
            $this->cleanupUploadFile($message->filePath);
            return;
        }

        // 3. Tenant isolation verification: find collection strictly scoped to this project
        $collection = $this->collectionRepo->findOneBy([
            'project' => $project,
            'slug'    => $message->collectionSlug,
        ]);
        if (!$collection) {
            $msg = $this->importService->trans('batch.import.tenant_mismatch', [], 'Target collection does not belong to the project tenant.');
            $this->importService->failBatch($message->batchId, $msg, 'TENANT_MISMATCH', $message->projectUuid);
            $this->cleanupUploadFile($message->filePath);
            return;
        }

        // 4. Process the batch file
        try {
            $this->importService->processBatchFile(
                batchId: $message->batchId,
                project: $project,
                collection: $collection,
                filePath: $message->filePath,
                format: $message->format,
                options: $message->options,
                userId: $message->userId,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Batch import failed: ' . $e->getMessage(), [
                'batch_id'     => $message->batchId,
                'project_uuid' => $message->projectUuid,
                'error'        => $e->getMessage(),
            ]);
        } finally {
            $this->cleanupUploadFile($message->filePath);
        }
    }

    private function cleanupUploadFile(string $filePath): void
    {
        $uploadDir = realpath($this->importService->getUploadDir());
        $realFile = realpath($filePath);
        if ($uploadDir && $realFile && str_starts_with($realFile, $uploadDir) && file_exists($realFile)) {
            @unlink($realFile);
        }
    }
}
