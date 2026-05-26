<?php

namespace App\Service\ExportImport\Export;

use App\Entity\Media;
use App\Entity\Project;
use App\Service\ExportImport\ExportHandlerInterface;
use Doctrine\ORM\EntityManagerInterface;

class MediaExportHandler implements ExportHandlerInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private string $projectDir,
    ) {}

    public static function getOptionKey(): string
    {
        return 'media';
    }

    public function export(Project $project, string $tempDir): array
    {
        $allMedia = $this->em->getRepository(Media::class)->findBy(['project' => $project]);

        // Filter out soft-deleted media
        $mediaEntities = array_filter($allMedia, fn(Media $m) => !$m->isDeleted());

        $mediaData = [];
        $copiedFiles = [];

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

            // Copy the binary file from project upload directory to temp export directory
            if ($media->fileName !== null) {
                $sourcePath = $this->projectDir . '/public/uploads/media/' . $project->uuid->toString() . '/' . $media->fileName;
                if (file_exists($sourcePath)) {
                    $mediaExportDir = $tempDir . '/media';
                    if (!is_dir($mediaExportDir)) {
                        mkdir($mediaExportDir, 0777, true);
                    }
                    $destPath = $mediaExportDir . '/' . $media->fileName;
                    copy($sourcePath, $destPath);
                    $copiedFiles[] = 'media/' . $media->fileName;
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
