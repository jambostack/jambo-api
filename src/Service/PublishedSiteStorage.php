<?php

namespace App\Service;

class PublishedSiteStorage
{
    public function __construct(
        private readonly string $baseDir,
    ) {}

    /**
     * Efface le répertoire du projet puis écrit tous les fichiers de manière atomique.
     *
     * @param array<string,string> $files chemin relatif → contenu
     */
    public function publish(string $projectUuid, array $files): void
    {
        $dir = $this->projectDir($projectUuid);

        // Écriture dans un répertoire temporaire pour éviter la corruption en cas de crash
        // et les race conditions entre deux publish concurrents.
        $stageDir = $dir . '.staging_' . getmypid();
        $this->removeDir($stageDir);
        mkdir($stageDir, 0755, true);
        $stageReal = realpath($stageDir);

        foreach ($files as $relativePath => $content) {
            // Protection anti-traversal : refuser les chemins qui tentent de sortir du répertoire.
            $clean = ltrim(str_replace('\\', '/', $relativePath), '/');
            if ($clean === '' || str_contains($clean, '..')) {
                continue;
            }

            $abs = $stageDir . '/' . $clean;
            $parent = dirname($abs);
            if (!is_dir($parent)) {
                mkdir($parent, 0755, true);
            }

            // Vérification post-résolution : le parent doit être sous le répertoire de staging.
            $parentReal = realpath($parent);
            if ($parentReal === false || (!str_starts_with($parentReal, $stageReal . \DIRECTORY_SEPARATOR) && $parentReal !== $stageReal)) {
                continue;
            }

            file_put_contents($abs, $content, \LOCK_EX);
        }

        // Remplacement atomique : swap l'ancien répertoire avec le nouveau.
        if (is_dir($dir)) {
            $oldDir = $dir . '.old_' . getmypid();
            rename($dir, $oldDir);
            rename($stageDir, $dir);
            $this->removeDir($oldDir);
        } else {
            rename($stageDir, $dir);
        }
    }

    /**
     * Lit un fichier ; retourne null si absent ou chemin suspect (traversal).
     */
    public function readFile(string $projectUuid, string $relativePath): ?string
    {
        $dir = $this->projectDir($projectUuid);
        $dirReal = realpath($dir);
        if ($dirReal === false) {
            return null; // répertoire projet inexistant
        }

        $abs = realpath($dir . '/' . ltrim($relativePath, '/'));

        if ($abs === false) {
            return null;
        }

        // Protection traversal : le chemin doit rester sous le répertoire projet.
        if (!str_starts_with($abs, $dirReal . \DIRECTORY_SEPARATOR) && $abs !== $dirReal) {
            return null;
        }

        if (!is_file($abs)) {
            return null;
        }

        $content = file_get_contents($abs);
        return $content !== false ? $content : null;
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

    /**
     * Supprime récursivement un répertoire et son contenu.
     * Version itérative pour éviter le risque de stack overflow sur les arborescences profondes.
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $toScan = [$dir];
        $allDirs = [];

        // Phase 1 : parcours en largeur pour collecter tous les répertoires.
        while ($toScan !== []) {
            $current = array_pop($toScan);
            $allDirs[] = $current;
            foreach (scandir($current) as $f) {
                if ($f === '.' || $f === '..') continue;
                $p = $current . '/' . $f;
                if (is_dir($p) && !is_link($p)) $toScan[] = $p;
            }
        }

        // Phase 2 : suppression des fichiers dans chaque répertoire.
        foreach ($allDirs as $d) {
            foreach (scandir($d) as $f) {
                if ($f === '.' || $f === '..') continue;
                $p = $d . '/' . $f;
                if (is_file($p) || is_link($p)) unlink($p);
            }
        }

        // Phase 3 : suppression des répertoires vides, du plus profond au parent.
        foreach (array_reverse($allDirs) as $d) {
            if (is_dir($d)) rmdir($d);
        }
    }
}
