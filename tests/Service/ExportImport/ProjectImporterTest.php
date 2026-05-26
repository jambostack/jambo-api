<?php

namespace App\Tests\Service\ExportImport;

use App\Dto\ImportOptions;
use App\Entity\Project;
use App\Service\ExportImport\Import\ContentImportHandler;
use App\Service\ExportImport\Import\MediaImportHandler;
use App\Service\ExportImport\Import\SettingsImportHandler;
use App\Service\ExportImport\Import\StructureImportHandler;
use App\Service\ExportImport\ProjectImporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class ProjectImporterTest extends TestCase
{
    public function testPreviewConflictsAggregatesFromAllHandlers(): void
    {
        $projectDir = realpath(__DIR__ . '/../../../');
        $importer = new ProjectImporter(
            new StructureImportHandler(),
            new ContentImportHandler(),
            new MediaImportHandler($projectDir),
            new SettingsImportHandler(),
            $projectDir,
        );

        $project = new Project();
        $project->uuid = Uuid::v4();
        $project->name = 'Test';

        $collection = new \App\Entity\Collection();
        $collection->slug = 'articles';
        $project->collections->add($collection);

        $tempDir = sys_get_temp_dir() . '/import-test-' . uniqid();
        mkdir($tempDir);

        $structureData = ['collections' => [['name' => 'Existing', 'slug' => 'articles', 'fields' => []]]];
        $contentData = ['entries' => []];
        file_put_contents($tempDir . '/manifest.json', json_encode(['version' => '1.0', 'included' => ['structure', 'content']]));
        file_put_contents($tempDir . '/structure.json', json_encode($structureData));
        file_put_contents($tempDir . '/content.json', json_encode($contentData));

        try {
            $conflicts = $importer->previewConflicts($project, $tempDir);
            $this->assertGreaterThan(0, count($conflicts));
        } finally {
            @unlink($tempDir . '/manifest.json');
            @unlink($tempDir . '/structure.json');
            @unlink($tempDir . '/content.json');
            rmdir($tempDir);
        }
    }

    public function testExtractZipExtractsToTempDir(): void
    {
        $projectDir = realpath(__DIR__ . '/../../../');
        $importer = new ProjectImporter(
            new StructureImportHandler(),
            new ContentImportHandler(),
            new MediaImportHandler($projectDir),
            new SettingsImportHandler(),
            $projectDir,
        );

        // Create a simple valid ZIP
        $zipPath = sys_get_temp_dir() . '/test-import-' . uniqid() . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('manifest.json', json_encode(['version' => '1.0', 'included' => []]));
        $zip->close();

        try {
            $extracted = $importer->extractZip($zipPath);
            $this->assertDirectoryExists($extracted);
            $this->assertFileExists($extracted . '/manifest.json');

            $importer->cleanup($extracted);
            $this->assertDirectoryDoesNotExist($extracted);
        } finally {
            @unlink($zipPath);
        }
    }

    public function testValidateManifestThrowsOnMissingVersion(): void
    {
        $projectDir = realpath(__DIR__ . '/../../../');
        $importer = new ProjectImporter(
            new StructureImportHandler(),
            new ContentImportHandler(),
            new MediaImportHandler($projectDir),
            new SettingsImportHandler(),
            $projectDir,
        );

        $tempDir = sys_get_temp_dir() . '/import-test-' . uniqid();
        mkdir($tempDir);
        file_put_contents($tempDir . '/manifest.json', json_encode(['included' => []]));

        try {
            $this->expectException(\RuntimeException::class);
            $importer->validateManifest($tempDir);
        } finally {
            unlink($tempDir . '/manifest.json');
            rmdir($tempDir);
        }
    }
}
