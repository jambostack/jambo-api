<?php

namespace App\Service\ExportImport\Export;

use App\Entity\Media;
use App\Entity\Project;
use App\Service\ExportImport\ExportHandlerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class MediaExportHandler implements ExportHandlerInterface
{
    public const MAX_MEDIA_ENTITIES = 1000;
    public const MAX_TOTAL_MEDIA_BYTES = 262144000; // 250 MB

    public function __construct(
        private EntityManagerInterface $em,
        private string $projectDir,
        private ?TranslatorInterface $translator = null,
    ) {}

    public static function getOptionKey(): string
    {
        return 'media';
    }

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters, 'messages');
        }
        return $default ?? $id;
    }

    public function export(Project $project, string $tempDir): array
    {
        $allMedia = $this->em->getRepository(Media::class)->findBy(['project' => $project]);

        // Filter out soft-deleted media
        $mediaEntities = array_values(array_filter($allMedia, fn(Media $m) => !$m->isDeleted()));

        if (count($mediaEntities) > self::MAX_MEDIA_ENTITIES) {
            throw new \RuntimeException($this->trans('export.media.too_many_files', ['%max%' => self::MAX_MEDIA_ENTITIES], sprintf('Export contains too many media files (max %d).', self::MAX_MEDIA_ENTITIES)));
        }

        $mediaData = [];
        $copiedFiles = [];
        $totalBytesCopied = 0;

        $projectUuidStr = $project->uuid?->toString();
        $realBaseDir = null;
        if ($projectUuidStr !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $projectUuidStr)) {
            $rawBaseDir = $this->projectDir . '/public/uploads/media/' . $projectUuidStr;
            $realBaseDir = is_dir($rawBaseDir) ? realpath($rawBaseDir) : false;
        }

        foreach ($mediaEntities as $media) {
            $mediaItem = [
                'uuid'          => $media->uuid?->toString(),
                'file_name'     => $media->fileName,
                'original_name' => $media->originalName,
                'mime_type'     => $media->mimeType,
                'file_size'     => $media->fileSize,
                'alt'           => $media->alt,
                'caption'       => $media->caption,
                'width'         => $media->metadata?->width,
                'height'        => $media->metadata?->height,
                'created_at'    => $media->createdAt->format(\DateTimeInterface::ATOM),
            ];

            $mediaData[] = $mediaItem;

            // Copy binary file safely from project upload directory to temp export directory
            if ($media->fileName !== null && $realBaseDir !== false && $realBaseDir !== null) {
                $rawFileName = (string) $media->fileName;

                // Anti path-traversal & null-byte rejection
                if (
                    str_contains($rawFileName, "\0") ||
                    str_contains($rawFileName, '..') ||
                    str_starts_with($rawFileName, '/') ||
                    str_starts_with($rawFileName, '\\')
                ) {
                    continue;
                }

                $safeName = basename($rawFileName);
                $sourcePath = $realBaseDir . DIRECTORY_SEPARATOR . $safeName;

                if (is_file($sourcePath) && !is_link($sourcePath)) {
                    $realSourcePath = realpath($sourcePath);
                    if ($realSourcePath !== false && str_starts_with($realSourcePath, $realBaseDir . DIRECTORY_SEPARATOR)) {
                        $fileSize = filesize($realSourcePath) ?: 0;
                        if ($totalBytesCopied + $fileSize > self::MAX_TOTAL_MEDIA_BYTES) {
                            throw new \RuntimeException($this->trans('export.media.size_exceeded', ['%max%' => (int)(self::MAX_TOTAL_MEDIA_BYTES / (1024 * 1024))], 'Cumulative media export size exceeds 250MB limit.'));
                        }

                        $mediaExportDir = $tempDir . '/media';
                        if (!is_dir($mediaExportDir)) {
                            mkdir($mediaExportDir, 0700, true);
                        }
                        $destPath = $mediaExportDir . '/' . $safeName;
                        if (copy($realSourcePath, $destPath)) {
                            $copiedFiles[] = 'media/' . $safeName;
                            $totalBytesCopied += $fileSize;
                        }
                    }
                }
            }
        }

        // Write metadata JSON
        $json = json_encode(['media' => $mediaData], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($tempDir . '/media.json', $json);

        $files = array_merge(['media.json'], $copiedFiles);

        return [
            'manifest' => ['file' => 'media.json', 'entityCount' => count($mediaData)],
            'files'    => $files,
        ];
    }
}
