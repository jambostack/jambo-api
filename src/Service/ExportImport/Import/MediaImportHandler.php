<?php

namespace App\Service\ExportImport\Import;

use App\Dto\ConflictItem;
use App\Dto\ImportOptions;
use App\Entity\AssetMetadata;
use App\Entity\Media;
use App\Entity\Project;
use App\Repository\MediaRepository;
use App\Service\ExportImport\ImportHandlerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

class MediaImportHandler implements ImportHandlerInterface
{
    public const MAX_TOTAL_MEDIA_BYTES = 262144000; // 250 MB
    public const MAX_MEDIA_ENTITIES = 2000;

    /** @var Media[] */
    private array $importedMedia = [];

    public function __construct(
        private string $projectDir,
        private ?TranslatorInterface $translator = null,
        private ?MediaRepository $mediaRepository = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters, 'messages');
        }
        return $default ?? $id;
    }

    public static function getOptionKey(): string
    {
        return 'media';
    }

    /** @return Media[] */
    public function getImportedMedia(): array
    {
        return $this->importedMedia;
    }

    public function import(Project $project, string $extractedDir, ImportOptions $options, array &$uuidMap): void
    {
        $path = $extractedDir . '/media.json';
        if (!file_exists($path)) {
            return;
        }

        $data = json_decode(file_get_contents($path), true);
        if (!isset($data['media']) || !is_array($data['media'])) {
            return;
        }

        if (count($data['media']) > 2000) {
            throw new \RuntimeException($this->trans('import.media.too_many_files', ['%max%' => 2000], 'media.json contains too many files (max 2000).'));
        }

        $targetMediaDir = $this->projectDir . '/public/uploads/media/' . $project->uuid?->toString();
        if (!is_dir($targetMediaDir)) {
            mkdir($targetMediaDir, 0777, true);
        }

        $this->importedMedia = [];
        $totalImportedBytes = 0;

        $quotaMb = null;
        if (isset($project->settings['storage_quota_mb'])) {
            $quotaMb = (int) $project->settings['storage_quota_mb'];
        } elseif (isset($_ENV['PROJECT_STORAGE_QUOTA_MB'])) {
            $quotaMb = (int) $_ENV['PROJECT_STORAGE_QUOTA_MB'];
        }
        $currentUsedBytes = ($this->mediaRepository !== null && $project->id !== null)
            ? $this->mediaRepository->getTotalSizeByProject($project)
            : 0;

        foreach ($data['media'] as $mediaData) {
            $oldUuid = $mediaData['uuid'] ?? null;

            $media = new Media();
            $media->project = $project;

            // Always generate fresh UUIDs for a brand-new project (same reasoning
            // as ContentImportHandler — global unique constraint on media.uuid).
            if ($options->strategy === 'new_uuids' || $options->createNewProject || !$oldUuid) {
                $media->uuid = Uuid::v4();
            } else {
                $media->uuid = Uuid::fromString($oldUuid);
            }

            $newUuid = $media->uuid->toString();
            if ($oldUuid) {
                $uuidMap[$oldUuid] = $newUuid;
            }

            $media->fileName = $mediaData['file_name'];
            $media->originalName = $mediaData['original_name'];
            $media->mimeType = $mediaData['mime_type'] ?? null;
            $media->fileSize = $mediaData['file_size'] ?? null;
            $media->alt = $mediaData['alt'] ?? null;
            $media->caption = $mediaData['caption'] ?? null;

            // Copy binary file from extracted archive to project's upload directory with strict security checks
            $fileName = basename((string) ($mediaData['file_name'] ?? ''));
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $blockedExtensions = [
                'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps',
                'sh', 'bash', 'exe', 'bat', 'cmd', 'com', 'cgi', 'pl', 'py',
                'htaccess', 'htpasswd', 'ini', 'conf', 'env', 'jsp', 'asp', 'aspx',
            ];

            $hasBlockedExtension = false;
            foreach (explode('.', $fileName) as $part) {
                if (in_array(strtolower($part), $blockedExtensions, true)) {
                    $hasBlockedExtension = true;
                    break;
                }
            }

            $isSafeFilename = $fileName !== ''
                && $fileName !== '.'
                && $fileName !== '..'
                && !str_starts_with($fileName, '.')
                && !str_contains($fileName, "\0")
                && !in_array($ext, $blockedExtensions, true)
                && !$hasBlockedExtension
                && preg_match('/^[a-zA-Z0-9_\-\.]+$/', $fileName);

            if ($isSafeFilename) {
                $sourceFile = $extractedDir . '/media/' . $fileName;
                $targetFile = $targetMediaDir . '/' . $fileName;

                if (file_exists($sourceFile) && is_file($sourceFile) && !is_link($sourceFile)) {
                    $realSource = realpath($sourceFile);
                    $mediaBase = realpath($extractedDir . '/media');
                    // Verify path is within media directory and size is reasonable (max 50MB)
                    if ($realSource && $mediaBase && str_starts_with($realSource, $mediaBase) && filesize($sourceFile) <= 52428800) {
                        $fileSize = filesize($sourceFile) ?: 0;
                        if ($totalImportedBytes + $fileSize > self::MAX_TOTAL_MEDIA_BYTES) {
                            throw new \RuntimeException($this->trans('import.media.cumulative_size_exceeded', ['%max%' => (int)(self::MAX_TOTAL_MEDIA_BYTES / (1024 * 1024))], 'Cumulative imported media size exceeds 250MB limit.'));
                        }
                        if ($quotaMb !== null && $quotaMb > 0) {
                            $quotaBytes = $quotaMb * 1024 * 1024;
                            if (($currentUsedBytes + $totalImportedBytes + $fileSize) > $quotaBytes) {
                                $usedMb = (int) ceil(($currentUsedBytes + $totalImportedBytes + $fileSize) / (1024 * 1024));
                                throw new \RuntimeException($this->trans(
                                    'upload.error.quota_exceeded',
                                    ['%used%' => (string) $usedMb, '%limit%' => (string) $quotaMb],
                                    sprintf('Project storage quota exceeded (%d MB used / %d MB limit).', $usedMb, $quotaMb)
                                ));
                            }
                        }

                        // Anti-polyglot check: reject files containing PHP opening tags
                        $head = @file_get_contents($sourceFile, false, null, 0, 1024);
                        if ($head === false || (!str_contains($head, '<?php') && !str_contains($head, '<?='))) {
                            copy($sourceFile, $targetFile);
                            $totalImportedBytes += $fileSize;
                        }
                    }
                }
            }

            // Restore asset metadata (width/height) when present
            if (isset($mediaData['width']) || isset($mediaData['height'])) {
                $metadata = new AssetMetadata();
                $metadata->media = $media;
                $metadata->width = $mediaData['width'] ?? null;
                $metadata->height = $mediaData['height'] ?? null;
                $media->metadata = $metadata;
            }

            $this->importedMedia[] = $media;
        }
    }

    public function previewConflicts(Project $project, string $extractedDir): array
    {
        $path = $extractedDir . '/media.json';
        if (!file_exists($path)) {
            return [];
        }

        $data = json_decode(file_get_contents($path), true);
        if (!isset($data['media'])) {
            return [];
        }

        // Project has no getMedia() method, so UUID collision detection against
        // existing media requires EntityManager (not available in this handler).
        // The orchestrator is responsible for detecting DB-level UUID collisions
        // via EntityManager before invoking this handler.
        return [];
    }
}
