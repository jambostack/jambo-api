<?php

namespace App\Service\ExportImport;

use App\Dto\ConflictItem;
use App\Dto\ImportOptions;
use App\Entity\Project;
use Symfony\Contracts\Translation\TranslatorInterface;

class ProjectImporter
{
    /** @var array<string, ImportHandlerInterface> */
    private array $handlers = [];

    public function __construct(
        private Import\StructureImportHandler $structureHandler,
        Import\ContentImportHandler $contentHandler,
        Import\MediaImportHandler $mediaHandler,
        Import\SettingsImportHandler $settingsHandler,
        private Import\EndUserImportHandler $endUserHandler,
        private string $projectDir,
        private ?TranslatorInterface $translator = null,
    ) {
        $this->handlers['structure'] = $structureHandler;
        $this->handlers['content'] = $contentHandler;
        $this->handlers['media'] = $mediaHandler;
        $this->handlers['settings'] = $settingsHandler;
        $this->handlers['end_users'] = $endUserHandler;
    }

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters, 'messages');
        }
        return $default ?? $id;
    }

    private function getTempDir(): string
    {
        $dir = $this->projectDir . '/var/tmp';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir;
    }

    public function extractZip(string $zipPath): string
    {
        $tempDir = $this->getTempDir() . '/jambo-import-' . uniqid();
        mkdir($tempDir, 0777, true);

        try {
            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new \RuntimeException($this->trans('import.zip.open_failed', ['%path%' => $zipPath], 'Cannot open ZIP file: ' . $zipPath));
            }

            $maxFiles = 5000;
            $maxTotalSize = 250 * 1024 * 1024; // 250 MB
            $maxRatio = 200; // Ratio maximal de décompression anti zip-bomb
            $totalSize = 0;

            $blockedExtensions = [
                'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps',
                'sh', 'bash', 'exe', 'bat', 'cmd', 'com', 'cgi', 'pl', 'py',
                'htaccess', 'htpasswd', 'ini', 'conf', 'env', 'jsp', 'asp', 'aspx',
            ];

            if ($zip->numFiles > $maxFiles) {
                $zip->close();
                throw new \RuntimeException($this->trans('import.zip.too_many_files', ['%max%' => $maxFiles], 'ZIP file contains too many files (max ' . $maxFiles . ')'));
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!$stat) {
                    continue;
                }
                $entryName = $stat['name'];

                // Reject symbolic links in ZIP archives
                $extAttr = $stat['external_attributes'] ?? 0;
                $mode = ($extAttr >> 16) & 0170000;
                if ($mode === 0120000) {
                    $zip->close();
                    throw new \RuntimeException($this->trans('import.zip.symlinks_forbidden', ['%file%' => $entryName], 'Symbolic links are strictly forbidden in ZIP archives: ' . $entryName));
                }

                // Prevent Zip Slip / path traversal, null bytes and Windows drive paths
                if (
                    str_contains($entryName, "\0") ||
                    str_contains($entryName, '../') ||
                    str_contains($entryName, '..\\') ||
                    str_contains($entryName, '..') ||
                    str_starts_with($entryName, '/') ||
                    str_starts_with($entryName, '\\') ||
                    preg_match('/^[a-zA-Z]:/', $entryName)
                ) {
                    $zip->close();
                    throw new \RuntimeException($this->trans('import.zip.traversal_detected', ['%file%' => $entryName], 'Invalid file path in ZIP archive (directory traversal detected): ' . $entryName));
                }

                // Reject dangerous and executable file extensions
                $entryExt = strtolower(pathinfo($entryName, PATHINFO_EXTENSION));
                if (in_array($entryExt, $blockedExtensions, true)) {
                    $zip->close();
                    throw new \RuntimeException($this->trans('import.zip.dangerous_extension', ['%file%' => $entryName], 'Executable or dangerous file extension forbidden in ZIP archive: ' . $entryName));
                }

                // Reject hidden files and directories (.env, .git, .htaccess)
                $normalizedParts = explode('/', str_replace('\\', '/', $entryName));
                foreach ($normalizedParts as $part) {
                    if (str_starts_with($part, '.') && $part !== '.' && $part !== '..') {
                        $zip->close();
                        throw new \RuntimeException($this->trans('import.zip.hidden_files_forbidden', ['%file%' => $entryName], 'Hidden files or directories forbidden in ZIP archive: ' . $entryName));
                    }
                }

                // Anti zip-bomb: check compression ratio if compressed size is reported
                $compSize = $stat['comp_size'] ?? 0;
                $uncompSize = $stat['size'] ?? 0;
                if ($compSize > 0 && ($uncompSize / $compSize) > $maxRatio && $uncompSize > 1048576) {
                    $zip->close();
                    throw new \RuntimeException($this->trans('import.zip.excessive_compression_ratio', ['%file%' => $entryName], 'Excessive compression ratio detected (potential decompression bomb): ' . $entryName));
                }

                $totalSize += $uncompSize;
                if ($totalSize > $maxTotalSize) {
                    $zip->close();
                    throw new \RuntimeException($this->trans('import.zip.total_size_exceeded', ['%max%' => 250], 'Uncompressed ZIP size exceeds safety limit of 250MB'));
                }
            }

            $zip->extractTo($tempDir);
            $zip->close();

            return $tempDir;
        } catch (\Throwable $e) {
            $this->removeDir($tempDir);
            throw $e;
        }
    }

    public function validateManifest(string $extractedDir): array
    {
        $manifestPath = $extractedDir . '/manifest.json';
        if (!file_exists($manifestPath)) {
            throw new \RuntimeException($this->trans('import.manifest.not_found', [], 'Invalid export package: manifest.json not found'));
        }

        if (filesize($manifestPath) > 5 * 1024 * 1024) {
            throw new \RuntimeException($this->trans('import.manifest.too_large', ['%max%' => 5], 'Manifest file is too large (max 5MB).'));
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (!$manifest || !isset($manifest['version'])) {
            throw new \RuntimeException($this->trans('import.manifest.malformed', [], 'Invalid export package: malformed manifest.json'));
        }

        // Contrôle de taille des fichiers de données
        foreach (['structure.json' => 10 * 1024 * 1024, 'content.json' => 50 * 1024 * 1024, 'end_users.json' => 20 * 1024 * 1024, 'media.json' => 10 * 1024 * 1024, 'settings.json' => 5 * 1024 * 1024] as $file => $maxSize) {
            $path = $extractedDir . '/' . $file;
            if (file_exists($path) && filesize($path) > $maxSize) {
                $maxMb = (int) ($maxSize / 1048576);
                throw new \RuntimeException($this->trans('import.payload.size_exceeded', ['%file%' => $file, '%max%' => $maxMb], sprintf('Import payload "%s" exceeds size limit (%d MB).', $file, $maxMb)));
            }
        }

        return $manifest;
    }

    /**
     * @return ConflictItem[]
     */
    public function previewConflicts(Project $project, string $extractedDir): array
    {
        $allConflicts = [];
        $manifest = $this->validateManifest($extractedDir);

        foreach ($manifest['included'] ?? [] as $key) {
            if (isset($this->handlers[$key])) {
                $conflicts = $this->handlers[$key]->previewConflicts($project, $extractedDir);
                $allConflicts = array_merge($allConflicts, $conflicts);
            }
        }

        return $allConflicts;
    }

    public function import(Project $project, string $extractedDir, ImportOptions $options): void
    {
        $manifest = $this->validateManifest($extractedDir);
        $uuidMap = [];

        // Import in order: structure -> media -> content -> end_users -> settings
        $order = ['structure', 'media', 'content', 'end_users', 'settings'];

        foreach ($order as $key) {
            if (!in_array($key, $manifest['included'] ?? [], true)) {
                continue;
            }
            if (!isset($this->handlers[$key])) {
                continue;
            }
            $this->handlers[$key]->import($project, $extractedDir, $options, $uuidMap);
        }
    }

    public function getMediaHandler(): Import\MediaImportHandler
    {
        return $this->handlers['media'];
    }

    public function getStructureHandler(): Import\StructureImportHandler
    {
        return $this->structureHandler;
    }

    public function getEndUserHandler(): Import\EndUserImportHandler
    {
        return $this->endUserHandler;
    }

    public function cleanup(string $extractedDir): void
    {
        $baseTemp = realpath($this->getTempDir());
        $realExtracted = realpath($extractedDir);
        if ($baseTemp && $realExtracted && str_starts_with($realExtracted, $baseTemp)) {
            $this->removeDir($realExtracted);
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($dir);
    }
}
