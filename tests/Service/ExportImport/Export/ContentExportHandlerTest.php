<?php

namespace App\Tests\Service\ExportImport\Export;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\ContentFieldValue;
use App\Entity\Field;
use App\Entity\Project;
use App\Service\ExportImport\Export\ContentExportHandler;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class ContentExportHandlerTest extends TestCase
{
    public function testGetOptionKey(): void
    {
        $this->assertSame('content', ContentExportHandler::getOptionKey());
    }

    public function testExportWritesContentJsonWithEntriesAndValues(): void
    {
        $handler = new ContentExportHandler();

        $project = new Project();
        $project->uuid = Uuid::v4();

        $collection = new Collection();
        $collection->slug = 'articles';
        $collection->project = $project;

        $field = new Field();
        $field->slug = 'title';
        $field->type = 'text';
        $field->collection = $collection;

        $collection->fields->add($field);

        $entry = new ContentEntry();
        $entry->uuid = Uuid::v4();
        $entry->locale = 'en';
        $entry->status = 'published';
        $entry->collection = $collection;
        $entry->project = $project;
        $entry->fieldValues = new ArrayCollection();

        $value = new ContentFieldValue();
        $value->field = $field;
        $value->fieldType = 'text';
        $value->textValue = 'Hello World';
        $value->contentEntry = $entry;
        $entry->fieldValues->add($value);

        $collection->contentEntries->add($entry);

        $project->collections->add($collection);

        $tempDir = sys_get_temp_dir() . '/export-test-' . uniqid();
        mkdir($tempDir);

        try {
            $result = $handler->export($project, $tempDir);

            $this->assertFileExists($tempDir . '/content.json');
            $data = json_decode(file_get_contents($tempDir . '/content.json'), true);

            $this->assertCount(1, $data['entries']);
            $exported = $data['entries'][0];
            $this->assertSame('published', $exported['status']);
            $this->assertSame('en', $exported['locale']);
            $this->assertSame('articles', $exported['collection_slug']);
            $this->assertCount(1, $exported['field_values']);
            $this->assertSame('title', $exported['field_values'][0]['field_slug']);
            $this->assertSame('Hello World', $exported['field_values'][0]['value']);

            $this->assertSame('content.json', $result['manifest']['file']);
            $this->assertSame(1, $result['manifest']['entityCount']);
        } finally {
            unlink($tempDir . '/content.json');
            rmdir($tempDir);
        }
    }
}
