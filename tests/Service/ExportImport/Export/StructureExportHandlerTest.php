<?php

namespace App\Tests\Service\ExportImport\Export;

use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\Repository\EndUserFieldRepository;
use App\Service\ExportImport\Export\StructureExportHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class StructureExportHandlerTest extends TestCase
{
    private function createHandler(): StructureExportHandler
    {
        $repo = $this->createMock(EndUserFieldRepository::class);
        $repo->method('findByProject')->willReturn([]);

        return new StructureExportHandler($repo);
    }

    public function testGetOptionKey(): void
    {
        $this->assertSame('structure', StructureExportHandler::getOptionKey());
    }

    public function testExportWritesStructureJson(): void
    {
        $handler = $this->createHandler();

        $project = new Project();
        $project->name = 'Test Project';
        $project->uuid = Uuid::v4();
        $project->defaultLocale = 'en';
        $project->locales = ['en', 'fr'];

        $collection = new Collection();
        $collection->name = 'Articles';
        $collection->slug = 'articles';
        $collection->description = 'Blog articles';
        $collection->isSingleton = false;
        $collection->order = 0;
        $collection->project = $project;

        $field = new Field();
        $field->name = 'Title';
        $field->slug = 'title';
        $field->type = 'text';
        $field->options = ['maxLength' => 255];
        $field->order = 0;
        $field->isRequired = true;
        $field->collection = $collection;

        $collection->fields->add($field);
        $project->collections->add($collection);

        $tempDir = sys_get_temp_dir() . '/export-test-' . uniqid();
        mkdir($tempDir);

        try {
            $result = $handler->export($project, $tempDir);

            $this->assertFileExists($tempDir . '/structure.json');
            $data = json_decode(file_get_contents($tempDir . '/structure.json'), true);

            $this->assertCount(1, $data['collections']);
            $this->assertSame('articles', $data['collections'][0]['slug']);
            $this->assertCount(1, $data['collections'][0]['fields']);
            $this->assertSame('title', $data['collections'][0]['fields'][0]['slug']);
            $this->assertSame(['maxLength' => 255], $data['collections'][0]['fields'][0]['options']);

            $this->assertArrayHasKey('file', $result['manifest']);
            $this->assertSame('structure.json', $result['manifest']['file']);
            $this->assertSame(1, $result['manifest']['entityCount']);
        } finally {
            unlink($tempDir . '/structure.json');
            rmdir($tempDir);
        }
    }
}
