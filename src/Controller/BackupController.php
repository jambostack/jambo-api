<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AuditService;
use App\Service\Backup\BackupManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/admin/backups', name: 'api_admin_backups_')]
class BackupController extends AbstractController
{
    private const SECURITY_HEADERS = [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        'Pragma' => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
    ];

    public function __construct(
        private readonly BackupManager $backupManager,
        private readonly ?AuditService $auditService = null,
        private readonly ?RateLimiterFactory $tokenLimiter = null,
        private readonly ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters, 'messages');
        }
        return $default ?? $id;
    }

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    /**
     * Strict verification: Super Admin privileges are mandatory for backup operations.
     */
    private function denyNonSuperAdmin(?string $action = null): ?JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->secureJson(['error' => $this->trans('backup.auth_required', [], 'Authentication required.')], 401);
        }

        if (!in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            $this->auditService?->log(
                toolName: 'backup_access_denied',
                project: null,
                input: ['attempted_action' => $action],
                output: ['status' => 'denied', 'reason' => 'super_admin_required'],
                status: 'failure',
                errorMessage: 'Super administrator privileges required.',
                createdBy: $user->getUserIdentifier(),
                source: 'admin_api',
            );
            $this->auditService?->flush();

            return $this->secureJson(['error' => $this->trans('backup.super_admin_required', [], 'Access denied: super administrator privileges required.')], 403);
        }

        return null;
    }

    private function checkRateLimit(Request $request): ?JsonResponse
    {
        if ($this->tokenLimiter !== null) {
            $identifier = $this->getUser()?->getUserIdentifier() ?: ($request->getClientIp() ?: '127.0.0.1');
            if (false === $this->tokenLimiter->create($identifier)->consume(1)->isAccepted()) {
                return $this->secureJson(['error' => $this->trans('backup.rate_limit_exceeded', [], 'Too many backup requests. Please retry later.')], 429);
            }
        }
        return null;
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        if ($error = $this->denyNonSuperAdmin('list')) {
            return $error;
        }

        if ($rateLimit = $this->checkRateLimit($request)) {
            return $rateLimit;
        }

        $backups = $this->backupManager->listBackups();
        return $this->secureJson(['data' => $backups]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if ($error = $this->denyNonSuperAdmin('create')) {
            return $error;
        }

        if ($rateLimit = $this->checkRateLimit($request)) {
            return $rateLimit;
        }

        $body = null;
        $content = $request->getContent();
        if ($content !== '') {
            try {
                $body = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                return $this->secureJson(['error' => $this->trans('backup.invalid_json', [], 'Invalid JSON payload.')], 400);
            }
        }

        $rawLabel = is_array($body) && isset($body['label']) ? (string) $body['label'] : 'manual';
        if (preg_match('/[\x00-\x1F\x7F]/', $rawLabel)) {
            return $this->secureJson(['error' => $this->trans('backup.label_control_chars', [], 'Backup label contains invalid control characters.')], 422);
        }

        $label = trim($rawLabel);
        if ($label === '') {
            $label = 'manual';
        }

        if (strlen($label) > 50) {
            return $this->secureJson(['error' => $this->trans('backup.label_too_long', [], 'Backup label cannot exceed 50 characters.')], 422);
        }

        if (!preg_match('/^[a-zA-Z0-9_\-\. ]+$/', $label)) {
            return $this->secureJson(['error' => $this->trans('backup.label_invalid_characters', [], 'Backup label contains invalid characters.')], 422);
        }

        $backup = $this->backupManager->createBackup($label);

        $this->auditService?->log(
            toolName: 'backup_create',
            project: null,
            input: ['label' => $label],
            output: ['filename' => $backup['filename'], 'size' => $backup['size']],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'admin_api',
        );
        $this->auditService?->flush();

        return $this->secureJson([
            'data'    => $backup,
            'message' => $this->trans('backup.created_success', [], 'Backup created successfully.'),
        ], 201);
    }

    #[Route('/{filename}/download', name: 'download', methods: ['GET'], requirements: ['filename' => 'backup-[a-zA-Z0-9_\-]{8,128}\.(sql|sql\.gz|zip)'])]
    public function download(string $filename, Request $request): Response
    {
        if ($error = $this->denyNonSuperAdmin('download')) {
            return $error;
        }

        if ($rateLimit = $this->checkRateLimit($request)) {
            return $rateLimit;
        }

        $filePath = $this->backupManager->resolveBackupFile($filename);
        if ($filePath === null) {
            return $this->secureJson(['error' => $this->trans('backup.file_not_found', [], 'Backup file not found.')], 404);
        }

        $this->auditService?->log(
            toolName: 'backup_download',
            project: null,
            input: ['filename' => $filename],
            output: ['status' => 'downloading'],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'admin_api',
        );
        $this->auditService?->flush();

        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
        foreach (self::SECURITY_HEADERS as $k => $v) {
            $response->headers->set($k, $v);
        }

        return $response;
    }

    #[Route('/restore', name: 'restore', methods: ['POST'])]
    public function restore(Request $request): JsonResponse
    {
        if ($error = $this->denyNonSuperAdmin('restore')) {
            return $error;
        }

        if ($rateLimit = $this->checkRateLimit($request)) {
            return $rateLimit;
        }

        $content = $request->getContent();
        try {
            $body = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('backup.invalid_json', [], 'Invalid JSON payload.')], 400);
        }

        if (!is_array($body)) {
            return $this->secureJson(['error' => $this->trans('backup.invalid_json', [], 'Invalid JSON payload.')], 400);
        }

        $filename = trim((string) ($body['filename'] ?? ''));
        if ($filename === '') {
            return $this->secureJson(['error' => $this->trans('backup.filename_required', [], 'Backup filename is required.')], 422);
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $filename) || !preg_match(BackupManager::FILENAME_REGEX, $filename)) {
            return $this->secureJson(['error' => $this->trans('backup.file_not_found', [], 'Backup file not found or invalid filename.')], 404);
        }

        $confirm = (bool) ($body['confirm'] ?? false);
        if (!$confirm) {
            return $this->secureJson(['error' => $this->trans('backup.confirmation_required', [], 'Explicit confirmation (confirm=true) is required for restore.')], 422);
        }

        $filePath = $this->backupManager->resolveBackupFile($filename);
        if ($filePath === null) {
            return $this->secureJson(['error' => $this->trans('backup.file_not_found', [], 'Backup file not found or invalid filename.')], 404);
        }

        $result = $this->backupManager->restoreBackup($filePath, $confirm);
        if (!$result['success']) {
            $this->auditService?->log(
                toolName: 'backup_restore',
                project: null,
                input: ['filename' => $filename],
                output: null,
                status: 'failure',
                errorMessage: $result['error'] ?? 'Unknown',
                createdBy: $this->getUser()?->getUserIdentifier(),
                source: 'admin_api',
            );
            $this->auditService?->flush();

            return $this->secureJson(['error' => $result['error'] ?? $this->trans('backup.restore_failed', [], 'Restore failed.')], 422);
        }

        $this->auditService?->log(
            toolName: 'backup_restore',
            project: null,
            input: ['filename' => $filename],
            output: ['status' => 'restored'],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'admin_api',
        );
        $this->auditService?->flush();

        return $this->secureJson([
            'status'  => 'success',
            'message' => $this->trans('backup.restore_success', [], 'Backup verified and restoration completed successfully.'),
        ]);
    }

    #[Route('/{filename}', name: 'delete', methods: ['DELETE'], requirements: ['filename' => 'backup-[a-zA-Z0-9_\-]{8,128}\.(sql|sql\.gz|zip)'])]
    public function delete(string $filename, Request $request): JsonResponse
    {
        if ($error = $this->denyNonSuperAdmin('delete')) {
            return $error;
        }

        if ($rateLimit = $this->checkRateLimit($request)) {
            return $rateLimit;
        }

        $deleted = $this->backupManager->deleteBackup($filename);
        if (!$deleted) {
            $this->auditService?->log(
                toolName: 'backup_delete_rejected',
                project: null,
                input: ['filename' => $filename],
                output: ['status' => 'failed'],
                status: 'failure',
                errorMessage: 'Backup file not found or deletion failed.',
                createdBy: $this->getUser()?->getUserIdentifier(),
                source: 'admin_api',
            );
            $this->auditService?->flush();

            return $this->secureJson(['error' => $this->trans('backup.delete_failed', [], 'Backup file not found or deletion failed.')], 404);
        }

        $this->auditService?->log(
            toolName: 'backup_delete',
            project: null,
            input: ['filename' => $filename],
            output: ['status' => 'deleted'],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'admin_api',
        );
        $this->auditService?->flush();

        return $this->secureJson(['status' => 'deleted', 'filename' => $filename]);
    }
}
