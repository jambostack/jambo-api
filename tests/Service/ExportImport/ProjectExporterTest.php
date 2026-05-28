<?php

namespace App\Tests\Service\ExportImport;

use App\Dto\ExportOptions;
use App\Entity\Project;
use App\Repository\EndUserFieldRepository;
use App\Repository\EndUserRepository;
use App\Service\ExportImport\Export\ContentExportHandler;
use App\Service\ExportImport\Export\EndUserExportHandler;
use App\Service\ExportImport\Export\MediaExportHandler;
use App\Service\ExportImport\Export\SettingsExportHandler;
use App\Service\ExportImport\Export\StructureExportHandler;
use App\Service\ExportImport\ProjectExporter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class ProjectExporterTest extends TestCase
{
    public function testExportCreatesZipFileWithManifest(): void
    {
        $projectDir = realpath(__DIR__ . '/../../../');

        // MediaExportHandler constructor signature: (EntityManagerInterface $em, string $projectDir)
        $em = $this->createMock(EntityManagerInterface::class);

        $exporter = new ProjectExporter(
            new StructureExportHandler($this->createMock(EndUserFieldRepository::class)),
            new ContentExportHandler(),
            new MediaExportHandler($em, $projectDir),
            new SettingsExportHandler(),
            new EndUserExportHandler($this->createMock(EndUserRepository::class)),
            $projectDir,
        );

        $project = new Project();
        $project->name = 'Export Test';
        $project->uuid = Uuid::v4();
        $project->defaultLocale = 'en';
        $project->locales = ['en'];

        $options = new ExportOptions();
        $options->structure = true;
        $options->content = false;
        $options->media = false;
        $options->settings = false;

        $zipPath = sys_get_temp_dir() . '/export-test-' . uniqid() . '.zip';

        try {
            $exporter->export($project, $options, $zipPath);

            $this->assertFileExists($zipPath);

            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($zipPath));
            $this->assertTrue($zip->locateName('manifest.json') !== false);
            $this->assertTrue($zip->locateName('structure.json') !== false);

            $manifest = json_decode($zip->getFromName('manifest.json'), true);
            $this->assertContains('structure', $manifest['included']);
            $this->assertNotContains('content', $manifest['included']);
            $this->assertSame('1.0', $manifest['version']);

            $zip->close();
        } finally {
            @unlink($zipPath);
        }
    }

    public function testStreamExportReturnsPath(): void
    {
        $projectDir = realpath(__DIR__ . '/../../../');
        $em = $this->createMock(EntityManagerInterface::class);

        $exporter = new ProjectExporter(
            new StructureExportHandler($this->createMock(EndUserFieldRepository::class)),
            new ContentExportHandler(),
            new MediaExportHandler($em, $projectDir),
            new SettingsExportHandler(),
            new EndUserExportHandler($this->createMock(EndUserRepository::class)),
            $projectDir,
        );

        $project = new Project();
        $project->name = 'Test';
        $project->uuid = Uuid::v4();
        $project->defaultLocale = 'en';
        $project->locales = ['en'];

        $options = new ExportOptions();
        $options->structure = true;

        $zipPath = $exporter->streamExport($project, $options);
        $this->assertFileExists($zipPath);
        @unlink($zipPath);
    }
}
