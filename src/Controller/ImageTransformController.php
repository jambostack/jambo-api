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
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class ImageTransformController extends AbstractController
{
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const MAX_OUTPUT_PIXELS = 12_000_000; // 12 MP
    private const MAX_SOURCE_PIXELS = 30_000_000; // 30 MP
    private const MAX_SOURCE_BYTES = 26_214_400;  // 25 MB

    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private ImageTransformService $imageTransform,
        private ?StorageDriverFactory $storageDriverFactory = null,
        private ?ProjectStorageProfileRepository $storageProfileRepo = null,
        private ?ApiTokenChecker $tokenChecker = null,
        private ?TranslatorInterface $translator = null,
        #[Autowire(service: 'limiter.image_transform_limiter')]
        private ?RateLimiterFactory $imageTransformLimiter = null,
    ) {}

    private function trans(string $key, string $default, array $parameters = []): string
    {
        if ($this->translator !== null) {
            $translated = $this->translator->trans($key, $parameters, 'messages');
            if ($translated !== $key) {
                return $translated;
            }
        }
        return strtr($default, $parameters);
    }

    private function checkRateLimit(Request $request): ?Response
    {
        if ($this->imageTransformLimiter !== null) {
            $identifier = $this->getUser()?->getUserIdentifier() ?? $request->getClientIp() ?: '127.0.0.1';
            if (false === $this->imageTransformLimiter->create($identifier)->consume(1)->isAccepted()) {
                return new Response($this->trans('media.rate_limit_exceeded', 'Too many image requests. Please retry later.'), 429, [
                    'Content-Type' => 'text/plain',
                    'Retry-After' => '60',
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }
        }
        return null;
    }

    #[Route('/cdn/media/{uuid}', name: 'cdn_media_transform', methods: ['GET'])]
    public function serve(string $uuid, Request $request): Response
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        if (!preg_match(self::UUID_REGEX, $uuid)) {
            return new Response($this->trans('media.not_found', 'Media not found'), 404, self::SECURITY_HEADERS);
        }

        try {
            $media = $this->em->getRepository(Media::class)->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return new Response($this->trans('media.not_found', 'Media not found'), 404, self::SECURITY_HEADERS);
        }

        // Vérifier que le média existe, n'est pas supprimé, et est accessible
        if (!$media || $media->isDeleted()) {
            return new Response($this->trans('media.not_found', 'Media not found'), 404, self::SECURITY_HEADERS);
        }

        // Vérifier les autorisations d'accès au média (publicApi, session utilisateur ou ApiToken)
        if (!$this->canAccessMedia($media, $request)) {
            return new Response($this->trans('media.access_denied', 'Access denied'), 403, self::SECURITY_HEADERS);
        }

        $filePath = $this->resolveLocalOrRemoteFilePath($media);
        if ($filePath === null || !file_exists($filePath)) {
            return new Response($this->trans('media.file_not_found', 'File not found'), 404, self::SECURITY_HEADERS);
        }

        $params = $request->query->all();
        $hasParams = !empty(array_intersect_key($params, array_flip(['w', 'h', 'fit', 'fmt', 'q', 'bg'])));

        // Protection: SVG or non-raster media types cannot be transformed by GD/Imagick
        $isSvg = str_contains((string) $media->mimeType, 'svg') || strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'svg';
        if ($isSvg) {
            return new BinaryFileResponse($filePath, 200, [
                'Content-Type' => 'image/svg+xml',
                'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none';",
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ]);
        }

        if (!$hasParams) {
            return new BinaryFileResponse($filePath, 200, [
                'Content-Type' => $media->mimeType ?? 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ]);
        }

        // Anti-DoS mémoire : contrôle de taille de fichier source
        $sourceSize = @filesize($filePath);
        if ($sourceSize !== false && $sourceSize > self::MAX_SOURCE_BYTES) {
            return new Response($this->trans('media.source_too_large', 'Source image is too large for dynamic transformation (max 25MB).'), 413, self::SECURITY_HEADERS);
        }

        // Anti-DoS mémoire : contrôle des dimensions physiques de l'image source
        $sourceInfo = @getimagesize($filePath);
        if ($sourceInfo === false) {
            return new Response($this->trans('media.invalid_image_source', 'Invalid or unreadable source image file.'), 422, self::SECURITY_HEADERS);
        }
        $srcW = (int) ($sourceInfo[0] ?? 0);
        $srcH = (int) ($sourceInfo[1] ?? 0);
        if ($srcW > 10000 || $srcH > 10000 || ($srcW * $srcH > self::MAX_SOURCE_PIXELS)) {
            return new Response($this->trans('media.dimensions_too_large', 'Source image dimensions exceed memory safety thresholds (max 30 MP).'), 422, self::SECURITY_HEADERS);
        }

        // Bounding & validation of transformation parameters
        $validatedParams = [];
        if (isset($params['w'])) {
            $rawW = $params['w'];
            if (!is_numeric($rawW) || (int) $rawW < 1 || (int) $rawW > 4000) {
                return new Response($this->trans('media.invalid_params', 'Invalid image transformation parameters.'), 422, self::SECURITY_HEADERS);
            }
            $validatedParams['w'] = (int) $rawW;
        }
        if (isset($params['h'])) {
            $rawH = $params['h'];
            if (!is_numeric($rawH) || (int) $rawH < 1 || (int) $rawH > 4000) {
                return new Response($this->trans('media.invalid_params', 'Invalid image transformation parameters.'), 422, self::SECURITY_HEADERS);
            }
            $validatedParams['h'] = (int) $rawH;
        }

        // Anti-DoS mémoire : borne du nombre total de pixels en sortie
        if (isset($validatedParams['w'], $validatedParams['h']) && ($validatedParams['w'] * $validatedParams['h'] > self::MAX_OUTPUT_PIXELS)) {
            return new Response($this->trans('media.pixel_limit_exceeded', 'Requested image dimensions exceed maximum output pixel limit (12 MP).'), 422, self::SECURITY_HEADERS);
        }

        if (isset($params['q'])) {
            $rawQ = $params['q'];
            if (!is_numeric($rawQ) || (int) $rawQ < 1 || (int) $rawQ > 100) {
                return new Response($this->trans('media.invalid_params', 'Invalid image transformation parameters.'), 422, self::SECURITY_HEADERS);
            }
            $validatedParams['q'] = (int) $rawQ;
        }
        if (isset($params['fit'])) {
            $rawFit = (string) $params['fit'];
            if (!in_array($rawFit, ['contain', 'cover', 'fill', 'crop', 'scale-down'], true)) {
                return new Response($this->trans('media.invalid_params', 'Invalid image transformation parameters.'), 422, self::SECURITY_HEADERS);
            }
            $validatedParams['fit'] = $rawFit;
        }
        if (isset($params['fmt'])) {
            $rawFmt = strtolower((string) $params['fmt']);
            if (!in_array($rawFmt, ['webp', 'avif', 'png', 'jpg', 'jpeg', 'gif'], true)) {
                return new Response($this->trans('media.invalid_params', 'Invalid image transformation parameters.'), 422, self::SECURITY_HEADERS);
            }
            $validatedParams['fmt'] = $rawFmt;
        }
        if (isset($params['bg'])) {
            $rawBg = (string) $params['bg'];
            if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $rawBg)) {
                return new Response($this->trans('media.invalid_params', 'Invalid image transformation parameters.'), 422, self::SECURITY_HEADERS);
            }
            $validatedParams['bg'] = $rawBg;
        }

        try {
            $cachePath = $this->imageTransform->transform($filePath, $validatedParams);
        } catch (\Throwable) {
            return new Response($this->trans('media.transform_failed', 'Image transformation failed.'), 422, self::SECURITY_HEADERS);
        }

        return new BinaryFileResponse($cachePath, 200, [
            'Content-Type' => $this->imageTransform->getMimeType($cachePath),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Image-Transform' => 'cached',
        ]);
    }

    #[Route('/cdn/media/{uuid}/info', name: 'cdn_media_info', methods: ['GET'])]
    public function info(string $uuid, Request $request): Response
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        if (!preg_match(self::UUID_REGEX, $uuid)) {
            return new JsonResponse(['error' => $this->trans('media.not_found', 'Media not found')], 404, self::SECURITY_HEADERS);
        }

        try {
            $media = $this->em->getRepository(Media::class)->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return new JsonResponse(['error' => $this->trans('media.not_found', 'Media not found')], 404, self::SECURITY_HEADERS);
        }

        if (!$media || $media->isDeleted()) {
            return new JsonResponse(['error' => $this->trans('media.not_found', 'Media not found')], 404, self::SECURITY_HEADERS);
        }

        // Vérifier les autorisations d'accès au média (publicApi, session utilisateur ou ApiToken)
        if (!$this->canAccessMedia($media, $request)) {
            return new JsonResponse(['error' => $this->trans('media.access_denied', 'Access denied')], 403, self::SECURITY_HEADERS);
        }

        $filePath = $this->resolveLocalOrRemoteFilePath($media);
        if ($filePath === null || !file_exists($filePath)) {
            return new JsonResponse(['error' => $this->trans('media.file_not_found', 'File not found')], 404, self::SECURITY_HEADERS);
        }

        $imageSize = @getimagesize($filePath);

        return new JsonResponse([
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
        ], 200, [
            'Cache-Control' => 'no-cache, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function resolveLocalOrRemoteFilePath(Media $media): ?string
    {
        $fileName = basename((string) $media->fileName);
        if ($fileName === '' || $fileName === '.' || $fileName === '..' || str_contains($fileName, "\0") || str_contains($fileName, '/') || str_contains($fileName, '\\')) {
            return null;
        }

        $project = $media->project;
        $mediaRoot = $this->getParameter('kernel.project_dir') . '/public/uploads/media/';

        // 1. Emplacement local standard avec UUID du projet
        if ($project?->uuid !== null && preg_match(self::UUID_REGEX, (string) $project->uuid)) {
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
            $projectDirName = ($project?->uuid !== null && preg_match(self::UUID_REGEX, (string) $project->uuid))
                ? (string) $project->uuid
                : 'global';
            $tempCacheDir = $this->getParameter('kernel.project_dir') . '/var/media_cache/' . $projectDirName;
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
