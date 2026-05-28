<?php

namespace App\Tests\Service\ExportImport;

use App\Dto\ExportOptions;
use App\Dto\ImportOptions;
use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\ContentFieldValue;
use App\Entity\Field;
use App\Entity\Project;
use App\Repository\EndUserFieldRepository;
use App\Repository\EndUserRepository;
use App\Service\ExportImport\Export\ContentExportHandler;
use App\Service\ExportImport\Export\EndUserExportHandler;
use App\Service\ExportImport\Export\MediaExportHandler;
use App\Service\ExportImport\Export\SettingsExportHandler;
use App\Service\ExportImport\Export\StructureExportHandler;
use App\Service\ExportImport\Import\ContentImportHandler;
use App\Service\ExportImport\Import\EndUserImportHandler;
use App\Service\ExportImport\Import\MediaImportHandler;
use App\Service\ExportImport\Import\SettingsImportHandler;
use App\Service\ExportImport\Import\StructureImportHandler;
use App\Service\ExportImport\ProjectExporter;
use App\Service\ExportImport\ProjectImporter;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class ExportImportRoundtripTest extends TestCase
{
    public function testFullRoundtripStructureAndContent(): void
    {
        $projectDir = realpath(__DIR__ . '/../../..');

        // --- Setup: create a source project with structure + content ---
        $source = new Project();
        $source->name = 'Roundtrip Source';
        $source->uuid = Uuid::v4();
        $source->defaultLocale = 'fr';
        $source->locales = ['fr', 'en'];

        $collection = new Collection();
        $collection->name = 'Articles';
        $collection->slug = 'articles';
        $collection->project = $source;
        $collection->fields = new ArrayCollection();
        $collection->contentEntries = new ArrayCollection();
        $source->collections->add($collection);

        $field = new Field();
        $field->name = 'Title';
        $field->slug = 'title';
        $field->type = 'text';
        $field->collection = $collection;
        $collection->fields->add($field);

        $entry = new ContentEntry();
        $entry->uuid = Uuid::v4();
        $entry->locale = 'fr';
        $entry->status = 'published';
        $entry->collection = $collection;
        $entry->project = $source;
        $entry->fieldValues = new ArrayCollection();

        $value = new ContentFieldValue();
        $value->field = $field;
        $value->fieldType = 'text';
        $value->textValue = 'Bonjour le monde';
        $value->contentEntry = $entry;
        $entry->fieldValues->add($value);

        $collection->contentEntries->add($entry);

        // --- Export ---
        $em = $this->createStub(EntityManagerInterface::class);
        $exporter = new ProjectExporter(
            new StructureExportHandler($this->createMock(EndUserFieldRepository::class)),
            new ContentExportHandler(),
            new MediaExportHandler($em, $projectDir),
            new SettingsExportHandler(),
            new EndUserExportHandler($this->createMock(EndUserRepository::class)),
            $projectDir,
        );

        $exportOptions = new ExportOptions();
        $exportOptions->structure = true;
        $exportOptions->content = true;
        $exportOptions->settings = true;

        $zipPath = sys_get_temp_dir() . '/roundtrip-' . uniqid() . '.zip';
        $exporter->export($source, $exportOptions, $zipPath);

        $this->assertFileExists($zipPath);

        // --- Import into a fresh target ---
        $importer = new ProjectImporter(
            new StructureImportHandler($this->createMock(EndUserFieldRepository::class)),
            new ContentImportHandler(),
            new MediaImportHandler($projectDir),
            new SettingsImportHandler(),
            new EndUserImportHandler($this->createMock(EndUserRepository::class)),
            $projectDir,
        );

        $target = new Project();
        $target->name = 'Roundtrip Target';
        $target->uuid = Uuid::v4();
        $target->defaultLocale = 'en';
        $target->locales = ['en'];

        $extractedDir = $importer->extractZip($zipPath);
        try {
            $importOptions = new ImportOptions();
            $importOptions->strategy = 'new_uuids';
            $importer->import($target, $extractedDir, $importOptions);

            // --- Assertions ---
            // Structure
            $this->assertCount(1, $target->collections);
            $importedCollection = $target->collections->first();
            $this->assertSame('articles', $importedCollection->slug);
            $this->assertCount(1, $importedCollection->fields);
            $this->assertSame('title', $importedCollection->fields->first()->slug);
            $this->assertSame('text', $importedCollection->fields->first()->type);

            // Content
            $this->assertCount(1, $importedCollection->contentEntries);
            $importedEntry = $importedCollection->contentEntries->first();
            $this->assertSame('published', $importedEntry->status);
            $this->assertSame('fr', $importedEntry->locale);
            $this->assertCount(1, $importedEntry->fieldValues);
            $this->assertSame('Bonjour le monde', $importedEntry->fieldValues->first()->textValue);
            $this->assertSame('text', $importedEntry->fieldValues->first()->fieldType);

            // Settings
            $this->assertSame('fr', $target->defaultLocale);
            $this->assertSame(['fr', 'en'], $target->locales);

            // UUIDs should differ with new_uuids strategy
            $this->assertNotSame(
                $entry->uuid->toString(),
                $importedEntry->uuid->toString(),
            );
        } finally {
            $importer->cleanup($extractedDir);
            @unlink($zipPath);
        }
    }

    public function testExportThenImportWithSkipStrategyPreservesStructure(): void
    {
        $projectDir = realpath(__DIR__ . '/../../..');

        // Create source with one collection
        $source = new Project();
        $source->name = 'Skip Test Source';
        $source->uuid = Uuid::v4();
        $source->defaultLocale = 'en';
        $source->locales = ['en'];

        $col = new Collection();
        $col->name = 'Pages';
        $col->slug = 'pages';
        $col->project = $source;
        $col->fields = new ArrayCollection();
        $col->contentEntries = new ArrayCollection();
        $source->collections->add($col);

        // Export
        $em = $this->createStub(EntityManagerInterface::class);
        $exporter = new ProjectExporter(
            new StructureExportHandler($this->createMock(EndUserFieldRepository::class)),
            new ContentExportHandler(),
            new MediaExportHandler($em, $projectDir),
            new SettingsExportHandler(),
            new EndUserExportHandler($this->createMock(EndUserRepository::class)),
            $projectDir,
        );

        $exportOptions = new ExportOptions();
        $exportOptions->structure = true;

        $zipPath = sys_get_temp_dir() . '/roundtrip-skip-' . uniqid() . '.zip';
        $exporter->export($source, $exportOptions, $zipPath);

        // Import into a target that already has a 'pages' collection
        $target = new Project();
        $target->name = 'Skip Test Target';
        $target->uuid = Uuid::v4();
        $target->defaultLocale = 'en';
        $target->locales = ['en'];

        $existingCol = new Collection();
        $existingCol->name = 'Existing Pages';
        $existingCol->slug = 'pages';
        $existingCol->fields = new ArrayCollection();
        $existingCol->contentEntries = new ArrayCollection();
        $target->collections->add($existingCol);

        $importer = new ProjectImporter(
            new StructureImportHandler($this->createMock(EndUserFieldRepository::class)),
            new ContentImportHandler(),
            new MediaImportHandler($projectDir),
            new SettingsImportHandler(),
            new EndUserImportHandler($this->createMock(EndUserRepository::class)),
            $projectDir,
        );

        $extractedDir = $importer->extractZip($zipPath);
        try {
            $importOptions = new ImportOptions();
            $importOptions->strategy = 'skip';
            $importer->import($target, $extractedDir, $importOptions);

            // With skip strategy, the existing collection should remain unchanged
            $this->assertCount(1, $target->collections);
            $this->assertSame('Existing Pages', $target->collections->first()->name);
            $this->assertSame(0, $target->collections->first()->fields->count());
        } finally {
            $importer->cleanup($extractedDir);
            @unlink($zipPath);
        }
    }
}
