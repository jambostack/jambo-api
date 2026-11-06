<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\ContentFieldValue;
use App\Entity\Field;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ImportService
{
    public const MAX_FILE_SIZE = 52428800;          // 50 MB
    public const MAX_UNCOMPRESSED_SIZE = 104857600; // 100 MB
    public const MAX_COMPRESSION_RATIO = 100;       // 100:1 ratio anti-bomb
    public const MAX_ROWS = 10000;
    public const MAX_COLUMNS = 100;
    public const MAX_CELL_LENGTH = 65536;          // 64 KB
    public const MAX_JSON_DEPTH = 32;
    public const CHUNK_SIZE = 50;
    public const MEMORY_THRESHOLD_PERCENT = 85;

    public function __construct(
        private EntityManagerInterface $em,
        private SluggerInterface $slugger,
        private string $projectDir,
        private ?EavFieldHelperService $fieldHelper = null,
        private ?TranslatorInterface $translator = null,
        private ?AuditService $auditService = null,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            $translated = $this->translator->trans($id, $parameters, 'messages');
            if ($translated !== $id) {
                return $translated;
            }
        }
        return $default ?? $id;
    }

    public function getBatchDir(): string
    {
        $dir = $this->projectDir . '/var/batch';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public function getUploadDir(): string
    {
        $dir = $this->projectDir . '/var/batch_uploads';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private function getTempDir(): string
    {
        $dir = $this->projectDir . '/var/tmp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public function initBatchStatus(
        string $batchId,
        string $projectUuid,
        string $collectionSlug,
        int $totalRows = 0,
        string $format = 'csv'
    ): void {
        $statusFile = $this->getBatchDir() . '/' . $batchId . '.json';
        $data = [
            'batch_id'        => $batchId,
            'project_uuid'    => $projectUuid,
            'collection_slug' => $collectionSlug,
            'format'          => $format,
            'status'          => 'pending',
            'created_at'      => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'updated_at'      => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'total_rows'      => $totalRows,
            'processed_rows'  => 0,
            'created_rows'    => 0,
            'errors_count'    => 0,
            'errors'          => [],
        ];
        file_put_contents($statusFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    public function updateBatchProgress(
        string $batchId,
        int $processed,
        int $created,
        int $errorsCount,
        array $recentErrors = []
    ): void {
        $statusFile = $this->getBatchDir() . '/' . $batchId . '.json';
        if (!file_exists($statusFile)) {
            return;
        }

        $data = json_decode((string) file_get_contents($statusFile), true);
        if (!is_array($data)) {
            return;
        }

        $data['status'] = 'processing';
        $data['updated_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $data['processed_rows'] = $processed;
        $data['created_rows'] = $created;
        $data['errors_count'] = $errorsCount;
        if (!empty($recentErrors)) {
            $data['errors'] = array_slice(array_merge($data['errors'] ?? [], $recentErrors), -100);
        }

        file_put_contents($statusFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    public function completeBatch(string $batchId, int $created, array $errors, int $durationMs): void
    {
        $statusFile = $this->getBatchDir() . '/' . $batchId . '.json';
        if (!file_exists($statusFile)) {
            return;
        }

        $data = json_decode((string) file_get_contents($statusFile), true);
        if (!is_array($data)) {
            return;
        }

        $data['status'] = 'completed';
        $data['completed_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $data['updated_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $data['created_rows'] = $created;
        $data['errors_count'] = count($errors);
        $data['errors'] = array_slice($errors, -100);
        $data['duration_ms'] = $durationMs;

        file_put_contents($statusFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    public function failBatch(string $batchId, string $errorMessage, string $errorCode = 'IMPORT_FAILED', ?string $projectUuid = null): void
    {
        $statusFile = $this->getBatchDir() . '/' . $batchId . '.json';
        $data = file_exists($statusFile) ? (json_decode((string) file_get_contents($statusFile), true) ?: []) : [];
        if (!is_array($data)) {
            $data = [];
        }

        $data['batch_id'] = $batchId;
        if ($projectUuid !== null && !isset($data['project_uuid'])) {
            $data['project_uuid'] = $projectUuid;
        }
        $data['status'] = 'failed';
        $data['failed_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $data['updated_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $data['error_message'] = $errorMessage;
        $data['error_code'] = $errorCode;

        file_put_contents($statusFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    public function getBatchStatus(string $batchId, ?string $expectedProjectUuid = null): ?array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $batchId)) {
            return null;
        }

        $statusFile = $this->getBatchDir() . '/' . $batchId . '.json';
        if (!file_exists($statusFile)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($statusFile), true);
        if (!is_array($data)) {
            return null;
        }

        // Multi-tenant isolation: verify project UUID matches!
        if ($expectedProjectUuid !== null && ($data['project_uuid'] ?? null) !== $expectedProjectUuid) {
            return null;
        }

        return $data;
    }

    public function isMemoryNearLimit(): bool
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1' || empty($limit)) {
            return false;
        }

        $limitBytes = $this->parseBytes((string) $limit);
        if ($limitBytes <= 0) {
            return false;
        }

        $usedBytes = memory_get_usage(true);
        $ratio = ($usedBytes / $limitBytes) * 100;

        if ($ratio >= self::MEMORY_THRESHOLD_PERCENT) {
            gc_collect_cycles();
            $usedBytes = memory_get_usage(true);
            $ratio = ($usedBytes / $limitBytes) * 100;
            return $ratio >= self::MEMORY_THRESHOLD_PERCENT;
        }

        return false;
    }

    private function parseBytes(string $val): int
    {
        $val = trim($val);
        $last = strtolower($val[strlen($val) - 1]);
        $num = (int) $val;
        return match ($last) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => (int) $val,
        };
    }

    public function sanitizeFormulaInjection(string $val): string
    {
        if ($val === '') {
            return $val;
        }
        $first = $val[0];
        if (in_array($first, ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $val;
        }
        return $val;
    }

    public function sanitizeControlChars(string $val): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $val);
    }

    public function validateAndExtractZip(string $zipPath): string
    {
        $tempDir = $this->getTempDir() . '/jambo-batch-zip-' . uniqid();
        @mkdir($tempDir, 0775, true);

        try {
            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new \RuntimeException($this->trans('batch.import.decompression_bomb_detected', [], 'Cannot open ZIP archive.'));
            }

            $maxFiles = 500;
            $totalUncompressed = 0;

            if ($zip->numFiles > $maxFiles) {
                $zip->close();
                throw new \RuntimeException($this->trans('batch.import.decompression_bomb_detected', [], 'ZIP archive contains too many files.'));
            }

            $blockedExtensions = [
                'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps',
                'sh', 'bash', 'exe', 'bat', 'cmd', 'com', 'cgi', 'pl', 'py',
                'htaccess', 'htpasswd', 'ini', 'conf', 'env', 'jsp', 'asp', 'aspx',
            ];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!$stat) {
                    continue;
                }
                $entryName = $stat['name'];

                // Reject symbolic links in ZIP archives
                $extAttr = $stat['external_attributes'] ?? 0;
                $mode = ($extAttr >> 16) & 0170000;
                if ($mode === 0120000) {
                    $zip->close();
                    throw new \RuntimeException($this->trans('batch.import.decompression_bomb_detected', [], 'Symbolic links are forbidden in ZIP archives.'));
                }

                // Prevent Zip Slip / path traversal, null bytes and Windows drive paths
                if (
                    str_contains($entryName, "\0") ||
                    str_contains($entryName, '../') ||
                    str_contains($entryName, '..\\') ||
                    str_contains($entryName, '..') ||
                    str_starts_with($entryName, '/') ||
                    str_starts_with($entryName, '\\') ||
                    preg_match('/^[a-zA-Z]:/', $entryName)
                ) {
                    $zip->close();
                    throw new \RuntimeException($this->trans('batch.import.decompression_bomb_detected', [], 'Path traversal detected in archive.'));
                }

                // Reject dangerous extensions
                $entryExt = strtolower(pathinfo($entryName, PATHINFO_EXTENSION));
                if (in_array($entryExt, $blockedExtensions, true)) {
                    $zip->close();
                    throw new \RuntimeException($this->trans('batch.import.invalid_format', [], 'Dangerous file extension forbidden in archive.'));
                }

                // Reject hidden files
                $normalizedParts = explode('/', str_replace('\\', '/', $entryName));
                foreach ($normalizedParts as $part) {
                    if (str_starts_with($part, '.') && $part !== '.' && $part !== '..') {
                        $zip->close();
                        throw new \RuntimeException($this->trans('batch.import.invalid_format', [], 'Hidden files forbidden in archive.'));
                    }
                }

                $compSize = $stat['comp_size'] ?? 0;
                $uncompSize = $stat['size'] ?? 0;

                // Anti-decompression bomb: compression ratio check
                if ($compSize > 0 && ($uncompSize / $compSize) > self::MAX_COMPRESSION_RATIO && $uncompSize > 1048576) {
                    $ratio = (int) ($uncompSize / $compSize);
                    $zip->close();
                    throw new \RuntimeException($this->trans('batch.import.ratio_exceeded', ['%ratio%' => (string) $ratio], "Compression ratio ({$ratio}:1) exceeds limit."));
                }

                $totalUncompressed += $uncompSize;
                if ($totalUncompressed > self::MAX_UNCOMPRESSED_SIZE) {
                    $zip->close();
                    throw new \RuntimeException($this->trans('batch.import.decompression_bomb_detected', [], 'Uncompressed size exceeds safety limit.'));
                }
            }

            $zip->extractTo($tempDir);
            $zip->close();

            return $tempDir;
        } catch (\Throwable $e) {
            $this->removeDir($tempDir);
            throw $e;
        }
    }

    public function validateAndDecompressGz(string $gzPath): string
    {
        $compressedSize = filesize($gzPath);
        if ($compressedSize <= 0) {
            throw new \RuntimeException($this->trans('batch.import.csv_malformed', ['%line%' => 1], 'Empty GZIP file.'));
        }

        $gz = @gzopen($gzPath, 'rb');
        if (!$gz) {
            throw new \RuntimeException($this->trans('batch.import.decompression_bomb_detected', [], 'Cannot open GZIP stream.'));
        }

        $decompressedPath = $this->getTempDir() . '/jambo-batch-gz-' . uniqid() . '.dat';
        $out = @fopen($decompressedPath, 'wb');
        if (!$out) {
            gzclose($gz);
            throw new \RuntimeException('Failed to open output destination for decompressed stream.');
        }

        $totalBytes = 0;
        try {
            while (!gzeof($gz)) {
                $chunk = gzread($gz, 65536);
                if ($chunk === false) {
                    break;
                }
                $chunkLen = strlen($chunk);
                $totalBytes += $chunkLen;

                if ($totalBytes > self::MAX_UNCOMPRESSED_SIZE) {
                    throw new \RuntimeException($this->trans('batch.import.decompression_bomb_detected', [], 'Decompressed size exceeds safety limit.'));
                }

                if ($compressedSize > 0 && ($totalBytes / $compressedSize) > self::MAX_COMPRESSION_RATIO && $totalBytes > 1048576) {
                    $ratio = (int) ($totalBytes / $compressedSize);
                    throw new \RuntimeException($this->trans('batch.import.ratio_exceeded', ['%ratio%' => (string) $ratio], "Compression ratio ({$ratio}:1) exceeds limit."));
                }

                fwrite($out, $chunk);
            }
        } finally {
            gzclose($gz);
            fclose($out);
        }

        return $decompressedPath;
    }

    public function processBatchFile(
        string $batchId,
        Project $project,
        Collection $collection,
        string $filePath,
        string $format,
        array $options = [],
        ?string $userId = null
    ): array {
        $startTime = hrtime(true);

        // 1. Multi-tenant tenant boundary verification
        if ($collection->project->id !== $project->id && $collection->project->uuid?->toString() !== $project->uuid?->toString()) {
            $msg = $this->trans('batch.import.tenant_mismatch', [], 'Target collection does not belong to the project tenant.');
            $this->failBatch($batchId, $msg, 'TENANT_MISMATCH');
            throw new \RuntimeException($msg);
        }

        if (!file_exists($filePath) || !is_readable($filePath)) {
            $msg = $this->trans('batch.import.csv_malformed', ['%line%' => 0], 'File not found or unreadable.');
            $this->failBatch($batchId, $msg, 'FILE_NOT_FOUND');
            throw new \RuntimeException($msg);
        }

        $fileSize = filesize($filePath);
        if ($fileSize > self::MAX_FILE_SIZE) {
            $maxMb = (int) (self::MAX_FILE_SIZE / 1048576);
            $msg = $this->trans('batch.import.max_file_size_exceeded', ['%max%' => (string) $maxMb], "File exceeds max size of {$maxMb}MB.");
            $this->failBatch($batchId, $msg, 'FILE_TOO_LARGE');
            throw new \RuntimeException($msg);
        }

        $this->updateBatchProgress($batchId, 0, 0, 0);

        // Map collection fields by slug
        $fieldMap = [];
        $fields = $this->em->getRepository(Field::class)->findBy([
            'collection' => $collection,
            'deletedAt'  => null,
        ]);
        foreach ($fields as $field) {
            $fieldMap[$field->slug] = $field;
        }

        $tempFilesToClean = [];
        $actualDataPath = $filePath;
        $actualFormat = $format;

        try {
            if ($format === 'zip') {
                $extractedDir = $this->validateAndExtractZip($filePath);
                $tempFilesToClean[] = ['dir', $extractedDir];

                // Locate data file: first .jsonl or .csv
                $found = null;
                $files = scandir($extractedDir) ?: [];
                foreach ($files as $f) {
                    if (str_ends_with(strtolower($f), '.jsonl')) {
                        $found = [$extractedDir . '/' . $f, 'jsonl'];
                        break;
                    }
                    if (str_ends_with(strtolower($f), '.csv')) {
                        $found = [$extractedDir . '/' . $f, 'csv'];
                        break;
                    }
                }
                if (!$found) {
                    throw new \RuntimeException($this->trans('batch.import.invalid_format', [], 'No CSV or JSONL data found in ZIP archive.'));
                }
                [$actualDataPath, $actualFormat] = $found;
            } elseif ($format === 'gz') {
                $decompressedPath = $this->validateAndDecompressGz($filePath);
                $tempFilesToClean[] = ['file', $decompressedPath];
                $actualDataPath = $decompressedPath;
                // Determine format from sub-extension or first bytes
                $firstBytes = file_get_contents($decompressedPath, false, null, 0, 100) ?: '';
                if (str_starts_with(trim($firstBytes), '{')) {
                    $actualFormat = 'jsonl';
                } else {
                    $actualFormat = 'csv';
                }
            }

            $result = match ($actualFormat) {
                'jsonl' => $this->processJsonlStream($batchId, $project, $collection, $fieldMap, $actualDataPath, $options),
                'csv'   => $this->processCsvStream($batchId, $project, $collection, $fieldMap, $actualDataPath, $options),
                default => throw new \RuntimeException($this->trans('batch.import.invalid_format', [], 'Unsupported batch format.')),
            };

            $endTime = hrtime(true);
            $durationMs = (int) (($endTime - $startTime) / 1e6);

            $this->completeBatch($batchId, $result['created'], $result['errors'], $durationMs);

            $this->auditService?->log(
                toolName: 'batch_import',
                project: $project,
                input: [
                    'batch_id'   => $batchId,
                    'collection' => $collection->slug,
                    'format'     => $actualFormat,
                    'total_rows' => $result['total'],
                ],
                output: [
                    'created'      => $result['created'],
                    'errors_count' => count($result['errors']),
                    'duration_ms'  => $durationMs,
                ],
                status: empty($result['errors']) ? 'success' : 'partial',
                createdBy: $userId ?? 'batch_system',
                source: 'batch_processing',
            );
            $this->auditService?->flush();

            return $result;
        } catch (\Throwable $e) {
            $this->failBatch($batchId, $e->getMessage(), 'IMPORT_FAILED');
            $this->auditService?->log(
                toolName: 'batch_import',
                project: $project,
                input: ['batch_id' => $batchId, 'collection' => $collection->slug, 'format' => $format],
                output: ['error' => $e->getMessage()],
                status: 'failure',
                createdBy: $userId ?? 'batch_system',
                source: 'batch_processing',
            );
            $this->auditService?->flush();
            throw $e;
        } finally {
            foreach ($tempFilesToClean as [$type, $target]) {
                if ($type === 'dir') {
                    $this->removeDir($target);
                } elseif ($type === 'file' && file_exists($target)) {
                    @unlink($target);
                }
            }
        }
    }

    private function processCsvStream(
        string $batchId,
        Project $project,
        Collection $collection,
        array $fieldMap,
        string $filePath,
        array $options
    ): array {
        $handle = @fopen($filePath, 'r');
        if (!$handle) {
            throw new \RuntimeException($this->trans('batch.import.csv_malformed', ['%line%' => 0], 'Cannot open CSV file.'));
        }

        $delimiter = (string) ($options['delimiter'] ?? ',');
        if (strlen($delimiter) !== 1) {
            $delimiter = ',';
        }

        $header = fgetcsv($handle, 0, $delimiter, '"', '\\');
        if (!$header || empty($header)) {
            fclose($handle);
            throw new \RuntimeException($this->trans('batch.import.csv_malformed', ['%line%' => 1], 'Missing CSV header row.'));
        }

        if (count($header) > self::MAX_COLUMNS) {
            fclose($handle);
            throw new \RuntimeException($this->trans('batch.import.csv_malformed', ['%line%' => 1], 'CSV header contains too many columns.'));
        }

        // Clean header strings
        $header = array_map(fn($col) => trim($this->sanitizeControlChars((string) $col)), $header);

        $fieldMapping = [];
        $rawMapping = $options['field_mapping'] ?? [];
        if (!empty($rawMapping) && is_array($rawMapping)) {
            foreach ($rawMapping as $csvCol => $targetSlug) {
                if (isset($fieldMap[$targetSlug])) {
                    $fieldMapping[$csvCol] = $targetSlug;
                }
            }
        } else {
            foreach ($header as $col) {
                if (isset($fieldMap[$col])) {
                    $fieldMapping[$col] = $col;
                }
            }
        }

        $locale = (string) ($options['locale'] ?? $project->defaultLocale);
        $status = (string) ($options['status'] ?? 'draft');
        $sanitizeFormula = (bool) ($options['sanitize_formula'] ?? true);

        $created = 0;
        $errors = [];
        $rowCount = 0;
        $chunkEntries = [];

        try {
            while (($rowValues = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
                $rowCount++;
                if ($rowCount > self::MAX_ROWS) {
                    $errors[] = [
                        'row'   => $rowCount,
                        'error' => $this->trans('batch.import.max_rows_exceeded', ['%max%' => (string) self::MAX_ROWS], "Max rows (" . self::MAX_ROWS . ") exceeded."),
                    ];
                    break;
                }

                if (count($rowValues) > count($header) + 20) {
                    $errors[] = ['row' => $rowCount, 'error' => $this->trans('batch.import.csv_malformed', ['%line%' => $rowCount], 'Cell count mismatch with header.')];
                    continue;
                }

                $row = [];
                foreach ($header as $idx => $colName) {
                    $val = (string) ($rowValues[$idx] ?? '');
                    if (strlen($val) > self::MAX_CELL_LENGTH) {
                        $val = substr($val, 0, self::MAX_CELL_LENGTH);
                    }
                    $val = $this->sanitizeControlChars($val);
                    if ($sanitizeFormula) {
                        $val = $this->sanitizeFormulaInjection($val);
                    }
                    $row[$colName] = $val;
                }

                try {
                    $entry = $this->createContentEntry($project, $collection, $fieldMap, $fieldMapping, $row, $rowCount, $locale, $status);
                    $this->em->persist($entry);
                    $chunkEntries[] = $entry;
                    $created++;

                    if (count($chunkEntries) >= self::CHUNK_SIZE) {
                        $this->em->flush();
                        foreach ($chunkEntries as $e) {
                            $this->detachEntryWithFieldValues($e);
                        }
                        $chunkEntries = [];

                        gc_collect_cycles();
                        $this->updateBatchProgress($batchId, $rowCount, $created, count($errors));

                        if ($this->isMemoryNearLimit()) {
                            throw new \RuntimeException($this->trans('batch.import.memory_exhausted', [], 'Batch aborted due to memory threshold limit.'));
                        }
                    }
                } catch (\Throwable $rowException) {
                    $errors[] = ['row' => $rowCount, 'error' => $rowException->getMessage()];
                }
            }

            if (!empty($chunkEntries)) {
                $this->em->flush();
                foreach ($chunkEntries as $e) {
                    $this->detachEntryWithFieldValues($e);
                }
                $chunkEntries = [];
            }
        } finally {
            fclose($handle);
        }

        return ['created' => $created, 'errors' => $errors, 'total' => $rowCount];
    }

    private function processJsonlStream(
        string $batchId,
        Project $project,
        Collection $collection,
        array $fieldMap,
        string $filePath,
        array $options
    ): array {
        $handle = @fopen($filePath, 'r');
        if (!$handle) {
            throw new \RuntimeException($this->trans('batch.import.invalid_format', [], 'Cannot open JSONL file.'));
        }

        $locale = (string) ($options['locale'] ?? $project->defaultLocale);
        $status = (string) ($options['status'] ?? 'draft');
        $sanitizeFormula = (bool) ($options['sanitize_formula'] ?? true);

        $created = 0;
        $errors = [];
        $rowCount = 0;
        $chunkEntries = [];

        try {
            while (($line = fgets($handle)) !== false) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }
                $rowCount++;

                if ($rowCount > self::MAX_ROWS) {
                    $errors[] = [
                        'row'   => $rowCount,
                        'error' => $this->trans('batch.import.max_rows_exceeded', ['%max%' => (string) self::MAX_ROWS], "Max rows (" . self::MAX_ROWS . ") exceeded."),
                    ];
                    break;
                }

                // Check JSON structure depth
                try {
                    $row = json_decode($trimmed, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
                } catch (\JsonException $je) {
                    $errors[] = [
                        'row'   => $rowCount,
                        'error' => $this->trans('batch.import.jsonl_malformed_line', ['%line%' => (string) $rowCount, '%error%' => $je->getMessage()], "Malformed JSON line {$rowCount}: " . $je->getMessage()),
                    ];
                    continue;
                }

                if (!is_array($row)) {
                    $errors[] = [
                        'row'   => $rowCount,
                        'error' => $this->trans('batch.import.jsonl_malformed_line', ['%line%' => (string) $rowCount, '%error%' => 'Expected JSON object.'], "Line {$rowCount} must be a JSON object."),
                    ];
                    continue;
                }

                // Sanitize string cells
                $sanitizedRow = [];
                foreach ($row as $k => $v) {
                    $keyStr = $this->sanitizeControlChars((string) $k);
                    if (is_string($v)) {
                        if (strlen($v) > self::MAX_CELL_LENGTH) {
                            $v = substr($v, 0, self::MAX_CELL_LENGTH);
                        }
                        $v = $this->sanitizeControlChars($v);
                        if ($sanitizeFormula) {
                            $v = $this->sanitizeFormulaInjection($v);
                        }
                    }
                    $sanitizedRow[$keyStr] = $v;
                }

                // Mapping: direct field slug matches
                $fieldMapping = [];
                foreach (array_keys($sanitizedRow) as $k) {
                    if (isset($fieldMap[$k])) {
                        $fieldMapping[$k] = $k;
                    }
                }

                try {
                    $entry = $this->createContentEntry($project, $collection, $fieldMap, $fieldMapping, $sanitizedRow, $rowCount, $locale, $status);
                    $this->em->persist($entry);
                    $chunkEntries[] = $entry;
                    $created++;

                    if (count($chunkEntries) >= self::CHUNK_SIZE) {
                        $this->em->flush();
                        foreach ($chunkEntries as $e) {
                            $this->detachEntryWithFieldValues($e);
                        }
                        $chunkEntries = [];

                        gc_collect_cycles();
                        $this->updateBatchProgress($batchId, $rowCount, $created, count($errors));

                        if ($this->isMemoryNearLimit()) {
                            throw new \RuntimeException($this->trans('batch.import.memory_exhausted', [], 'Batch aborted due to memory threshold limit.'));
                        }
                    }
                } catch (\Throwable $rowException) {
                    $errors[] = ['row' => $rowCount, 'error' => $rowException->getMessage()];
                }
            }

            if (!empty($chunkEntries)) {
                $this->em->flush();
                foreach ($chunkEntries as $e) {
                    $this->detachEntryWithFieldValues($e);
                }
                $chunkEntries = [];
            }
        } finally {
            fclose($handle);
        }

        return ['created' => $created, 'errors' => $errors, 'total' => $rowCount];
    }

    private function createContentEntry(
        Project $project,
        Collection $collection,
        array $fieldMap,
        array $fieldMapping,
        array $row,
        int $index,
        string $locale,
        string $status
    ): ContentEntry {
        $entry = new ContentEntry();
        $entry->project = $project;
        $entry->collection = $collection;
        $entry->locale = $locale;
        $entry->status = $status;

        $slugSource = '';
        foreach ($fieldMapping as $srcCol => $fSlug) {
            if (isset($row[$srcCol]) && is_scalar($row[$srcCol]) && (string) $row[$srcCol] !== '' && $slugSource === '') {
                $slugSource = (string) $row[$srcCol];
            }
        }
        $slugBase = $slugSource ?: "batch-{$index}-" . bin2hex(random_bytes(3));
        $entry->slug = $this->ensureUniqueSlug($collection, $slugBase, $locale);

        foreach ($fieldMapping as $srcCol => $targetSlug) {
            if (!isset($fieldMap[$targetSlug]) || !array_key_exists($srcCol, $row)) {
                continue;
            }
            /** @var Field $field */
            $field = $fieldMap[$targetSlug];
            $val = $row[$srcCol];

            $fv = new ContentFieldValue();
            $fv->contentEntry = $entry;
            $fv->field = $field;
            $fv->fieldType = $field->type;

            if ($this->fieldHelper !== null) {
                $this->fieldHelper->setFieldValue($fv, $field->type, $val);
            } else {
                match ($field->type) {
                    'number', 'decimal' => $fv->numberValue = is_numeric($val) ? (string) $val : null,
                    'boolean' => $fv->booleanValue = in_array(strtolower((string) $val), ['1', 'true', 'yes', 'on'], true),
                    default => $fv->textValue = is_scalar($val) ? (string) $val : json_encode($val),
                };
            }

            $this->em->persist($fv);
            $entry->fieldValues->add($fv);
        }

        return $entry;
    }

    private function detachEntryWithFieldValues(ContentEntry $entry): void
    {
        $this->em->detach($entry);
        foreach ($entry->fieldValues as $fv) {
            $this->em->detach($fv);
        }
    }

    private function ensureUniqueSlug(Collection $collection, string $baseSlug, string $locale): string
    {
        $base = (string) $this->slugger->slug($baseSlug)->lower()->truncate(50, '');
        if ($base === '') {
            $base = 'entry';
        }
        $slug = $base;
        $counter = 1;

        while ($counter <= 20) {
            $qb = $this->em->createQueryBuilder();
            $qb->select('COUNT(e.id)')
               ->from(ContentEntry::class, 'e')
               ->where('e.collection = :collection')
               ->andWhere('e.slug = :slug')
               ->andWhere('e.locale = :locale')
               ->setParameter('collection', $collection)
               ->setParameter('slug', $slug)
               ->setParameter('locale', $locale);

            if ((int) $qb->getQuery()->getSingleScalarResult() === 0) {
                return $slug;
            }

            $suffix = '-' . $counter;
            $slug = substr($base, 0, max(1, 50 - strlen($suffix))) . $suffix;
            $counter++;
        }

        return substr($base, 0, 40) . '-' . bin2hex(random_bytes(4));
    }

    public function exportCollection(
        Collection $collection,
        string $format,
        callable $writeChunk,
        array $options = []
    ): int {
        $fields = $this->em->getRepository(Field::class)->findBy([
            'collection' => $collection,
            'deletedAt'  => null,
        ]);

        $pageSize = 100;
        $offset = 0;
        $totalExported = 0;
        $sanitizeFormula = (bool) ($options['sanitize_formula'] ?? true);

        if ($format === 'csv') {
            $headers = ['id', 'uuid', 'slug', 'locale', 'status', 'created_at'];
            foreach ($fields as $f) {
                $headers[] = $f->slug;
            }
            $writeChunk($this->arrayToCsvLine($headers));
        }

        while (true) {
            $qb = $this->em->createQueryBuilder();
            $qb->select('e')
               ->from(ContentEntry::class, 'e')
               ->where('e.collection = :col')
               ->setParameter('col', $collection)
               ->orderBy('e.id', 'ASC')
               ->setFirstResult($offset)
               ->setMaxResults($pageSize);

            /** @var ContentEntry[] $entries */
            $entries = $qb->getQuery()->getResult();
            if (empty($entries)) {
                break;
            }

            foreach ($entries as $entry) {
                $entryData = [
                    'id'         => $entry->id,
                    'uuid'       => $entry->uuid?->toString(),
                    'slug'       => $entry->slug,
                    'locale'     => $entry->locale,
                    'status'     => $entry->status,
                    'created_at' => $entry->createdAt?->format(\DateTimeInterface::ATOM),
                ];

                $valuesBySlug = [];
                foreach ($entry->fieldValues as $fv) {
                    $slug = $fv->field->slug;
                    $valuesBySlug[$slug] = $fv->getResolvedValue();
                }

                foreach ($fields as $f) {
                    $val = $valuesBySlug[$f->slug] ?? null;
                    if ($format === 'csv') {
                        $valStr = is_scalar($val) ? (string) $val : (is_array($val) ? json_encode($val) : '');
                        if ($sanitizeFormula) {
                            $valStr = $this->sanitizeFormulaInjection($valStr);
                        }
                        $entryData[$f->slug] = $valStr;
                    } else {
                        $entryData[$f->slug] = $val;
                    }
                }

                if ($format === 'csv') {
                    $writeChunk($this->arrayToCsvLine(array_values($entryData)));
                } else {
                    $writeChunk(json_encode($entryData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
                }

                $totalExported++;
                $this->detachEntryWithFieldValues($entry);
            }

            $offset += count($entries);
            gc_collect_cycles();
        }

        return $totalExported;
    }

    private function arrayToCsvLine(array $fields): string
    {
        $f = fopen('php://memory', 'r+');
        fputcsv($f, $fields, ',', '"', "\\");
        rewind($f);
        $line = stream_get_contents($f);
        fclose($f);
        return $line ?: '';
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
        }
        @rmdir($dir);
    }
}
