<?php

namespace App\Service;

use Intervention\Image\ImageManager;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Translation\TranslatorInterface;

class ImageOptimizerService
{
    public const MAX_SOURCE_BYTES = 26_214_400;  // 25 MB
    public const MAX_SOURCE_PIXELS = 30_000_000; // 30 MP
    public const MAX_OUTPUT_PIXELS = 12_000_000; // 12 MP
    public const MAX_DIMENSION = 4000;           // 4000 px
    public const MAX_SOURCE_DIMENSION = 10000;   // 10000 px
    public const MAX_ASPECT_RATIO = 25.0;        // 25:1
    public const MAX_SOURCE_ASPECT_RATIO = 50.0; // 50:1
    public const MAX_DECOMPRESSION_RATIO = 500;
    public const DECOMPRESSION_BOMB_THRESHOLD_BYTES = 5_000_000; // 5 MB uncompressed

    public const ALLOWED_FORMATS = ['webp', 'avif', 'png', 'jpg', 'jpeg', 'gif'];
    public const ALLOWED_SOURCE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];

    public const PRESETS = [
        'thumbnail' => ['w' => 150, 'h' => 150, 'fit' => 'cover', 'q' => 80, 'fmt' => 'webp'],
        'avatar'    => ['w' => 200, 'h' => 200, 'fit' => 'crop',  'q' => 85, 'fmt' => 'webp'],
        'preview'   => ['w' => 600, 'h' => 400, 'fit' => 'contain', 'q' => 82, 'fmt' => 'webp'],
        'card'      => ['w' => 800, 'h' => 600, 'fit' => 'cover', 'q' => 85, 'fmt' => 'webp'],
        'banner'    => ['w' => 1920, 'h' => 600, 'fit' => 'cover', 'q' => 85, 'fmt' => 'webp'],
    ];

    private ImageManager $manager;
    private string $cacheDir;
    private Filesystem $fs;

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
                // Ignore if resource limit cannot be set
            }
        }

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
     * Valide de manière stricte la sécurité d'une image physique avant manipulation.
     *
     * @return array{width: int, height: int, mime: string, fileSize: int, ratio: float}
     */
    public function validateImageSafety(string $sourcePath): array
    {
        if (str_contains($sourcePath, "\0")) {
            throw new \InvalidArgumentException($this->trans('media.file_not_found', 'File not found.'));
        }

        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new \InvalidArgumentException($this->trans('media.file_not_found', 'File not found.'));
        }

        $fileSize = @filesize($sourcePath);
        if ($fileSize === false || $fileSize <= 0) {
            throw new \InvalidArgumentException($this->trans('media.file_not_found', 'File not found.'));
        }

        if ($fileSize > self::MAX_SOURCE_BYTES) {
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

        // Vérification ratio d'aspect source (anti-boucle / canvas DoS)
        $aspectRatio = (float) ($srcW / $srcH);
        if ($aspectRatio > self::MAX_SOURCE_ASPECT_RATIO || $aspectRatio < (1.0 / self::MAX_SOURCE_ASPECT_RATIO)) {
            throw new \InvalidArgumentException($this->trans('media.source_aspect_ratio_exceeded', 'Source image aspect ratio exceeds maximum safety threshold (50:1).'));
        }

        // Détection de bombe de décompression (pixel bomb / compression bomb)
        $uncompressedBytes = $srcW * $srcH * 4; // 4 octets par pixel RGBA
        $decompressionRatio = $uncompressedBytes / max(1, $fileSize);
        if ($decompressionRatio > self::MAX_DECOMPRESSION_RATIO && $uncompressedBytes > self::DECOMPRESSION_BOMB_THRESHOLD_BYTES) {
            throw new \OverflowException($this->trans('media.image_bomb_detected', 'Suspicious compression ratio detected (potential image decompression bomb).'));
        }

        // Vérification de la marge mémoire du processus PHP
        if ($this->isMemoryExhaustionRisk($uncompressedBytes)) {
            throw new \OverflowException($this->trans('media.memory_limit_exceeded', 'Insufficient server memory headroom to safely decode and transform this image.'));
        }

        return [
            'width' => $srcW,
            'height' => $srcH,
            'mime' => $srcMime,
            'fileSize' => $fileSize,
            'ratio' => $aspectRatio,
        ];
    }

    /**
     * Détecte si un fichier image est une bombe de décompression (sans lever d'exception).
     */
    public function isDecompressionBomb(string $sourcePath): bool
    {
        try {
            $this->validateImageSafety($sourcePath);
            return false;
        } catch (\OverflowException) {
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Détecte si une archive ZIP est une bombe de décompression (ZIP bomb).
     */
    public static function detectZipBomb(
        string $archivePath,
        float $maxRatio = 100.0,
        int $maxTotalUncompressedBytes = 104_857_600, // 100 MB
        int $maxFiles = 10_000
    ): bool {
        if (!class_exists(\ZipArchive::class) || !is_file($archivePath) || !is_readable($archivePath)) {
            return false;
        }

        $zip = new \ZipArchive();
        $openResult = $zip->open($archivePath, \ZipArchive::RDONLY);
        if ($openResult !== true) {
            return false;
        }

        try {
            $numFiles = $zip->numFiles;
            if ($numFiles > $maxFiles) {
                return true;
            }

            $totalUncompressed = 0;
            $totalCompressed = 0;

            for ($i = 0; $i < $numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }

                $size = (int) ($stat['size'] ?? 0);
                $compSize = (int) ($stat['comp_size'] ?? 0);

                if ($size < 0 || $compSize < 0) {
                    return true;
                }

                $totalUncompressed += $size;
                $totalCompressed += $compSize;

                if ($totalUncompressed > $maxTotalUncompressedBytes) {
                    return true;
                }

                if ($compSize > 0 && ($size / $compSize) > $maxRatio && $size > 1_000_000) {
                    return true;
                }
            }

            if ($totalCompressed > 0 && ($totalUncompressed / $totalCompressed) > $maxRatio && $totalUncompressed > 5_000_000) {
                return true;
            }

            return false;
        } finally {
            $zip->close();
        }
    }

    /**
     * Supprime les métadonnées sensibles (EXIF / GPS / IPTC / XMP) d'une image pour la confidentialité.
     */
    public function stripMetadata(string $sourcePath, ?string $destinationPath = null): string
    {
        $this->validateImageSafety($sourcePath);

        $dest = $destinationPath ?? $sourcePath;

        try {
            if (extension_loaded('imagick') && class_exists(\Imagick::class)) {
                $im = new \Imagick($sourcePath);
                $im->stripImage();
                $this->fs->mkdir(dirname($dest));
                $im->writeImage($dest);
                $im->clear();
                $im->destroy();
                return $dest;
            }

            $image = $this->manager->decodePath($sourcePath);
            $this->fs->mkdir(dirname($dest));
            $image->save($dest);
            return $dest;
        } catch (\Throwable $e) {
            throw new \RuntimeException($this->trans('media.strip_metadata_failed', 'Failed to strip metadata from image.'), 0, $e);
        }
    }

    /**
     * Optimise une image avec isolation multi-tenant et options sécurisées.
     *
     * @param array{
     *     quality?: int,
     *     format?: string,
     *     strip_metadata?: bool,
     *     max_width?: int,
     *     max_height?: int,
     *     preset?: string
     * } $options
     * @return array{path: string, original_size: int, optimized_size: int, savings_bytes: int, savings_percent: float}
     */
    public function optimize(string $sourcePath, array $options = [], ?string $tenantUuid = null): array
    {
        $safety = $this->validateImageSafety($sourcePath);

        if (isset($options['preset']) && is_string($options['preset'])) {
            $presetName = strtolower($options['preset']);
            if (!isset(self::PRESETS[$presetName])) {
                throw new \InvalidArgumentException($this->trans('media.preset_not_found', 'Image optimization preset not found.'));
            }
            $options = array_merge(self::PRESETS[$presetName], $options);
        }

        $quality = isset($options['q']) || isset($options['quality'])
            ? max(1, min(100, (int) ($options['q'] ?? $options['quality'])))
            : 85;

        $fmt = isset($options['fmt']) || isset($options['format'])
            ? strtolower((string) ($options['fmt'] ?? $options['format']))
            : 'webp';

        if (!in_array($fmt, self::ALLOWED_FORMATS, true)) {
            throw new \InvalidArgumentException($this->trans('media.format_not_supported', 'Image format is not supported for dynamic optimization.'));
        }

        $strip = (bool) ($options['strip_metadata'] ?? true);
        $maxW = isset($options['w']) || isset($options['max_width'])
            ? min(self::MAX_DIMENSION, max(1, (int) ($options['w'] ?? $options['max_width'])))
            : null;
        $maxH = isset($options['h']) || isset($options['max_height'])
            ? min(self::MAX_DIMENSION, max(1, (int) ($options['h'] ?? $options['max_height'])))
            : null;

        // Ratio d'aspect de sortie
        if ($maxW !== null && $maxH !== null) {
            $outputRatio = (float) ($maxW / $maxH);
            if ($outputRatio > self::MAX_ASPECT_RATIO || $outputRatio < (1.0 / self::MAX_ASPECT_RATIO)) {
                throw new \InvalidArgumentException($this->trans('media.aspect_ratio_exceeded', 'Requested image aspect ratio exceeds maximum allowed threshold (25:1).'));
            }
            if ($maxW * $maxH > self::MAX_OUTPUT_PIXELS) {
                throw new \InvalidArgumentException($this->trans('media.pixel_limit_exceeded', 'Requested image dimensions exceed maximum output pixel limit (12 MP).'));
            }
        }

        $targetDir = $this->resolveTenantCacheDir($tenantUuid);
        $this->fs->mkdir($targetDir);

        $hash = md5($sourcePath . json_encode(compact('quality', 'fmt', 'strip', 'maxW', 'maxH')));
        $outputPath = $targetDir . '/opt_' . $hash . '.' . $fmt;

        if (file_exists($outputPath) && filesize($outputPath) > 0) {
            $optSize = (int) filesize($outputPath);
            $origSize = $safety['fileSize'];
            return [
                'path' => $outputPath,
                'original_size' => $origSize,
                'optimized_size' => $optSize,
                'savings_bytes' => max(0, $origSize - $optSize),
                'savings_percent' => $origSize > 0 ? round(max(0, ($origSize - $optSize) / $origSize) * 100, 2) : 0.0,
            ];
        }

        try {
            $image = $this->manager->decodePath($sourcePath);

            if ($maxW !== null || $maxH !== null) {
                $image->scaleDown(width: $maxW ?: null, height: $maxH ?: null);
            }

            // GD fallback : AVIF non supporté -> WebP
            if ($fmt === 'avif' && !extension_loaded('imagick')) {
                $outputPath = preg_replace('/\.avif$/', '.webp', $outputPath);
                $fmt = 'webp';
            }

            $image->save($outputPath, quality: $quality);

            $optSize = (int) filesize($outputPath);
            $origSize = $safety['fileSize'];

            return [
                'path' => $outputPath,
                'original_size' => $origSize,
                'optimized_size' => $optSize,
                'savings_bytes' => max(0, $origSize - $optSize),
                'savings_percent' => $origSize > 0 ? round(max(0, ($origSize - $optSize) / $origSize) * 100, 2) : 0.0,
            ];
        } catch (\Throwable $e) {
            throw new \RuntimeException($this->trans('media.optimization_failed', 'Image optimization failed.'), 0, $e);
        }
    }

    /**
     * Génère un rendu selon un preset prédéfini.
     */
    public function generatePreset(string $sourcePath, string $preset, ?string $tenantUuid = null): string
    {
        $preset = strtolower($preset);
        if (!isset(self::PRESETS[$preset])) {
            throw new \InvalidArgumentException($this->trans('media.preset_not_found', 'Image optimization preset not found.'));
        }

        $res = $this->optimize($sourcePath, self::PRESETS[$preset], $tenantUuid);
        return $res['path'];
    }

    /**
     * Retourne la liste des presets disponibles.
     */
    public function getPresets(): array
    {
        return self::PRESETS;
    }

    /**
     * Résout le répertoire de cache isolé pour un tenant.
     */
    public function resolveTenantCacheDir(?string $tenantUuid): string
    {
        if ($tenantUuid !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenantUuid)) {
            return $this->cacheDir . '/' . strtolower($tenantUuid);
        }

        return $this->cacheDir;
    }

    /**
     * Vérifie si l'allocation mémoire projetée risque de dépasser la limite de sécurité PHP.
     */
    private function isMemoryExhaustionRisk(int $projectedRequiredBytes): bool
    {
        $limitStr = ini_get('memory_limit');
        if ($limitStr === false || $limitStr === '' || $limitStr === '-1') {
            return false;
        }

        $limitBytes = self::parseMemoryLimit((string) $limitStr);
        if ($limitBytes <= 0) {
            return false;
        }

        $used = memory_get_usage(true);
        // Prévoir 2x la taille du buffer brut pour le traitement intermédiaire
        return ($used + ($projectedRequiredBytes * 2)) > ($limitBytes * 0.85);
    }

    public static function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);
        if ($limit === '-1' || $limit === '') {
            return -1;
        }

        $unit = strtolower(substr($limit, -1));
        $val = (int) substr($limit, 0, -1);

        return match ($unit) {
            'g' => $val * 1024 * 1024 * 1024,
            'm' => $val * 1024 * 1024,
            'k' => $val * 1024,
            default => (int) $limit,
        };
    }
}
