<?php

namespace App\Service;

use App\Entity\Media;
use Vich\UploaderBundle\Storage\StorageInterface;

class MediaSerializer
{
    public function __construct(private StorageInterface $storage) {}

    public function serialize(Media $media): array
    {
        $size          = $media->fileSize ?? 0;
        $extension     = $media->fileName ? strtolower(pathinfo($media->fileName, PATHINFO_EXTENSION)) : '';
        $formattedSize = match (true) {
            $size >= 1_073_741_824 => number_format($size / 1_073_741_824, 2) . ' GB',
            $size >= 1_048_576    => number_format($size / 1_048_576, 2) . ' MB',
            $size >= 1_024        => number_format($size / 1_024, 2) . ' KB',
            default               => $size . ' B',
        };

        $url = $this->storage->resolveUri($media, 'file');

        return [
            'id'                => $media->id,
            'uuid'              => $media->uuid?->toRfc4122(),
            'filename'          => $media->fileName,
            'original_filename' => $media->originalName,
            'mime_type'         => $media->mimeType,
            'extension'         => $extension,
            'size'              => $size,
            'disk'              => 'public',
            'path'              => $url,
            'url'               => $url,
            'full_url'          => $url,
            'thumbnail_url'     => $url,
            'formatted_size'    => $formattedSize,
            'metadata'          => $media->metadata ? [
                'width'       => null,
                'height'      => null,
                'alt_text'    => $media->alt,
                'title'       => null,
                'caption'     => $media->caption,
                'description' => null,
                'author'      => null,
                'copyright'   => null,
            ] : null,
            'alt'               => $media->alt,
            'caption'           => $media->caption,
            'created_at'        => $media->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at'        => $media->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
