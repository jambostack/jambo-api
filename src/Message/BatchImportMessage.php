<?php

declare(strict_types=1);

namespace App\Message;

class BatchImportMessage
{
    public readonly string $batchId;
    public readonly string $projectUuid;
    public readonly string $collectionSlug;
    public readonly string $filePath;
    public readonly string $format;
    public readonly array $options;
    public readonly ?string $userId;
    public readonly int $dispatchedAt;
    public readonly int $ttlSeconds;

    public function __construct(
        string $batchId,
        string $projectUuid,
        string $collectionSlug,
        string $filePath,
        string $format = 'csv',
        array $options = [],
        ?string $userId = null,
        int $dispatchedAt = 0,
        int $ttlSeconds = 3600,
    ) {
        $batchId = trim($batchId);
        if ($batchId === '' || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $batchId)) {
            throw new \InvalidArgumentException('Invalid batchId: must be alphanumeric (1-64 characters).');
        }

        $projectUuid = trim($projectUuid);
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $projectUuid)) {
            throw new \InvalidArgumentException('Invalid projectUuid format.');
        }

        $collectionSlug = trim($collectionSlug);
        if ($collectionSlug === '' || !preg_match('/^[a-z0-9_-]{1,64}$/i', $collectionSlug)) {
            throw new \InvalidArgumentException('Invalid collectionSlug: must be 1-64 characters (letters, numbers, underscore, hyphen).');
        }

        $format = strtolower(trim($format));
        if (!in_array($format, ['csv', 'jsonl', 'zip', 'gz'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported batch format "%s". Allowed: csv, jsonl, zip, gz.', $format));
        }

        $filePath = trim($filePath);
        if ($filePath === '' || str_contains($filePath, "\0") || str_contains($filePath, '..')) {
            throw new \InvalidArgumentException('Invalid filePath: path traversal or null byte detected.');
        }

        if ($ttlSeconds <= 0) {
            throw new \InvalidArgumentException('ttlSeconds must be strictly positive.');
        }

        $this->batchId = $batchId;
        $this->projectUuid = $projectUuid;
        $this->collectionSlug = $collectionSlug;
        $this->filePath = $filePath;
        $this->format = $format;
        $this->options = $options;
        $this->userId = $userId;
        $this->dispatchedAt = $dispatchedAt > 0 ? $dispatchedAt : time();
        $this->ttlSeconds = $ttlSeconds;
    }
}
