<?php

namespace App\Tests\Service\ExportImport\Import;

use App\Dto\ImportOptions;
use App\Entity\Project;
use App\Service\ExportImport\Import\StructureImportHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class StructureImportHandlerTest extends TestCase
{
    public function testGetOptionKey(): void
    {
        $this->assertSame('structure', StructureImportHandler::getOptionKey());
    }

    public function testImportCreatesCollectionsAndFields(): void
    {
        $handler = new StructureImportHandler();

        $project = new Project();
        $project->name = 'Target Project';
        $project->uuid = Uuid::v4();
        $project->defaultLocale = 'en';
        $project->locales = ['en'];

        $tempDir = sys_get_temp_dir() . '/import-test-' . uniqid();
        mkdir($tempDir);

        $structureData = [
            'collections' => [
                [
                    'name'         => 'Articles',
                    'slug'         => 'articles',
                    'description'  => 'Blog articles',
                    'is_singleton' => false,
                    'order'        => 0,
                    'fields'       => [
                        [
                            'name'        => 'Title',
                            'slug'        => 'title',
                            'type'        => 'text',
                            'options'     => ['maxLength' => 255],
                            'order'       => 0,
                            'is_required' => true,
                        ],
                    ],
                ],
            ],
        ];
        file_put_contents($tempDir . '/structure.json', json_encode($structureData));

        try {
            $uuidMap = [];
            $options = new ImportOptions();
            $handler->import($project, $tempDir, $options, $uuidMap);

            $this->assertCount(1, $project->collections);
            $collection = $project->collections->first();
            $this->assertSame('Articles', $collection->name);
            $this->assertSame('articles', $collection->slug);

            $this->assertCount(1, $collection->fields);
            $field = $collection->fields->first();
            $this->assertSame('Title', $field->name);
            $this->assertSame('text', $field->type);
        } finally {
            unlink($tempDir . '/structure.json');
            rmdir($tempDir);
        }
    }

    public function testPreviewConflictsDetectsSlugCollision(): void
    {
        $handler = new StructureImportHandler();

        $project = new Project();
        $project->uuid = Uuid::v4();

        $existing = new \App\Entity\Collection();
        $existing->name = 'Articles';
        $existing->slug = 'articles';
        $project->collections->add($existing);

        $tempDir = sys_get_temp_dir() . '/import-test-' . uniqid();
        mkdir($tempDir);

        $structureData = [
            'collections' => [
                ['name' => 'Articles', 'slug' => 'articles', 'fields' => []],
                ['name' => 'Pages', 'slug' => 'pages', 'fields' => []],
            ],
        ];
        file_put_contents($tempDir . '/structure.json', json_encode($structureData));

        try {
            $conflicts = $handler->previewConflicts($project, $tempDir);
            $this->assertCount(1, $conflicts);
            $this->assertSame('collection', $conflicts[0]->entityType);
            $this->assertSame('articles', $conflicts[0]->entityName);
        } finally {
            unlink($tempDir . '/structure.json');
            rmdir($tempDir);
        }
    }
}
