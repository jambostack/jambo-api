<?php

namespace App\Service\ExportImport;

use App\Dto\ConflictItem;
use App\Dto\ImportOptions;
use App\Entity\Project;

class ProjectImporter
{
    /** @var array<string, ImportHandlerInterface> */
    private array $handlers = [];

    public function __construct(
        Import\StructureImportHandler $structureHandler,
        Import\ContentImportHandler $contentHandler,
        Import\MediaImportHandler $mediaHandler,
        Import\SettingsImportHandler $settingsHandler,
        private string $projectDir,
    ) {
        $this->handlers['structure'] = $structureHandler;
        $this->handlers['content'] = $contentHandler;
        $this->handlers['media'] = $mediaHandler;
        $this->handlers['settings'] = $settingsHandler;
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

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Cannot open ZIP file: ' . $zipPath);
        }
        $zip->extractTo($tempDir);
        $zip->close();

        return $tempDir;
    }

    public function validateManifest(string $extractedDir): array
    {
        $manifestPath = $extractedDir . '/manifest.json';
        if (!file_exists($manifestPath)) {
            throw new \RuntimeException('Invalid export package: manifest.json not found');
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (!$manifest || !isset($manifest['version'])) {
            throw new \RuntimeException('Invalid export package: malformed manifest.json');
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

        // Import in order: structure -> media -> content -> settings
        $order = ['structure', 'media', 'content', 'settings'];

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

    public function cleanup(string $extractedDir): void
    {
        $this->removeDir($extractedDir);
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
