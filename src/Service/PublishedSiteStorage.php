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

        foreach ($files as $relativePath => $content) {
            $filePath = $projectDir . '/' . ltrim($relativePath, '/');
            $dir = dirname($filePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($filePath, $content);
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
