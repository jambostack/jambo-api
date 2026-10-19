<?php

declare(strict_types=1);

namespace App\Service;

class PublishedSiteStorage
{
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
            $parts = explode('/', trim($normalizedRelative, '/'));
            if (in_array('..', $parts, true) || in_array('.', $parts, true)) {
                throw new \InvalidArgumentException("Path traversal attempt detected in relative path: {$relativePath}");
            }

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
        $projectDir = $this->getProjectDir($projectUuid);
        $realProjectDir = realpath($projectDir);

        if ($realProjectDir === false) {
            return null;
        }

        $targetPath = $projectDir . '/' . ltrim($relativePath, '/');
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
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $subPath = substr($file->getPathname(), $baseLen);
                $files[] = str_replace(DIRECTORY_SEPARATOR, '/', $subPath);
            }
        }

        return $files;
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
