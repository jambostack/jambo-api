<?php

namespace App\Service;

use Intervention\Image\ImageManager;
use Symfony\Component\Filesystem\Filesystem;

class ImageTransformService
{
    private ImageManager $manager;
    private string $cacheDir;
    private Filesystem $fs;
    /** @var array<string, bool> cache mémoire local pour éviter file_exists répétés */
    private array $checkedPaths = [];

    private const ALLOWED_FITS = ['contain', 'cover', 'fill', 'crop', 'scale-down'];
    private const ALLOWED_FORMATS = ['webp', 'avif', 'png', 'jpg', 'jpeg', 'gif'];

    public function __construct(
        private string $projectDir,
    ) {
        // Intervention Image v4 : instanciation par classe de driver
        // (les helpers statiques ::gd()/::imagick() ne sont pas exposés dans cette build).
        $driverClass = extension_loaded('imagick')
            ? \Intervention\Image\Drivers\Imagick\Driver::class
            : \Intervention\Image\Drivers\Gd\Driver::class;
        $this->manager = new ImageManager($driverClass);
        $this->cacheDir = $projectDir . '/public/uploads/media/cache';
        $this->fs = new Filesystem();
    }

    /**
     * Transform an image and return the cached path.
     */
    public function transform(string $sourcePath, array $params): string
    {
        $params = $this->normalizeParams($params);
        $cacheKey = $this->cacheKey($sourcePath, $params);
        $cachePath = $this->cacheDir . '/' . $cacheKey;

        if (isset($this->checkedPaths[$cachePath]) || file_exists($cachePath)) {
            $this->checkedPaths[$cachePath] = true;

            return $cachePath;
        }

        if (!file_exists($sourcePath)) {
            throw new \RuntimeException('Image source introuvable');
        }

        $this->fs->mkdir(dirname($cachePath));

        $image = $this->manager->decodePath($sourcePath);

        if ($params['fit'] === 'crop' && $params['w'] && $params['h']) {
            $image->cover($params['w'], $params['h']);
        } elseif ($params['fit'] === 'contain' && $params['w'] && $params['h']) {
            $image->contain($params['w'], $params['h']);
        } elseif ($params['fit'] === 'fill' && $params['w'] && $params['h']) {
            $image->pad($params['w'], $params['h'], $params['bg'] ?? '#ffffff');
        } elseif ($params['fit'] === 'scale-down' && ($params['w'] || $params['h'])) {
            $image->scaleDown(width: $params['w'] ?: null, height: $params['h'] ?: null);
        } elseif ($params['w'] || $params['h']) {
            $image->resize(
                width: $params['w'] ?: null,
                height: $params['h'] ?: null,
            );
        }

        $ext = pathinfo($cachePath, PATHINFO_EXTENSION);

        if ($params['q'] !== null) {
            $image->encodeByExtension($ext, quality: $params['q']);
        }

        // GD driver fallback: AVIF → WebP
        if ($ext === 'avif' && !extension_loaded('imagick')) {
            $cachePath = preg_replace('/\.avif$/', '.webp', $cachePath);
        }

        $image->save($cachePath);

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
        $w = isset($params['w']) ? min(4000, max(1, (int) $params['w'])) : null;
        $h = isset($params['h']) ? min(4000, max(1, (int) $params['h'])) : null;
        $fit = in_array($params['fit'] ?? '', self::ALLOWED_FITS) ? $params['fit'] : null;
        $fmt = in_array(strtolower($params['fmt'] ?? ''), self::ALLOWED_FORMATS) ? strtolower($params['fmt']) : null;
        $q = isset($params['q']) ? max(1, min(100, (int) $params['q'])) : null;
        $bg = isset($params['bg']) && preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $params['bg']) ? (string) $params['bg'] : null;

        return compact('w', 'h', 'fit', 'fmt', 'q', 'bg');
    }

    private function cacheKey(string $sourcePath, array $params): string
    {
        $hash = md5($sourcePath . json_encode($params));
        $ext = $params['fmt'] ?? pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'jpg';
        $parts = array_filter([$params['w'], $params['h']]);
        $dims = !empty($parts) ? implode('x', $parts) . '_' : '';
        return $dims . $hash . '.' . $ext;
    }
}
