<?php

namespace App\Service;

use Intervention\Image\ImageManager;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Translation\TranslatorInterface;

class ImageTransformService
{
    private ImageManager $manager;
    private string $cacheDir;
    private Filesystem $fs;
    /** @var array<string, bool> cache mémoire local pour éviter file_exists répétés */
    private array $checkedPaths = [];

    public const MAX_SOURCE_BYTES = 26_214_400;  // 25 MB
    public const MAX_SOURCE_PIXELS = 30_000_000; // 30 MP
    public const MAX_OUTPUT_PIXELS = 12_000_000; // 12 MP
    public const MAX_DIMENSION = 4000;           // 4000 px
    public const MAX_SOURCE_DIMENSION = 10000;   // 10000 px

    private const ALLOWED_FITS = ['contain', 'cover', 'fill', 'crop', 'scale-down'];
    private const ALLOWED_FORMATS = ['webp', 'avif', 'png', 'jpg', 'jpeg', 'gif'];
    private const ALLOWED_SOURCE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];

    public function __construct(
        private string $projectDir,
        private ?TranslatorInterface $translator = null,
    ) {
        if (extension_loaded('imagick') && class_exists(\Imagick::class)) {
            try {
                \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 128 * 1024 * 1024);
                \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MAP, 256 * 1024 * 1024);
                \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_WIDTH, self::MAX_SOURCE_DIMENSION);
                \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_HEIGHT, self::MAX_SOURCE_DIMENSION);
                \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_DISK, 512 * 1024 * 1024);
            } catch (\Throwable) {
                // Ignore if resource limit cannot be adjusted in this environment
            }
        }

        // Intervention Image v4 : instanciation par classe de driver
        // (les helpers statiques ::gd()/::imagick() ne sont pas exposés dans cette build).
        $driverClass = extension_loaded('imagick')
            ? \Intervention\Image\Drivers\Imagick\Driver::class
            : \Intervention\Image\Drivers\Gd\Driver::class;
        $this->manager = new ImageManager($driverClass);
        $this->cacheDir = $projectDir . '/public/uploads/media/cache';
        $this->fs = new Filesystem();
    }

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

    /**
     * Transform an image and return the cached path.
     */
    public function transform(string $sourcePath, array $params): string
    {
        if (str_contains($sourcePath, "\0")) {
            throw new \InvalidArgumentException($this->trans('media.file_not_found', 'File not found.'));
        }

        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new \InvalidArgumentException($this->trans('media.file_not_found', 'File not found.'));
        }

        $fileSize = @filesize($sourcePath);
        if ($fileSize !== false && $fileSize > self::MAX_SOURCE_BYTES) {
            throw new \OverflowException($this->trans('media.source_too_large', 'Source image is too large for dynamic transformation (max 25MB).'));
        }

        $sourceInfo = @getimagesize($sourcePath);
        if ($sourceInfo === false || !isset($sourceInfo[0], $sourceInfo[1])) {
            throw new \InvalidArgumentException($this->trans('media.invalid_image_source', 'Invalid or unreadable source image file.'));
        }

        $srcMime = strtolower((string) ($sourceInfo['mime'] ?? ''));
        if (!in_array($srcMime, self::ALLOWED_SOURCE_MIMES, true)) {
            throw new \InvalidArgumentException($this->trans('media.invalid_image_source', 'Invalid or unreadable source image file.'));
        }

        $srcW = (int) $sourceInfo[0];
        $srcH = (int) $sourceInfo[1];
        if ($srcW <= 0 || $srcH <= 0 || $srcW > self::MAX_SOURCE_DIMENSION || $srcH > self::MAX_SOURCE_DIMENSION || ($srcW * $srcH > self::MAX_SOURCE_PIXELS)) {
            throw new \OverflowException($this->trans('media.dimensions_too_large', 'Source image dimensions exceed memory safety thresholds (max 30 MP).'));
        }

        $normalized = $this->normalizeParams($params);
        if ($normalized['w'] !== null && $normalized['h'] !== null && ($normalized['w'] * $normalized['h'] > self::MAX_OUTPUT_PIXELS)) {
            throw new \InvalidArgumentException($this->trans('media.pixel_limit_exceeded', 'Requested image dimensions exceed maximum output pixel limit (12 MP).'));
        }

        $cacheKey = $this->cacheKey($sourcePath, $normalized);
        $cachePath = $this->cacheDir . '/' . $cacheKey;

        if (isset($this->checkedPaths[$cachePath]) || file_exists($cachePath)) {
            $this->checkedPaths[$cachePath] = true;

            return $cachePath;
        }

        $this->fs->mkdir(dirname($cachePath));

        try {
            $image = $this->manager->decodePath($sourcePath);

            if ($normalized['fit'] === 'crop' && $normalized['w'] && $normalized['h']) {
                $image->cover($normalized['w'], $normalized['h']);
            } elseif ($normalized['fit'] === 'contain' && $normalized['w'] && $normalized['h']) {
                $image->contain($normalized['w'], $normalized['h']);
            } elseif ($normalized['fit'] === 'fill' && $normalized['w'] && $normalized['h']) {
                $image->pad($normalized['w'], $normalized['h'], $normalized['bg'] ?? '#ffffff');
            } elseif ($normalized['fit'] === 'scale-down' && ($normalized['w'] || $normalized['h'])) {
                $image->scaleDown(width: $normalized['w'] ?: null, height: $normalized['h'] ?: null);
            } elseif ($normalized['w'] || $normalized['h']) {
                $image->resize(
                    width: $normalized['w'] ?: null,
                    height: $normalized['h'] ?: null,
                );
            }

            $ext = pathinfo($cachePath, PATHINFO_EXTENSION);

            // GD driver fallback: AVIF → WebP
            if ($ext === 'avif' && !extension_loaded('imagick')) {
                $cachePath = preg_replace('/\.avif$/', '.webp', $cachePath);
            }

            if ($normalized['q'] !== null) {
                $image->save($cachePath, quality: $normalized['q']);
            } else {
                $image->save($cachePath);
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException($this->trans('media.transform_failed', 'Image transformation failed.'), 0, $e);
        }

        return $cachePath;
    }

    public function getMimeType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'jpg', 'jpeg' => 'image/jpeg',
            default => mime_content_type($path) ?: 'application/octet-stream',
        };
    }

    public function getCacheDir(): string
    {
        return $this->cacheDir;
    }

    /**
     * Nettoie les fichiers de cache des transformations d'images.
     *
     * @param int $maxAgeDays Âge maximal des fichiers en jours
     * @param int|null $maxSizeBytes Taille maximale totale autorisée pour le dossier de cache
     * @return array{deletedFiles: int, freedBytes: int, remainingFiles: int, remainingBytes: int}
     */
    public function pruneCache(int $maxAgeDays = 30, ?int $maxSizeBytes = null): array
    {
        $deletedCount = 0;
        $freedBytes = 0;

        if (!is_dir($this->cacheDir)) {
            return [
                'deletedFiles' => 0,
                'freedBytes' => 0,
                'remainingFiles' => 0,
                'remainingBytes' => 0,
            ];
        }

        $now = time();
        $cutoffTime = $now - ($maxAgeDays * 86400);

        /** @var \SplFileInfo[] $files */
        $files = [];
        $totalBytes = 0;

        $it = new \DirectoryIterator($this->cacheDir);
        foreach ($it as $fileInfo) {
            if ($fileInfo->isDot() || !$fileInfo->isFile()) {
                continue;
            }
            $mtime = $fileInfo->getMTime();
            $size = $fileInfo->getSize();

            if ($mtime < $cutoffTime) {
                if (@unlink($fileInfo->getPathname())) {
                    $deletedCount++;
                    $freedBytes += $size;
                    continue;
                }
            }

            $files[] = clone $fileInfo;
            $totalBytes += $size;
        }

        // Si une taille maximale est spécifiée et dépassée, supprimer les plus anciens
        if ($maxSizeBytes !== null && $totalBytes > $maxSizeBytes) {
            usort($files, fn(\SplFileInfo $a, \SplFileInfo $b) => $a->getMTime() <=> $b->getMTime());
            foreach ($files as $idx => $file) {
                if ($totalBytes <= $maxSizeBytes) {
                    break;
                }
                $size = $file->getSize();
                if (@unlink($file->getPathname())) {
                    $deletedCount++;
                    $freedBytes += $size;
                    $totalBytes -= $size;
                    unset($files[$idx]);
                }
            }
        }

        $this->checkedPaths = [];

        return [
            'deletedFiles' => $deletedCount,
            'freedBytes' => $freedBytes,
            'remainingFiles' => count($files),
            'remainingBytes' => $totalBytes,
        ];
    }

    private function normalizeParams(array $params): array
    {
        $w = isset($params['w']) ? min(self::MAX_DIMENSION, max(1, (int) $params['w'])) : null;
        $h = isset($params['h']) ? min(self::MAX_DIMENSION, max(1, (int) $params['h'])) : null;
        $fit = in_array($params['fit'] ?? '', self::ALLOWED_FITS, true) ? $params['fit'] : null;
        $fmt = in_array(strtolower($params['fmt'] ?? ''), self::ALLOWED_FORMATS, true) ? strtolower($params['fmt']) : null;
        $q = isset($params['q']) ? max(1, min(100, (int) $params['q'])) : null;
        $bg = isset($params['bg']) && preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $params['bg']) ? (string) $params['bg'] : null;

        return compact('w', 'h', 'fit', 'fmt', 'q', 'bg');
    }

    private function cacheKey(string $sourcePath, array $params): string
    {
        $hash = md5($sourcePath . json_encode($params));
        $sourceExt = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
        $ext = $params['fmt'] ?? (in_array($sourceExt, self::ALLOWED_FORMATS, true) ? $sourceExt : 'jpg');
        $parts = array_filter([$params['w'], $params['h']]);
        $dims = !empty($parts) ? implode('x', $parts) . '_' : '';
        return $dims . $hash . '.' . $ext;
    }
}
