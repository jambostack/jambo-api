<?php

namespace App\Service\ExportImport\Import;

use App\Dto\ConflictItem;
use App\Dto\ImportOptions;
use App\Entity\AssetMetadata;
use App\Entity\Media;
use App\Entity\Project;
use App\Service\ExportImport\ImportHandlerInterface;
use Symfony\Component\Uid\Uuid;

class MediaImportHandler implements ImportHandlerInterface
{
    /** @var Media[] */
    private array $importedMedia = [];

    public function __construct(private string $projectDir) {}

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
        if (!isset($data['media'])) {
            return;
        }

        $targetMediaDir = $this->projectDir . '/public/uploads/media/' . $project->uuid?->toString();
        if (!is_dir($targetMediaDir)) {
            mkdir($targetMediaDir, 0777, true);
        }

        $this->importedMedia = [];

        foreach ($data['media'] as $mediaData) {
            $oldUuid = $mediaData['uuid'] ?? null;

            $media = new Media();
            $media->project = $project;

            if ($options->strategy === 'new_uuids' || !$oldUuid) {
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

            // Copy binary file from extracted archive to project's upload directory
            $sourceFile = $extractedDir . '/media/' . $mediaData['file_name'];
            if (file_exists($sourceFile)) {
                copy($sourceFile, $targetMediaDir . '/' . $mediaData['file_name']);
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
