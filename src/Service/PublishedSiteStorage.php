<?php

namespace App\Service;

class PublishedSiteStorage
{
    public function __construct(
        private readonly string $baseDir,
    ) {}

    /**
     * Efface le repertoire du projet puis ecrit tous les fichiers.
     *
     * @param array<string,string> $files chemin relatif → contenu
     */
    public function publish(string $projectUuid, array $files): void
    {
        $dir = $this->projectDir($projectUuid);
        $this->removeDir($dir);
        mkdir($dir, 0755, true);

        foreach ($files as $relativePath => $content) {
            $abs = $dir . '/' . ltrim($relativePath, '/');
            $parent = dirname($abs);
            if (!is_dir($parent)) {
                mkdir($parent, 0755, true);
            }
            file_put_contents($abs, $content);
        }
    }

    /**
     * Lit un fichier ; retourne null si absent ou chemin suspect (traversal).
     */
    public function readFile(string $projectUuid, string $relativePath): ?string
    {
        $dir = $this->projectDir($projectUuid);
        $abs = realpath($dir . '/' . ltrim($relativePath, '/'));

        if ($abs === false) {
            return null;
        }

        // Protection traversal : le chemin doit rester sous le repertoire projet.
        if (!str_starts_with($abs, realpath($dir) . \DIRECTORY_SEPARATOR) && $abs !== realpath($dir)) {
            return null;
        }

        if (!is_file($abs)) {
            return null;
        }

        return file_get_contents($abs) ?: null;
    }

    /**
     * Retourne les chemins relatifs de tous les fichiers publies.
     *
     * @return string[]
     */
    public function listFiles(string $projectUuid): array
    {
        $dir = $this->projectDir($projectUuid);
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iter as $file) {
            if ($file->isFile()) {
                $files[] = substr($file->getPathname(), \strlen($dir) + 1);
            }
        }

        return array_map(fn (string $f): string => str_replace('\\', '/', $f), $files);
    }

    public function projectDir(string $projectUuid): string
    {
        return rtrim($this->baseDir, '/\\') . '/' . $projectUuid;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->removeDir($p) : unlink($p);
        }
        rmdir($dir);
    }
}
