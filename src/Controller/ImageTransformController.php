<?php

namespace App\Controller;

use App\Entity\Media;
use App\Entity\ProjectStorageProfile;
use App\Repository\ProjectStorageProfileRepository;
use App\Service\ImageTransformService;
use App\Service\StorageDriverFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ImageTransformController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ImageTransformService $imageTransform,
        private ?StorageDriverFactory $storageDriverFactory = null,
        private ?ProjectStorageProfileRepository $storageProfileRepo = null,
    ) {}

    #[Route('/cdn/media/{uuid}', name: 'cdn_media_transform', methods: ['GET'])]
    public function serve(string $uuid, Request $request): Response
    {
        $media = $this->em->getRepository(Media::class)->findOneBy(['uuid' => $uuid]);

        // Vérifier que le média existe, n'est pas supprimé, et est accessible
        if (!$media || $media->isDeleted()) {
            return new Response('Média introuvable', 404);
        }

        // Vérifier que le projet autorise l'accès public ou que l'utilisateur est authentifié
        $project = $media->project;
        if (!$project?->publicApi && !$this->getUser()) {
            return new Response('Accès non autorisé', 403);
        }

        $filePath = $this->resolveLocalOrRemoteFilePath($media);
        if ($filePath === null || !file_exists($filePath)) {
            return new Response('Fichier introuvable', 404);
        }

        $params = $request->query->all();
        $hasParams = !empty(array_intersect_key($params, array_flip(['w', 'h', 'fit', 'fmt', 'q', 'bg'])));

        if (!$hasParams) {
            return new BinaryFileResponse($filePath, 200, [
                'Content-Type' => $media->mimeType ?? 'application/octet-stream',
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ]);
        }

        try {
            $cachePath = $this->imageTransform->transform($filePath, $params);
        } catch (\RuntimeException $e) {
            return new Response($e->getMessage(), 404);
        }

        return new BinaryFileResponse($cachePath, 200, [
            'Content-Type' => $this->imageTransform->getMimeType($cachePath),
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Image-Transform' => 'cached',
        ]);
    }

    #[Route('/cdn/media/{uuid}/info', name: 'cdn_media_info', methods: ['GET'])]
    public function info(string $uuid): Response
    {
        $media = $this->em->getRepository(Media::class)->findOneBy(['uuid' => $uuid]);
        if (!$media || $media->isDeleted()) {
            return $this->json(['error' => 'Média introuvable'], 404);
        }

        $filePath = $this->resolveLocalOrRemoteFilePath($media);
        if ($filePath === null || !file_exists($filePath)) {
            return $this->json(['error' => 'Fichier introuvable'], 404);
        }

        $imageSize = @getimagesize($filePath);

        return $this->json([
            'uuid' => $media->uuid->toRfc4122(),
            'name' => $media->originalName ?? $media->fileName,
            'mimeType' => $media->mimeType,
            'size' => $media->fileSize,
            'width' => $imageSize[0] ?? null,
            'height' => $imageSize[1] ?? null,
            'url' => $this->generateUrl('cdn_media_transform', ['uuid' => $media->uuid->toRfc4122()], 0),
            'transformations' => [
                'resize' => '?w=800&h=600',
                'crop' => '?w=400&h=400&fit=crop',
                'webp' => '?fmt=webp&q=80',
                'thumbnail' => '?w=200&h=200&fit=crop&fmt=webp&q=80',
            ],
        ]);
    }

    private function resolveLocalOrRemoteFilePath(Media $media): ?string
    {
        $project = $media->project;
        $mediaRoot = $this->getParameter('kernel.project_dir') . '/public/uploads/media/';

        // 1. Emplacement local standard avec UUID du projet
        if ($project?->uuid !== null) {
            $filePath = $mediaRoot . (string) $project->uuid . '/' . $media->fileName;
            if (file_exists($filePath)) {
                return $filePath;
            }
        }

        // 2. Emplacement local plat (anciens uploads)
        $flatPath = $mediaRoot . $media->fileName;
        if (file_exists($flatPath)) {
            return $flatPath;
        }

        // 3. Rapatriement depuis stockage distant Flysystem (S3 / R2 / MinIO)
        if ($this->storageDriverFactory !== null) {
            $tempCacheDir = $this->getParameter('kernel.project_dir') . '/var/media_cache/' . ($project?->uuid ?? 'global');
            @mkdir($tempCacheDir, 0755, true);
            $localCached = $tempCacheDir . '/' . $media->fileName;

            // Si déjà en cache local temporaire
            if (file_exists($localCached) && filesize($localCached) > 0) {
                return $localCached;
            }

            // Tenter via media->storagePaths
            if (!empty($media->storagePaths)) {
                foreach ($media->storagePaths as $profileUuid => $remotePath) {
                    try {
                        $profile = $this->storageProfileRepo?->findOneBy(['uuid' => $profileUuid])
                            ?? $this->em->getRepository(ProjectStorageProfile::class)->findOneBy(['uuid' => $profileUuid]);
                        if ($profile !== null) {
                            $fs = $this->storageDriverFactory->create($profile);
                            if ($fs->fileExists($remotePath)) {
                                $stream = $fs->readStream($remotePath);
                                $dest = fopen($localCached, 'wb');
                                stream_copy_to_stream($stream, $dest);
                                fclose($dest);
                                if (is_resource($stream)) {
                                    fclose($stream);
                                }
                                if (file_exists($localCached) && filesize($localCached) > 0) {
                                    return $localCached;
                                }
                            }
                        }
                    } catch (\Throwable) {
                        continue;
                    }
                }
            }

            // Tenter via media->storageProfile
            if ($media->storageProfile !== null) {
                try {
                    $fs = $this->storageDriverFactory->create($media->storageProfile);
                    $candidates = [
                        (string) $media->fileName,
                        'projects/' . ($project?->uuid ?? '') . '/' . $media->fileName,
                    ];
                    foreach ($candidates as $cand) {
                        if ($fs->fileExists($cand)) {
                            $stream = $fs->readStream($cand);
                            $dest = fopen($localCached, 'wb');
                            stream_copy_to_stream($stream, $dest);
                            fclose($dest);
                            if (is_resource($stream)) {
                                fclose($stream);
                            }
                            if (file_exists($localCached) && filesize($localCached) > 0) {
                                return $localCached;
                            }
                        }
                    }
                } catch (\Throwable) {
                    // Ignorer et continuer
                }
            }
        }

        return null;
    }
}
