<?php

namespace App\Controller;

use App\Entity\Media;
use App\Entity\ProjectStorageProfile;
use App\Repository\ProjectStorageProfileRepository;
use App\Service\ApiTokenChecker;
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
        private ?ApiTokenChecker $tokenChecker = null,
    ) {}

    #[Route('/cdn/media/{uuid}', name: 'cdn_media_transform', methods: ['GET'])]
    public function serve(string $uuid, Request $request): Response
    {
        try {
            $media = $this->em->getRepository(Media::class)->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return new Response('Média introuvable', 404);
        }

        // Vérifier que le média existe, n'est pas supprimé, et est accessible
        if (!$media || $media->isDeleted()) {
            return new Response('Média introuvable', 404);
        }

        // Vérifier les autorisations d'accès au média (publicApi, session utilisateur ou ApiToken)
        if (!$this->canAccessMedia($media, $request)) {
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
        } catch (\Throwable $e) {
            return new Response($e->getMessage(), 422);
        }

        return new BinaryFileResponse($cachePath, 200, [
            'Content-Type' => $this->imageTransform->getMimeType($cachePath),
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Image-Transform' => 'cached',
        ]);
    }

    #[Route('/cdn/media/{uuid}/info', name: 'cdn_media_info', methods: ['GET'])]
    public function info(string $uuid, Request $request): Response
    {
        $media = $this->em->getRepository(Media::class)->findOneBy(['uuid' => $uuid]);
        if (!$media || $media->isDeleted()) {
            return $this->json(['error' => 'Média introuvable'], 404);
        }

        // Vérifier les autorisations d'accès au média (publicApi, session utilisateur ou ApiToken)
        if (!$this->canAccessMedia($media, $request)) {
            return $this->json(['error' => 'Accès non autorisé'], 403);
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
        $fileName = basename((string) $media->fileName);
        if ($fileName === '' || $fileName === '.' || $fileName === '..') {
            return null;
        }

        $project = $media->project;
        $mediaRoot = $this->getParameter('kernel.project_dir') . '/public/uploads/media/';

        // 1. Emplacement local standard avec UUID du projet
        if ($project?->uuid !== null) {
            $filePath = $mediaRoot . (string) $project->uuid . '/' . $fileName;
            if (file_exists($filePath)) {
                return $filePath;
            }
        }

        // 2. Emplacement local plat (anciens uploads)
        $flatPath = $mediaRoot . $fileName;
        if (file_exists($flatPath)) {
            return $flatPath;
        }

        // 3. Rapatriement depuis stockage distant Flysystem (S3 / R2 / MinIO)
        if ($this->storageDriverFactory !== null) {
            $tempCacheDir = $this->getParameter('kernel.project_dir') . '/var/media_cache/' . ($project?->uuid ?? 'global');
            @mkdir($tempCacheDir, 0700, true);
            $localCached = $tempCacheDir . '/' . $fileName;

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

    private function canAccessMedia(Media $media, Request $request): bool
    {
        $project = $media->project;
        if ($project === null || $project->publicApi) {
            return true;
        }

        $user = $this->getUser();
        if ($user instanceof \App\Entity\User) {
            if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
                return true;
            }
            if ($project->hasMember($user)) {
                return true;
            }
        }

        if ($this->tokenChecker !== null) {
            $token = $this->tokenChecker->resolve($request);
            if ($token !== null && $token->project === $project) {
                return true;
            }
        }

        return false;
    }
}
