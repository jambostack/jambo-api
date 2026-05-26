<?php

namespace App\Service\ExportImport;

use App\Dto\ExportOptions;
use App\Entity\Project;
use App\Service\ExportImport\Export\ContentExportHandler;
use App\Service\ExportImport\Export\MediaExportHandler;
use App\Service\ExportImport\Export\SettingsExportHandler;
use App\Service\ExportImport\Export\StructureExportHandler;

class ProjectExporter
{
    /** @var array<string, ExportHandlerInterface> */
    private array $handlers;

    public function __construct(
        private StructureExportHandler $structureHandler,
        private ContentExportHandler $contentHandler,
        private MediaExportHandler $mediaHandler,
        private SettingsExportHandler $settingsHandler,
        private string $projectDir,
    ) {
        $this->handlers = [
            $structureHandler::getOptionKey() => $structureHandler,
            $contentHandler::getOptionKey()   => $contentHandler,
            $mediaHandler::getOptionKey()     => $mediaHandler,
            $settingsHandler::getOptionKey()  => $settingsHandler,
        ];
    }

    /**
     * Export a project to a ZIP file.
     *
     * @param Project $project The project to export
     * @param ExportOptions $options Which sections to include
     * @param string $outputPath Absolute path for the output ZIP file
     */
    private function getTempDir(): string
    {
        $dir = $this->projectDir . '/var/tmp';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir;
    }

    public function export(Project $project, ExportOptions $options, string $outputPath): void
    {
        $tempDir = $this->getTempDir() . '/jambo-export-' . uniqid();

        try {
            if (!mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
                throw new \RuntimeException("Cannot create temp dir: $tempDir");
            }

            $included = [];
            $manifestSections = [];

            foreach ($options->getEnabledOptions() as $key) {
                if (!isset($this->handlers[$key])) {
                    continue;
                }

                $result = $this->handlers[$key]->export($project, $tempDir);
                $included[] = $key;
                $manifestSections[$key] = $result['manifest'];
            }

            $manifest = [
                'version'     => '1.0',
                'exported_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'project'     => [
                    'name'           => $project->name,
                    'uuid'           => $project->uuid?->toString(),
                    'default_locale' => $project->defaultLocale,
                    'locales'        => $project->locales,
                ],
                'included' => $included,
                'sections' => $manifestSections,
            ];

            $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            file_put_contents($tempDir . '/manifest.json', $manifestJson);

            $this->createZip($tempDir, $outputPath);
        } finally {
            try {
                $this->removeDir($tempDir);
            } catch (\Throwable) {
                // Silently ignore cleanup errors — the ZIP is already created
            }
        }
    }

    /**
     * Export a project and return the path to the generated ZIP file.
     * Useful for streaming responses (BinaryFileResponse).
     *
     * @return string Absolute path to the ZIP file
     */
    public function streamExport(Project $project, ExportOptions $options): string
    {
        $zipPath = $this->getTempDir() . '/jambo-export-' . uniqid() . '.zip';
        $this->export($project, $options, $zipPath);
        return $zipPath;
    }

    /**
     * Recursively remove a directory and its contents.
     */
    public function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }

    /**
     * Create a ZIP archive from all files in a directory.
     */
    private function createZip(string $sourceDir, string $outputPath): void
    {
        $zip = new \ZipArchive();

        if ($zip->open($outputPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot create ZIP file: $outputPath");
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            $localName = substr($file->getPathname(), strlen($sourceDir) + 1);
            $localName = str_replace('\\', '/', $localName);

            if ($file->isDir()) {
                $zip->addEmptyDir($localName);
            } else {
                $zip->addFile($file->getPathname(), $localName);
            }
        }

        $zip->close();
    }
}
