<?php

namespace App\Tests\Service\ExportImport\Import;

use App\Dto\ImportOptions;
use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\Service\ExportImport\Import\ContentImportHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class ContentImportHandlerTest extends TestCase
{
    public function testGetOptionKey(): void
    {
        $this->assertSame('content', ContentImportHandler::getOptionKey());
    }

    public function testImportCreatesContentEntries(): void
    {
        $handler = new ContentImportHandler();

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

        $project->collections->add($collection);

        $tempDir = sys_get_temp_dir() . '/import-test-' . uniqid();
        mkdir($tempDir);

        $entryUuid = Uuid::v4()->toString();
        $contentData = [
            'entries' => [
                [
                    'uuid'            => $entryUuid,
                    'locale'          => 'en',
                    'status'          => 'published',
                    'collection_slug' => 'articles',
                    'created_at'      => null,
                    'updated_at'      => null,
                    'field_values'    => [
                        ['field_slug' => 'title', 'field_type' => 'text', 'value' => 'Hello'],
                    ],
                ],
            ],
        ];
        file_put_contents($tempDir . '/content.json', json_encode($contentData));

        try {
            $uuidMap = [];
            $options = new ImportOptions();
            $handler->import($project, $tempDir, $options, $uuidMap);

            $this->assertCount(1, $collection->contentEntries);
            $entry = $collection->contentEntries->first();
            $this->assertSame('published', $entry->status);
            $this->assertCount(1, $entry->fieldValues);
            $this->assertSame('Hello', $entry->fieldValues->first()->textValue);
            $this->assertArrayHasKey($entryUuid, $uuidMap);
        } finally {
            unlink($tempDir . '/content.json');
            rmdir($tempDir);
        }
    }

    public function testPreviewConflictsDetectsUuidCollision(): void
    {
        $handler = new ContentImportHandler();

        $project = new Project();
        $project->uuid = Uuid::v4();

        $collection = new Collection();
        $collection->slug = 'articles';
        $collection->project = $project;
        $project->collections->add($collection);

        $existingEntryUuid = Uuid::v4();
        $existingEntry = new \App\Entity\ContentEntry();
        $existingEntry->uuid = $existingEntryUuid;
        $existingEntry->collection = $collection;
        $existingEntry->project = $project;
        $collection->contentEntries->add($existingEntry);

        $tempDir = sys_get_temp_dir() . '/import-test-' . uniqid();
        mkdir($tempDir);

        $contentData = [
            'entries' => [
                [
                    'uuid'            => $existingEntryUuid->toString(),
                    'locale'          => 'en',
                    'status'          => 'draft',
                    'collection_slug' => 'articles',
                    'field_values'    => [],
                ],
            ],
        ];
        file_put_contents($tempDir . '/content.json', json_encode($contentData));

        try {
            $conflicts = $handler->previewConflicts($project, $tempDir);
            $this->assertCount(1, $conflicts);
            $this->assertSame('content_entry', $conflicts[0]->entityType);
        } finally {
            unlink($tempDir . '/content.json');
            rmdir($tempDir);
        }
    }
}
