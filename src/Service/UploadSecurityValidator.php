<?php

namespace App\Service;

use App\Entity\Project;
use App\Repository\MediaRepository;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private readonly ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            $translated = $this->translator->trans($id, $parameters, 'messages');
            if ($translated !== $id) {
                return $translated;
            }
        }
        if ($default !== null) {
            return strtr($default, $parameters);
        }
        return $id;
    }

    /**
     * Valide et nettoie un nom de fichier original.
     */
    public function sanitizeAndValidateFilename(string $filename): string
    {
        if (str_contains($filename, "\0")) {
            throw new \InvalidArgumentException($this->trans('upload.error.null_byte', [], 'Filename contains forbidden null byte.'));
        }

        $clean = basename($filename);
        $clean = preg_replace('/[\x00-\x1F\/\\\\]/', '', $clean);

        if ($clean === '' || $clean === '.' || $clean === '..') {
            throw new \InvalidArgumentException($this->trans('upload.error.invalid_filename', [], 'Invalid filename.'));
        }

        $extension = strtolower(pathinfo($clean, PATHINFO_EXTENSION));
        if ($extension === '') {
            throw new \InvalidArgumentException($this->trans('upload.error.extension_required', [], 'File extension is required.'));
        }

        if (in_array($extension, self::FORBIDDEN_EXTENSIONS, true)) {
            throw new \InvalidArgumentException($this->trans('upload.error.extension_forbidden', ['%extension%' => $extension], sprintf('File extension ".%s" is strictly forbidden.', $extension)));
        }

        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new \InvalidArgumentException($this->trans('upload.error.extension_not_allowed', ['%extension%' => $extension], sprintf('File type ".%s" is not allowed.', $extension)));
        }

        return $clean;
    }

    /**
     * Valide et nettoie un type MIME (anti-injection CRLF, pas d'exécutables ou formats dangereux).
     */
    public function validateMimeType(string $mimeType): string
    {
        $mime = trim($mimeType);
        if ($mime === '') {
            return 'application/octet-stream';
        }

        if (strlen($mime) > 255 || preg_match('/[\x00-\x1F\x7F]/', $mime)) {
            throw new \InvalidArgumentException($this->trans('upload.error.mime_control_chars', [], 'MIME type contains invalid control characters or exceeds 255 characters.'));
        }

        if (!preg_match('~^[a-zA-Z0-9!#$&^_.+-]+/[a-zA-Z0-9!#$&^_.+-]+(?:;\s*[a-zA-Z0-9!#$&^_.+-]+=[a-zA-Z0-9!#$&^_.+-]+)*$~', $mime)) {
            throw new \InvalidArgumentException($this->trans('upload.error.mime_invalid_format', ['%mime%' => $mime], sprintf('Invalid MIME type format: "%s".', $mime)));
        }

        $baseMime = strtolower(explode(';', $mime)[0]);
        if (in_array($baseMime, self::DANGEROUS_MIMES, true)) {
            throw new \InvalidArgumentException($this->trans('upload.error.mime_forbidden', ['%mime%' => $baseMime], sprintf('File MIME type "%s" is strictly forbidden.', $baseMime)));
        }

        return $mime;
    }

    /**
     * Valide le contenu réel d'un échantillon binaire ou mémoire (magic bytes, absence de polyglotte PHP / scripts).
     */
    public function validateBufferContent(string $sample, string $extension): void
    {
        $extension = strtolower($extension);

        // 1. Détection polyglotte / injection PHP
        $lowerSample = strtolower($sample);
        if (
            str_contains($lowerSample, '<?php') ||
            str_contains($lowerSample, '<?=') ||
            str_contains($lowerSample, '<script language="php"') ||
            str_contains($lowerSample, '<script language=\'php\'')
        ) {
            throw new \InvalidArgumentException($this->trans('upload.error.php_code_detected', [], 'File contains dangerous PHP executable code.'));
        }

        // 2. Détection scripts actifs dans les formats de texte / données
        if (in_array($extension, ['txt', 'csv', 'json', 'yaml', 'yml', 'md'], true)) {
            if (
                preg_match('/<\s*(?:script|iframe|object|embed|applet)\b/i', $sample) ||
                preg_match('/\bon[a-z0-9_-]+\s*=/i', $sample)
            ) {
                throw new \InvalidArgumentException($this->trans('upload.error.script_injection_detected', [], 'Text or data file contains active script injection tags.'));
            }
        }

        // 3. Validation signatures Magic Bytes strictes pour les formats binaires majeurs
        $magic = substr($sample, 0, 32);

        if (in_array($extension, ['jpg', 'jpeg'], true)) {
            if (strlen($magic) < 3 || substr($magic, 0, 3) !== "\xFF\xD8\xFF") {
                throw new \InvalidArgumentException($this->trans('upload.error.corrupted_jpeg', [], 'Corrupted or invalid JPEG image header.'));
            }
        } elseif ($extension === 'png') {
            if (strlen($magic) < 8 || substr($magic, 0, 8) !== "\x89PNG\r\n\x1a\n") {
                throw new \InvalidArgumentException($this->trans('upload.error.corrupted_png', [], 'Corrupted or invalid PNG image header.'));
            }
        } elseif ($extension === 'gif') {
            if (strlen($magic) < 6 || (!str_starts_with($magic, 'GIF87a') && !str_starts_with($magic, 'GIF89a'))) {
                throw new \InvalidArgumentException($this->trans('upload.error.corrupted_gif', [], 'Corrupted or invalid GIF image header.'));
            }
        } elseif ($extension === 'pdf') {
            if (strlen($magic) < 4 || substr($magic, 0, 4) !== '%PDF') {
                throw new \InvalidArgumentException($this->trans('upload.error.corrupted_pdf', [], 'Corrupted or invalid PDF header.'));
            }
        } elseif ($extension === 'webp') {
            if (strlen($magic) < 12 || substr($magic, 0, 4) !== 'RIFF' || substr($magic, 8, 4) !== 'WEBP') {
                throw new \InvalidArgumentException($this->trans('upload.error.corrupted_webp', [], 'Corrupted or invalid WebP image header.'));
            }
        } elseif ($extension === 'avif') {
            if (strlen($magic) < 12 || substr($magic, 4, 4) !== 'ftyp') {
                throw new \InvalidArgumentException($this->trans('upload.error.corrupted_avif', [], 'Corrupted or invalid AVIF image header.'));
            }
        } elseif ($extension === 'bmp') {
            if (strlen($magic) < 2 || substr($magic, 0, 2) !== 'BM') {
                throw new \InvalidArgumentException($this->trans('upload.error.corrupted_bmp', [], 'Corrupted or invalid BMP image header.'));
            }
        } elseif ($extension === 'ico') {
            if (strlen($magic) < 4 || (substr($magic, 0, 4) !== "\x00\x00\x01\x00" && substr($magic, 0, 4) !== "\x00\x00\x02\x00")) {
                throw new \InvalidArgumentException($this->trans('upload.error.corrupted_ico', [], 'Corrupted or invalid ICO image header.'));
            }
        } elseif (in_array($extension, ['tif', 'tiff'], true)) {
            if (strlen($magic) < 4 || (substr($magic, 0, 4) !== "II*\x00" && substr($magic, 0, 4) !== "MM\x00*")) {
                throw new \InvalidArgumentException($this->trans('upload.error.corrupted_tiff', [], 'Corrupted or invalid TIFF image header.'));
            }
        } elseif (in_array($extension, ['mp4', 'mov', 'm4a'], true)) {
            if (strlen($magic) < 8 || substr($magic, 4, 4) !== 'ftyp') {
                throw new \InvalidArgumentException($this->trans('upload.error.corrupted_mp4', [], 'Corrupted or invalid MP4/MOV container header.'));
            }
        } elseif (in_array($extension, ['webm', 'mkv'], true)) {
            if (strlen($magic) < 4 || substr($magic, 0, 4) !== "\x1A\x45\xDF\xA3") {
                throw new \InvalidArgumentException($this->trans('upload.error.corrupted_matroska', [], 'Corrupted or invalid WebM/MKV container header.'));
            }
        } elseif ($extension === 'avi') {
            if (strlen($magic) < 12 || substr($magic, 0, 4) !== 'RIFF' || substr($magic, 8, 4) !== 'AVI ') {
                throw new \InvalidArgumentException($this->trans('upload.error.corrupted_avi', [], 'Corrupted or invalid AVI container header.'));
            }
        } elseif ($extension === 'wav') {
            if (strlen($magic) < 12 || substr($magic, 0, 4) !== 'RIFF' || substr($magic, 8, 4) !== 'WAVE') {
                throw new \InvalidArgumentException($this->trans('upload.error.corrupted_wav', [], 'Corrupted or invalid WAV audio header.'));
            }
        } elseif ($extension === 'ogg') {
            if (strlen($magic) < 4 || substr($magic, 0, 4) !== 'OggS') {
                throw new \InvalidArgumentException($this->trans('upload.error.corrupted_ogg', [], 'Corrupted or invalid OGG container header.'));
            }
        } elseif ($extension === 'flac') {
            if (strlen($magic) < 4 || substr($magic, 0, 4) !== 'fLaC') {
                throw new \InvalidArgumentException($this->trans('upload.error.corrupted_flac', [], 'Corrupted or invalid FLAC audio header.'));
            }
        } elseif ($extension === 'mp3') {
            $hasId3 = str_starts_with($magic, 'ID3');
            $hasFrameSync = strlen($magic) >= 2 && ord($magic[0]) === 0xFF && (ord($magic[1]) & 0xE0) === 0xE0;
            if (!$hasId3 && !$hasFrameSync) {
                throw new \InvalidArgumentException($this->trans('upload.error.corrupted_mp3', [], 'Corrupted or invalid MP3 audio header.'));
            }
        } elseif (in_array($extension, ['zip', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp'], true)) {
            if (strlen($magic) < 4 || (
                substr($magic, 0, 4) !== "PK\x03\x04" &&
                substr($magic, 0, 4) !== "PK\x05\x06" &&
                substr($magic, 0, 4) !== "PK\x07\x08"
            )) {
                throw new \InvalidArgumentException($this->trans('upload.error.corrupted_archive', [], 'Corrupted or invalid ZIP/OpenDocument header.'));
            }
        } elseif ($extension === 'gz') {
            if (strlen($magic) < 2 || substr($magic, 0, 2) !== "\x1F\x8B") {
                throw new \InvalidArgumentException($this->trans('upload.error.corrupted_gzip', [], 'Corrupted or invalid GZIP header.'));
            }
        } elseif ($extension === '7z') {
            if (strlen($magic) < 6 || substr($magic, 0, 6) !== "7z\xBC\xAF\x27\x1C") {
                throw new \InvalidArgumentException($this->trans('upload.error.corrupted_7z', [], 'Corrupted or invalid 7-Zip header.'));
            }
        }
    }

    /**
     * Valide le contenu réel du fichier (magic bytes, absence de code PHP injecté, sanitization SVG).
     */
    public function validateFileContent(string $filePath, string $extension): string
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \InvalidArgumentException($this->trans('upload.error.cannot_read', [], 'Uploaded file cannot be read on disk.'));
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($filePath);
        if ($mime === false) {
            $mime = 'application/octet-stream';
        }

        $extension = strtolower($extension);

        // 1. Refuser formellement les types MIME dangereux / exécutables
        if (in_array(strtolower($mime), self::DANGEROUS_MIMES, true)) {
            throw new \InvalidArgumentException($this->trans('upload.error.mime_not_allowed', ['%mime%' => $mime], sprintf('File MIME type "%s" is forbidden.', $mime)));
        }

        // 2. Détection polyglotte et magic bytes via échantillon
        $handle = fopen($filePath, 'rb');
        $sample = $handle ? fread($handle, 65536) : '';
        if ($handle) {
            fclose($handle);
        }

        if ($sample !== '') {
            $this->validateBufferContent($sample, $extension);
        }

        // 3. Validation de cohérence MIME déclarée vs détectée
        $baseMime = strtolower(explode(';', $mime)[0]);
        $images = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico', 'tiff', 'tif', 'svg'];
        if (in_array($extension, $images, true)) {
            if (!str_starts_with($baseMime, 'image/') && !in_array($baseMime, ['application/octet-stream', 'text/xml', 'application/xml', 'text/plain'], true)) {
                throw new \InvalidArgumentException($this->trans('upload.error.mime_mismatch_image', ['%mime%' => $baseMime, '%ext%' => $extension], sprintf('MIME type "%s" does not match image extension ".%s".', $baseMime, $extension)));
            }
        }
        $audios = ['mp3', 'wav', 'ogg', 'aac', 'flac', 'm4a'];
        if (in_array($extension, $audios, true)) {
            if (!str_starts_with($baseMime, 'audio/') && !in_array($baseMime, ['application/ogg', 'application/octet-stream'], true)) {
                throw new \InvalidArgumentException($this->trans('upload.error.mime_mismatch_audio', ['%mime%' => $baseMime, '%ext%' => $extension], sprintf('MIME type "%s" does not match audio extension ".%s".', $baseMime, $extension)));
            }
        }
        $videos = ['mp4', 'webm', 'mov', 'avi', 'mkv'];
        if (in_array($extension, $videos, true)) {
            if (!str_starts_with($baseMime, 'video/') && !in_array($baseMime, ['application/mp4', 'application/octet-stream'], true)) {
                throw new \InvalidArgumentException($this->trans('upload.error.mime_mismatch_video', ['%mime%' => $baseMime, '%ext%' => $extension], sprintf('MIME type "%s" does not match video extension ".%s".', $baseMime, $extension)));
            }
        }
        if ($extension === 'pdf') {
            if ($baseMime !== 'application/pdf' && $baseMime !== 'application/x-pdf' && $baseMime !== 'application/octet-stream') {
                throw new \InvalidArgumentException($this->trans('upload.error.mime_mismatch_pdf', ['%mime%' => $baseMime], sprintf('MIME type "%s" is not valid for PDF document.', $baseMime)));
            }
        }

        // 4. Protection anti-XXE et anti-XSS pour les fichiers SVG et XML
        if (in_array($extension, ['svg', 'xml'], true)) {
            $content = file_get_contents($filePath);
            if ($content !== false) {
                // Détection XXE / DTD injection (DOCTYPE ou ENTITY)
                if (preg_match('/<!(?:DOCTYPE|ENTITY)\b/i', $content)) {
                    throw new \InvalidArgumentException($this->trans('upload.error.xml_doctype_forbidden', [], 'XML/SVG file containing DOCTYPE or ENTITY definitions is strictly forbidden.'));
                }

                if ($extension === 'svg') {
                    $lower = strtolower($content);
                    // 4a. Balises actives dangereuses
                    if (preg_match('/<\s*(?:script|foreignobject|iframe|object|embed|applet|meta|form|input)\b/i', $content)) {
                        throw new \InvalidArgumentException($this->trans('upload.error.svg_script_detected', [], 'SVG file contains dangerous active script pattern: <script>'));
                    }
                    // 4b. Gestionnaires d'événements inline (onload, onclick, onmouseover, onfocus, etc.)
                    if (preg_match('/\bon[a-z0-9_-]+\s*=/i', $content)) {
                        throw new \InvalidArgumentException($this->trans('upload.error.svg_event_handlers', [], 'SVG file contains dangerous inline event handler attributes.'));
                    }
                    // 4c. URLs actives ou data:text/html dans href ou xlink:href
                    if (preg_match('/(?:href|xlink:href)\s*=\s*["\']?\s*(?:javascript:|data:\s*text\/html)/i', $content)) {
                        throw new \InvalidArgumentException($this->trans('upload.error.svg_href_script', [], 'SVG file contains dangerous script or data URI in href attribute.'));
                    }
                    // 4d. Animations injectant javascript dans les attributs (animate/set)
                    if (preg_match('/<\s*(?:animate|set)\b[^>]*\bvalues?\s*=\s*["\']?[^"\'>]*javascript:/i', $content)) {
                        throw new \InvalidArgumentException($this->trans('upload.error.svg_animation_script', [], 'SVG file contains dangerous script animation pattern.'));
                    }
                    // 4e. Recherche globale javascript: ou XML namespaces frauduleux (ex: xmlns:script)
                    if (str_contains($lower, 'xmlns:script') || str_contains($lower, 'javascript:')) {
                        throw new \InvalidArgumentException($this->trans('upload.error.svg_forbidden_pattern', [], 'SVG file contains forbidden script pattern or namespace.'));
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
            throw new \InvalidArgumentException($this->trans('upload.error.size_zero', [], 'File size must be greater than 0.'));
        }

        // Vérification de la disponibilité physique d'espace disque hôte (anti-épuisement de partition)
        $diskFree = @disk_free_space(sys_get_temp_dir());
        if ($diskFree !== false && $diskFree < 52428800) { // 50 MB
            throw new \RuntimeException($this->trans('upload.error.server_disk_full', [], 'Server storage volume has critically low disk space. Upload rejected.'));
        }

        // Taille maximale par fichier (défaut 100 Mo)
        $maxMb = (int) ($project->settings['upload_max_file_size_mb'] ?? $_ENV['UPLOAD_MAX_FILE_SIZE_MB'] ?? 100);
        if ($maxMb <= 0) {
            $maxMb = 100;
        }
        $maxBytes = $maxMb * 1024 * 1024;

        if ($fileSize > $maxBytes) {
            throw new \InvalidArgumentException($this->trans('upload.error.size_exceeded', ['%size%' => (string) $fileSize, '%max%' => (string) $maxMb], sprintf('File size (%d bytes) exceeds maximum allowed limit (%d MB).', $fileSize, $maxMb)));
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
                $usedMb = (int) ceil(($currentUsedBytes + $fileSize) / (1024 * 1024));
                throw new \InvalidArgumentException($this->trans(
                    'upload.error.quota_exceeded',
                    ['%used%' => (string) $usedMb, '%limit%' => (string) $quotaMb],
                    sprintf('Project storage quota exceeded (%d MB used / %d MB limit).', $usedMb, $quotaMb)
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
