<?php

namespace App\Service;

use App\Entity\Project;
use App\Repository\MediaRepository;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class UploadSecurityValidator
{
    public const ALLOWED_EXTENSIONS = [
        // Images
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp', 'ico', 'tiff', 'tif',
        // Vidéos
        'mp4', 'webm', 'mov', 'avi', 'mkv',
        // Audio
        'mp3', 'wav', 'ogg', 'aac', 'flac', 'm4a',
        // Documents
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp',
        'txt', 'csv', 'json', 'xml', 'yaml', 'yml', 'md',
        // Archives
        'zip', 'gz', 'tar', '7z', 'rar',
    ];

    public const FORBIDDEN_EXTENSIONS = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phar',
        'cgi', 'pl', 'py', 'sh', 'bash', 'zsh', 'ksh', 'csh', 'bat', 'cmd',
        'exe', 'com', 'dll', 'vbs', 'vbe', 'js', 'mjs', 'jsp', 'asp', 'aspx',
        'htaccess', 'htpasswd', 'ini', 'env',
    ];

    private const DANGEROUS_MIMES = [
        'text/x-php',
        'application/x-php',
        'application/x-httpd-php',
        'application/x-executable',
        'application/x-dosexec',
        'application/x-sharedlib',
        'application/x-msdos-program',
        'text/x-shellscript',
        'text/x-python',
        'text/x-perl',
    ];

    public function __construct(
        private readonly MediaRepository $mediaRepository,
    ) {}

    /**
     * Valide et nettoie un nom de fichier original.
     */
    public function sanitizeAndValidateFilename(string $filename): string
    {
        if (str_contains($filename, "\0")) {
            throw new \InvalidArgumentException('Filename contains forbidden null byte.');
        }

        $clean = basename($filename);
        $clean = preg_replace('/[\x00-\x1F\/\\\\]/', '', $clean);

        if ($clean === '' || $clean === '.' || $clean === '..') {
            throw new \InvalidArgumentException('Invalid filename.');
        }

        $extension = strtolower(pathinfo($clean, PATHINFO_EXTENSION));
        if ($extension === '') {
            throw new \InvalidArgumentException('File extension is required.');
        }

        if (in_array($extension, self::FORBIDDEN_EXTENSIONS, true)) {
            throw new \InvalidArgumentException(sprintf('File extension ".%s" is strictly forbidden.', $extension));
        }

        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new \InvalidArgumentException(sprintf('File type ".%s" is not allowed.', $extension));
        }

        return $clean;
    }

    /**
     * Valide le contenu réel du fichier (magic bytes, absence de code PHP injecté, sanitization SVG).
     */
    public function validateFileContent(string $filePath, string $extension): string
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \InvalidArgumentException('Uploaded file cannot be read on disk.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($filePath);
        if ($mime === false) {
            $mime = 'application/octet-stream';
        }

        $extension = strtolower($extension);

        // 1. Refuser formellement les types MIME dangereux / exécutables
        if (in_array(strtolower($mime), self::DANGEROUS_MIMES, true)) {
            throw new \InvalidArgumentException(sprintf('File MIME type "%s" is forbidden.', $mime));
        }

        // 2. Détection polyglotte / injection PHP dans le fichier
        $handle = fopen($filePath, 'rb');
        if ($handle) {
            $sample = fread($handle, 8192);
            fclose($handle);

            if ($sample !== false) {
                $lowerSample = strtolower($sample);
                if (
                    str_contains($lowerSample, '<?php') ||
                    str_contains($lowerSample, '<?=') ||
                    str_contains($lowerSample, '<script language="php"') ||
                    str_contains($lowerSample, '<script language=\'php\'')
                ) {
                    throw new \InvalidArgumentException('File contains dangerous PHP executable code.');
                }
            }
        }

        // 3. Validation signatures Magic Bytes strictes pour les formats binaires majeurs
        $handle = fopen($filePath, 'rb');
        $magic = $handle ? fread($handle, 16) : '';
        if ($handle) {
            fclose($handle);
        }

        if (in_array($extension, ['jpg', 'jpeg'], true)) {
            if (strlen($magic) < 3 || substr($magic, 0, 3) !== "\xFF\xD8\xFF") {
                throw new \InvalidArgumentException('Corrupted or invalid JPEG image header.');
            }
        } elseif ($extension === 'png') {
            if (strlen($magic) < 8 || substr($magic, 0, 8) !== "\x89PNG\r\n\x1a\n") {
                throw new \InvalidArgumentException('Corrupted or invalid PNG image header.');
            }
        } elseif ($extension === 'gif') {
            if (strlen($magic) < 6 || (!str_starts_with($magic, 'GIF87a') && !str_starts_with($magic, 'GIF89a'))) {
                throw new \InvalidArgumentException('Corrupted or invalid GIF image header.');
            }
        } elseif ($extension === 'pdf') {
            if (strlen($magic) < 4 || substr($magic, 0, 4) !== '%PDF') {
                throw new \InvalidArgumentException('Corrupted or invalid PDF header.');
            }
        } elseif ($extension === 'webp') {
            if (strlen($magic) < 12 || substr($magic, 0, 4) !== 'RIFF' || substr($magic, 8, 4) !== 'WEBP') {
                throw new \InvalidArgumentException('Corrupted or invalid WebP image header.');
            }
        }

        // 4. Protection XSS pour les fichiers SVG
        if ($extension === 'svg') {
            $content = file_get_contents($filePath);
            if ($content !== false) {
                $lower = strtolower($content);
                $forbiddenPatterns = [
                    '<script',
                    'javascript:',
                    'onload=',
                    'onerror=',
                    'onclick=',
                    'onmouseover=',
                    'xmlns:script',
                    'xlink:href="javascript:',
                    "xlink:href='javascript:",
                ];
                foreach ($forbiddenPatterns as $pattern) {
                    if (str_contains($lower, $pattern)) {
                        throw new \InvalidArgumentException(sprintf('SVG file contains dangerous active script pattern: %s', $pattern));
                    }
                }
            }
        }

        return $mime;
    }

    /**
     * Valide la taille du fichier et vérifie le respect des quotas de stockage du projet.
     */
    public function validateSizeAndQuota(Project $project, int $fileSize): void
    {
        if ($fileSize <= 0) {
            throw new \InvalidArgumentException('File size must be greater than 0.');
        }

        // Taille maximale par fichier (défaut 100 Mo)
        $maxMb = (int) ($project->settings['upload_max_file_size_mb'] ?? $_ENV['UPLOAD_MAX_FILE_SIZE_MB'] ?? 100);
        if ($maxMb <= 0) {
            $maxMb = 100;
        }
        $maxBytes = $maxMb * 1024 * 1024;

        if ($fileSize > $maxBytes) {
            throw new \InvalidArgumentException(sprintf('File size (%d bytes) exceeds maximum allowed limit (%d MB).', $fileSize, $maxMb));
        }

        // Quota global de stockage du projet (en Mo)
        $quotaMb = null;
        if (isset($project->settings['storage_quota_mb'])) {
            $quotaMb = (int) $project->settings['storage_quota_mb'];
        } elseif (isset($_ENV['PROJECT_STORAGE_QUOTA_MB'])) {
            $quotaMb = (int) $_ENV['PROJECT_STORAGE_QUOTA_MB'];
        }

        if ($quotaMb !== null && $quotaMb > 0) {
            $quotaBytes = $quotaMb * 1024 * 1024;
            $currentUsedBytes = $this->mediaRepository->getTotalSizeByProject($project);

            if (($currentUsedBytes + $fileSize) > $quotaBytes) {
                throw new \InvalidArgumentException(sprintf(
                    'Project storage quota exceeded (%d MB used / %d MB limit).',
                    (int) ceil(($currentUsedBytes + $fileSize) / (1024 * 1024)),
                    $quotaMb
                ));
            }
        }
    }

    /**
     * Valide un objet UploadedFile complet de Symfony.
     */
    public function validateUploadedFile(UploadedFile $uploadedFile, Project $project): string
    {
        $filename = $this->sanitizeAndValidateFilename($uploadedFile->getClientOriginalName());
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $fileSize = $uploadedFile->getSize();
        $this->validateSizeAndQuota($project, $fileSize);

        $path = $uploadedFile->getRealPath();
        if ($path === false) {
            $path = $uploadedFile->getPathname();
        }

        return $this->validateFileContent($path, $ext);
    }
}
