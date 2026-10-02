<?php

namespace App\Service;

use App\Entity\AssetUploadIntent;
use App\Entity\Media;
use App\Entity\Project;
use App\Entity\ProjectStorageProfile;
use App\Entity\User;
use App\Repository\ProjectStorageProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class DirectUploadService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StorageDriverFactory $storageDriverFactory,
        private readonly ProjectStorageProfileRepository $storageProfileRepository,
    ) {}

    /**
     * Crée une intention de téléversement direct S3 (single_put ou multipart).
     */
    public function createIntent(Project $project, array $data): AssetUploadIntent
    {
        $filename = trim($data['filename'] ?? '');
        if ($filename === '') {
            throw new \InvalidArgumentException('Filename is required.');
        }

        $mimeType = $data['mime_type'] ?? 'application/octet-stream';
        $maxBytes = isset($data['max_bytes']) ? (int) $data['max_bytes'] : null;
        $mode = ($data['mode'] ?? 'single_put') === AssetUploadIntent::MODE_MULTIPART
            ? AssetUploadIntent::MODE_MULTIPART
            : AssetUploadIntent::MODE_SINGLE_PUT;

        // Trouver le profil de stockage S3 actif pour ce projet
        $profile = $this->resolveS3Profile($project);
        if ($profile === null) {
            throw new \RuntimeException('No active S3 storage profile configured for this project.');
        }

        $intentUuid = Uuid::v4();
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $cleanExt = $extension !== '' ? '.' . strtolower($extension) : '';
        $storageKey = sprintf('%s/%s%s', $project->uuid?->toRfc4122() ?? 'global', $intentUuid->toRfc4122(), $cleanExt);

        $intent = new AssetUploadIntent();
        $intent->uuid = $intentUuid;
        $intent->project = $project;
        $intent->storageProfile = $profile;
        $intent->storageKey = $storageKey;
        $intent->originalFilename = $filename;
        $intent->clientMimeType = $mimeType;
        $intent->maxBytes = $maxBytes;
        $intent->mode = $mode;

        if ($mode === AssetUploadIntent::MODE_MULTIPART) {
            $s3Client = $this->storageDriverFactory->getS3Client($profile);
            $result = $s3Client->createMultipartUpload([
                'Bucket'      => $profile->s3Bucket,
                'Key'         => $storageKey,
                'ContentType' => $mimeType,
            ]);
            $intent->s3MultipartUploadId = $result['UploadId'] ?? null;
        }

        $this->em->persist($intent);
        $this->em->flush();

        return $intent;
    }

    /**
     * Génère l'URL pré-signée PUT pour un upload simple.
     */
    public function getPresignedPutUrl(AssetUploadIntent $intent, int $expiresInSeconds = 3600): string
    {
        $profile = $intent->storageProfile;
        if ($profile === null || $profile->driver !== 's3') {
            throw new \RuntimeException('Associated storage profile is not an S3 driver.');
        }

        $s3Client = $this->storageDriverFactory->getS3Client($profile);
        $cmd = $s3Client->getCommand('PutObject', [
            'Bucket'      => $profile->s3Bucket,
            'Key'         => $intent->storageKey,
            'ContentType' => $intent->clientMimeType ?? 'application/octet-stream',
        ]);

        $request = $s3Client->createPresignedRequest($cmd, "+{$expiresInSeconds} seconds");
        return (string) $request->getUri();
    }

    /**
     * Génère l'URL pré-signée pour une partie spécifique d'un upload multipart.
     */
    public function getPresignedPartUrl(AssetUploadIntent $intent, int $partNumber, int $expiresInSeconds = 3600): string
    {
        if ($intent->mode !== AssetUploadIntent::MODE_MULTIPART || !$intent->s3MultipartUploadId) {
            throw new \LogicException('Cannot sign a part for a non-multipart upload intent.');
        }

        $profile = $intent->storageProfile;
        if ($profile === null || $profile->driver !== 's3') {
            throw new \RuntimeException('Associated storage profile is not an S3 driver.');
        }

        $s3Client = $this->storageDriverFactory->getS3Client($profile);
        $cmd = $s3Client->getCommand('UploadPart', [
            'Bucket'     => $profile->s3Bucket,
            'Key'        => $intent->storageKey,
            'UploadId'   => $intent->s3MultipartUploadId,
            'PartNumber' => $partNumber,
        ]);

        $request = $s3Client->createPresignedRequest($cmd, "+{$expiresInSeconds} seconds");
        return (string) $request->getUri();
    }

    /**
     * Finalise un upload (simple ou multipart) et crée l'entité Media associée.
     */
    public function completeUpload(AssetUploadIntent $intent, array $parts = [], ?int $fileSize = null, ?User $user = null): Media
    {
        if ($intent->status !== AssetUploadIntent::STATUS_PENDING) {
            throw new \LogicException("Upload intent is already {$intent->status}.");
        }

        $profile = $intent->storageProfile;
        if ($profile === null || $profile->driver !== 's3') {
            throw new \RuntimeException('Associated storage profile is not an S3 driver.');
        }

        $s3Client = $this->storageDriverFactory->getS3Client($profile);

        if ($intent->mode === AssetUploadIntent::MODE_MULTIPART) {
            if (empty($parts)) {
                throw new \InvalidArgumentException('Parts list cannot be empty for multipart completion.');
            }

            // Normaliser le format des parts AWS S3
            $formattedParts = [];
            foreach ($parts as $part) {
                $formattedParts[] = [
                    'PartNumber' => (int) ($part['part_number'] ?? $part['PartNumber']),
                    'ETag'       => (string) ($part['etag'] ?? $part['ETag']),
                ];
            }

            usort($formattedParts, fn($a, $b) => $a['PartNumber'] <=> $b['PartNumber']);

            $s3Client->completeMultipartUpload([
                'Bucket'          => $profile->s3Bucket,
                'Key'             => $intent->storageKey,
                'UploadId'        => $intent->s3MultipartUploadId,
                'MultipartUpload' => ['Parts' => $formattedParts],
            ]);
        }

        // Si la taille n'est pas passée, on interroge l'objet sur S3 via HeadObject
        if ($fileSize === null) {
            try {
                $head = $s3Client->headObject([
                    'Bucket' => $profile->s3Bucket,
                    'Key'    => $intent->storageKey,
                ]);
                $fileSize = (int) ($head['ContentLength'] ?? 0);
            } catch (\Throwable) {
                $fileSize = $intent->maxBytes ?? 0;
            }
        }

        $media = new Media();
        $media->uuid = Uuid::v4();
        $media->project = $intent->project;
        $media->fileName = $intent->storageKey;
        $media->originalName = $intent->originalFilename;
        $media->mimeType = $intent->clientMimeType;
        $media->fileSize = $fileSize;
        $media->createdBy = $user;

        $intent->status = AssetUploadIntent::STATUS_COMPLETED;

        $this->em->persist($media);
        $this->em->flush();

        return $media;
    }

    /**
     * Annule un upload multipart sur S3.
     */
    public function abortUpload(AssetUploadIntent $intent): void
    {
        if ($intent->mode === AssetUploadIntent::MODE_MULTIPART && $intent->s3MultipartUploadId) {
            $profile = $intent->storageProfile;
            if ($profile !== null && $profile->driver === 's3') {
                try {
                    $s3Client = $this->storageDriverFactory->getS3Client($profile);
                    $s3Client->abortMultipartUpload([
                        'Bucket'   => $profile->s3Bucket,
                        'Key'      => $intent->storageKey,
                        'UploadId' => $intent->s3MultipartUploadId,
                    ]);
                } catch (\Throwable) {
                    // Ignorer les erreurs d'annulation distante
                }
            }
        }

        $intent->status = AssetUploadIntent::STATUS_ABORTED;
        $this->em->flush();
    }

    private function resolveS3Profile(Project $project): ?ProjectStorageProfile
    {
        $profiles = $this->storageProfileRepository->findBy([
            'project' => $project,
            'enabled' => true,
            'driver'  => 's3',
        ], ['priority' => 'DESC', 'isDefault' => 'DESC']);

        return $profiles[0] ?? null;
    }
}
