<?php

declare(strict_types=1);

namespace App\Service;

class PublishedSiteStorage
{
    public const MAX_FILES_PER_PUBLICATION = 2000;
    public const MAX_SINGLE_FILE_SIZE_BYTES = 26214400; // 25 MB
    public const MAX_TOTAL_PUBLICATION_SIZE_BYTES = 104857600; // 100 MB

    private const FORBIDDEN_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'inc',
        'sh', 'bash', 'zsh', 'ksh', 'csh', 'bat', 'cmd', 'ps1', 'exe', 'bin',
        'cgi', 'pl', 'py', 'rb', 'jsp', 'asp', 'aspx', 'shtml',
    ];

    private const FORBIDDEN_FILENAMES = [
        '.htaccess', '.htpasswd', 'web.config', '.env', '.env.local',
        '.git', '.gitignore', '.gitmodules', '.gitlab-ci.yml',
    ];

    public function __construct(
        private readonly string $baseDir
    ) {
    }

    /**
     * Publishes a set of files for a given project, replacing any existing files.
     *
     * @param array<string, string> $files Map of relative file path to file content.
     */
    public function publish(string $projectUuid, array $files): void
    {
        $this->validateProjectUuid($projectUuid);

        if (count($files) > self::MAX_FILES_PER_PUBLICATION) {
            throw new \InvalidArgumentException(sprintf('Publication exceeds maximum allowed file count of %d files.', self::MAX_FILES_PER_PUBLICATION));
        }

        $totalBytes = 0;
        foreach ($files as $relativePath => $content) {
            if (!is_string($content)) {
                throw new \InvalidArgumentException("File content must be a string for {$relativePath}.");
            }
            $fileSize = strlen($content);
            if ($fileSize > self::MAX_SINGLE_FILE_SIZE_BYTES) {
                throw new \InvalidArgumentException(sprintf('File "%s" exceeds maximum single file size of %d MB.', $relativePath, self::MAX_SINGLE_FILE_SIZE_BYTES / (1024 * 1024)));
            }
            $totalBytes += $fileSize;
        }

        if ($totalBytes > self::MAX_TOTAL_PUBLICATION_SIZE_BYTES) {
            throw new \InvalidArgumentException(sprintf('Total publication payload exceeds maximum size limit of %d MB.', self::MAX_TOTAL_PUBLICATION_SIZE_BYTES / (1024 * 1024)));
        }

        $projectDir = $this->getProjectDir($projectUuid);

        if (is_dir($projectDir)) {
            $this->removeDirectory($projectDir);
        }

        mkdir($projectDir, 0755, true);
        $realProjectDir = realpath($projectDir);
        if ($realProjectDir === false) {
            throw new \RuntimeException("Unable to resolve project publication directory: {$projectDir}");
        }

        foreach ($files as $relativePath => $content) {
            $normalizedRelative = str_replace('\\', '/', $relativePath);
            $parts = array_values(array_filter(explode('/', trim($normalizedRelative, '/')), fn($p) => $p !== ''));
            if (empty($parts) || in_array('..', $parts, true) || in_array('.', $parts, true)) {
                throw new \InvalidArgumentException("Path traversal attempt detected in relative path: {$relativePath}");
            }

            $this->validateRelativePathSecurity($relativePath);

            $filePath = $projectDir . '/' . implode('/', $parts);
            $dir = dirname($filePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $realDir = realpath($dir);
            if ($realDir === false || (!str_starts_with($realDir, $realProjectDir . DIRECTORY_SEPARATOR) && $realDir !== $realProjectDir)) {
                throw new \InvalidArgumentException("Directory path resolves outside the project publication root: {$relativePath}");
            }

            file_put_contents($filePath, $content);
            @chmod($filePath, 0644);

            $realFilePath = realpath($filePath);
            if ($realFilePath === false || !str_starts_with($realFilePath, $realProjectDir . DIRECTORY_SEPARATOR)) {
                @unlink($filePath);
                throw new \InvalidArgumentException("File path resolves outside the project publication root: {$relativePath}");
            }
        }
    }

    /**
     * Reads a file published for a project. Returns null if missing or traversal attempt.
     */
    public function readFile(string $projectUuid, string $relativePath): ?string
    {
        if (!preg_match('/^[a-zA-Z0-9_\-]{1,64}$/', $projectUuid)) {
            return null;
        }

        if (str_contains($relativePath, "\0") || str_contains($relativePath, '..')) {
            return null;
        }

        $projectDir = $this->getProjectDir($projectUuid);
        $realProjectDir = realpath($projectDir);

        if ($realProjectDir === false) {
            return null;
        }

        $targetPath = $projectDir . '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
        $realTargetPath = realpath($targetPath);

        if ($realTargetPath === false || !is_file($realTargetPath)) {
            return null;
        }

        // Prevent path traversal
        if (!str_starts_with($realTargetPath, $realProjectDir . DIRECTORY_SEPARATOR) && $realTargetPath !== $realProjectDir) {
            return null;
        }

        $content = file_get_contents($realTargetPath);
        return $content !== false ? $content : null;
    }

    /**
     * Lists all relative file paths published for a project.
     *
     * @return list<string>
     */
    public function listFiles(string $projectUuid): array
    {
        if (!preg_match('/^[a-zA-Z0-9_\-]{1,64}$/', $projectUuid)) {
            return [];
        }

        $projectDir = $this->getProjectDir($projectUuid);
        $realProjectDir = realpath($projectDir);

        if ($realProjectDir === false || !is_dir($realProjectDir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($realProjectDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        $baseLen = strlen($realProjectDir) + 1;
        $count = 0;
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                if (++$count > self::MAX_FILES_PER_PUBLICATION) {
                    break;
                }
                $subPath = substr($file->getPathname(), $baseLen);
                $files[] = str_replace(DIRECTORY_SEPARATOR, '/', $subPath);
            }
        }

        return $files;
    }

    /**
     * Safely deletes all published assets for a given project.
     */
    public function deleteProject(string $projectUuid): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_\-]{1,64}$/', $projectUuid)) {
            return false;
        }

        $projectDir = $this->getProjectDir($projectUuid);
        if (is_dir($projectDir)) {
            $this->removeDirectory($projectDir);
            return true;
        }

        return false;
    }

    private function validateProjectUuid(string $projectUuid): void
    {
        if (!preg_match('/^[a-zA-Z0-9_\-]{1,64}$/', $projectUuid)) {
            throw new \InvalidArgumentException("Invalid project identifier: {$projectUuid}");
        }
    }

    private function validateRelativePathSecurity(string $relativePath): void
    {
        if (str_contains($relativePath, "\0")) {
            throw new \InvalidArgumentException("Null byte detected in relative path: {$relativePath}");
        }

        $normalized = str_replace('\\', '/', $relativePath);
        if (str_starts_with($normalized, '/') || preg_match('/^[a-zA-Z]:/', $normalized)) {
            throw new \InvalidArgumentException("Absolute path not allowed: {$relativePath}");
        }

        $baseName = strtolower(basename($normalized));
        if (in_array($baseName, self::FORBIDDEN_FILENAMES, true)) {
            throw new \InvalidArgumentException("Forbidden sensitive file detected: {$relativePath}");
        }

        $ext = strtolower(pathinfo($baseName, PATHINFO_EXTENSION));
        if (in_array($ext, self::FORBIDDEN_EXTENSIONS, true)) {
            throw new \InvalidArgumentException("Forbidden executable file type detected: {$relativePath}");
        }
    }

    private function getProjectDir(string $projectUuid): string
    {
        return rtrim($this->baseDir, '/\\') . DIRECTORY_SEPARATOR . $projectUuid;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
