<?php

namespace App\Service\ExportImport;

use App\Dto\ExportOptions;
use App\Entity\Project;
use App\Service\ExportImport\Export\ContentExportHandler;
use App\Service\ExportImport\Export\EndUserExportHandler;
use App\Service\ExportImport\Export\MediaExportHandler;
use App\Service\ExportImport\Export\SettingsExportHandler;
use App\Service\ExportImport\Export\StructureExportHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

class ProjectExporter
{
    public const MAX_EXPORT_FILES = 5000;
    public const MAX_EXPORT_BYTES = 262144000; // 250 MB
    public const MIN_FREE_DISK_BYTES = 524288000; // 500 MB

    /** @var array<string, ExportHandlerInterface> */
    private array $handlers;

    public function __construct(
        private StructureExportHandler $structureHandler,
        private ContentExportHandler $contentHandler,
        private MediaExportHandler $mediaHandler,
        private SettingsExportHandler $settingsHandler,
        private EndUserExportHandler $endUserHandler,
        private string $projectDir,
        private ?TranslatorInterface $translator = null,
    ) {
        $this->handlers = [
            $structureHandler::getOptionKey() => $structureHandler,
            $contentHandler::getOptionKey()   => $contentHandler,
            $mediaHandler::getOptionKey()     => $mediaHandler,
            $settingsHandler::getOptionKey()  => $settingsHandler,
            $endUserHandler::getOptionKey()   => $endUserHandler,
        ];
    }

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters, 'messages');
        }
        return $default ?? $id;
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
            mkdir($dir, 0700, true);
        }
        return $dir;
    }

    public function cleanStaleTempDirs(): void
    {
        $base = $this->getTempDir();
        $items = @scandir($base);
        if ($items === false) {
            return;
        }

        $now = time();
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            if (str_starts_with($item, 'jambo-export-') || str_starts_with($item, 'jambo-import-')) {
                $path = $base . '/' . $item;
                $mtime = @filemtime($path);
                // Clean temp files/directories older than 1 hour (3600s)
                if ($mtime !== false && ($now - $mtime) > 3600) {
                    if (is_dir($path)) {
                        try {
                            $this->removeDir($path);
                        } catch (\Throwable) {}
                    } else {
                        @unlink($path);
                    }
                }
            }
        }
    }

    public function export(Project $project, ExportOptions $options, string $outputPath): void
    {
        $this->cleanStaleTempDirs();

        $free = @disk_free_space($this->getTempDir());
        if ($free !== false && $free >= 0 && $free < self::MIN_FREE_DISK_BYTES) {
            throw new \RuntimeException($this->trans('export.insufficient_disk_space', [], 'Insufficient server disk space to generate export archive.'));
        }

        $token = bin2hex(random_bytes(16));
        $tempDir = $this->getTempDir() . '/jambo-export-' . $token;

        try {
            if (!mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
                throw new \RuntimeException($this->trans('export.temp_dir_create_failed', ['%dir%' => $tempDir], "Cannot create temp dir: $tempDir"));
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
        $token = bin2hex(random_bytes(16));
        $zipPath = $this->getTempDir() . '/jambo-export-' . $token . '.zip';
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
     * Create a ZIP archive from all files in a directory with strict safety limits.
     */
    private function createZip(string $sourceDir, string $outputPath): void
    {
        $zip = new \ZipArchive();

        if ($zip->open($outputPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException($this->trans('export.zip_create_failed', ['%path%' => $outputPath], "Cannot create ZIP file: $outputPath"));
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        $fileCount = 0;
        $totalBytes = 0;

        foreach ($iterator as $file) {
            $localName = substr($file->getPathname(), strlen($sourceDir) + 1);
            $localName = str_replace('\\', '/', $localName);

            // Path traversal guard
            if (str_contains($localName, '..') || str_starts_with($localName, '/')) {
                continue;
            }

            $fileCount++;
            if ($fileCount > self::MAX_EXPORT_FILES) {
                $zip->close();
                @unlink($outputPath);
                throw new \RuntimeException($this->trans('export.archive.too_many_files', ['%max%' => self::MAX_EXPORT_FILES], sprintf('Too many files to archive in export (max %d).', self::MAX_EXPORT_FILES)));
            }

            if ($file->isDir()) {
                $zip->addEmptyDir($localName);
            } else {
                $fileSize = $file->getSize();
                $totalBytes += $fileSize;
                if ($totalBytes > self::MAX_EXPORT_BYTES) {
                    $zip->close();
                    @unlink($outputPath);
                    throw new \RuntimeException($this->trans('export.archive.size_exceeded', [], 'Total uncompressed export archive size exceeds limit.'));
                }
                $zip->addFile($file->getPathname(), $localName);
            }
        }

        $zip->close();
    }
}
