# Export/Import de Projet — Plan d'Implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permettre l'export modulaire d'un projet (structure, contenu, médias, paramètres) en archive ZIP et son import dans une instance nouvelle ou existante avec résolution de conflits.

**Architecture:** Approche modulaire par handlers. Un orchestrateur (`ProjectExporter`/`ProjectImporter`) délègue à des handlers spécialisés (Structure, Content, Media, Settings) via une interface commune. Chaque handler est testable isolément.

**Tech Stack:** PHP 8.4, Symfony 8.0, Doctrine ORM, ext-zip (`\ZipArchive`), PHPUnit 13.1, React 19 + TypeScript + Inertia.js + shadcn/ui + axios

---

### Task 1: Créer les DTOs

**Files:**
- Create: `src/Dto/ExportOptions.php`
- Create: `src/Dto/ImportOptions.php`
- Create: `src/Dto/ConflictItem.php`

- [ ] **Step 1: Créer ExportOptions.php**

```php
<?php

namespace App\Dto;

class ExportOptions
{
    public bool $structure = true;
    public bool $content = false;
    public bool $media = false;
    public bool $settings = false;

    public static function fromRequest(array $data): self
    {
        $options = new self();
        if (isset($data['structure'])) { $options->structure = (bool) $data['structure']; }
        if (isset($data['content'])) { $options->content = (bool) $data['content']; }
        if (isset($data['media'])) { $options->media = (bool) $data['media']; }
        if (isset($data['settings'])) { $options->settings = (bool) $data['settings']; }
        return $options;
    }

    /** @return string[] */
    public function getEnabledOptions(): array
    {
        $enabled = [];
        if ($this->structure) { $enabled[] = 'structure'; }
        if ($this->content) { $enabled[] = 'content'; }
        if ($this->media) { $enabled[] = 'media'; }
        if ($this->settings) { $enabled[] = 'settings'; }
        return $enabled;
    }
}
```

- [ ] **Step 2: Créer ImportOptions.php**

```php
<?php

namespace App\Dto;

class ImportOptions
{
    public string $strategy = 'skip'; // 'overwrite' | 'skip' | 'new_uuids'
    public bool $createNewProject = true;
    public ?string $newProjectName = null;
    public ?string $ownerEmail = null;

    public static function fromRequest(array $data): self
    {
        $options = new self();
        if (isset($data['strategy'])) { $options->strategy = $data['strategy']; }
        if (isset($data['create_new_project'])) { $options->createNewProject = (bool) $data['create_new_project']; }
        if (isset($data['new_project_name'])) { $options->newProjectName = $data['new_project_name']; }
        if (isset($data['owner_email'])) { $options->ownerEmail = $data['owner_email']; }
        return $options;
    }
}
```

- [ ] **Step 3: Créer ConflictItem.php**

```php
<?php

namespace App\Dto;

class ConflictItem
{
    public string $entityType;   // 'collection' | 'content_entry' | 'media'
    public string $entityName;
    public string $entityUuid;
    public string $existingUuid;
    public string $suggestedAction = 'skip'; // 'overwrite' | 'skip' | 'new_uuid'
    public ?string $chosenAction = null;

    public static function create(
        string $entityType,
        string $entityName,
        string $entityUuid,
        string $existingUuid,
    ): self {
        $item = new self();
        $item->entityType = $entityType;
        $item->entityName = $entityName;
        $item->entityUuid = $entityUuid;
        $item->existingUuid = $existingUuid;
        return $item;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'entity_type'     => $this->entityType,
            'entity_name'     => $this->entityName,
            'entity_uuid'     => $this->entityUuid,
            'existing_uuid'   => $this->existingUuid,
            'suggested_action' => $this->suggestedAction,
            'chosen_action'   => $this->chosenAction ?? $this->suggestedAction,
        ];
    }
}
```

- [ ] **Step 4: Vérifier que les fichiers compilent**

Run: `php bin/console cache:clear`
Expected: no errors related to new DTO classes.

---

### Task 2: Créer les interfaces

**Files:**
- Create: `src/Service/ExportImport/ExportHandlerInterface.php`
- Create: `src/Service/ExportImport/ImportHandlerInterface.php`

- [ ] **Step 1: Créer ExportHandlerInterface.php**

```php
<?php

namespace App\Service\ExportImport;

use App\Dto\ConflictItem;
use App\Entity\Project;

interface ExportHandlerInterface
{
    /**
     * Export data for a given project to a temporary directory.
     * @return array{manifest: array{file: string, entityCount: int}, files: string[]}
     */
    public function export(Project $project, string $tempDir): array;

    /** Returns the option key used in manifest.json and export options (e.g. 'structure', 'content') */
    public static function getOptionKey(): string;
}
```

- [ ] **Step 2: Créer ImportHandlerInterface.php**

```php
<?php

namespace App\Service\ExportImport;

use App\Dto\ConflictItem;
use App\Dto\ImportOptions;
use App\Entity\Project;

interface ImportHandlerInterface
{
    /**
     * Import data from an extracted ZIP into the given project.
     * @param array<string, string> $uuidMap Maps old UUIDs to new UUIDs (populated by previous handlers)
     */
    public function import(Project $project, string $extractedDir, ImportOptions $options, array &$uuidMap): void;

    /**
     * Preview conflicts without importing.
     * @return ConflictItem[]
     */
    public function previewConflicts(Project $project, string $extractedDir): array;

    /** Returns the option key matching the export handler (e.g. 'structure', 'content') */
    public static function getOptionKey(): string;
}
```

- [ ] **Step 3: Vérifier la compilation**

Run: `php bin/console cache:clear`
Expected: no errors.

---

### Task 3: Créer StructureExportHandler

**Files:**
- Create: `src/Service/ExportImport/Export/StructureExportHandler.php`
- Create: `tests/Service/ExportImport/Export/StructureExportHandlerTest.php`

- [ ] **Step 1: Écrire le test unitaire**

```php
<?php

namespace App\Tests\Service\ExportImport\Export;

use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\Service\ExportImport\Export\StructureExportHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class StructureExportHandlerTest extends TestCase
{
    public function testGetOptionKey(): void
    {
        $this->assertSame('structure', StructureExportHandler::getOptionKey());
    }

    public function testExportWritesStructureJson(): void
    {
        $handler = new StructureExportHandler();

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
```

- [ ] **Step 2: Lancer le test (doit échouer)**

Run: `php vendor/bin/phpunit tests/Service/ExportImport/Export/StructureExportHandlerTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implémenter StructureExportHandler**

```php
<?php

namespace App\Service\ExportImport\Export;

use App\Entity\Project;
use App\Service\ExportImport\ExportHandlerInterface;

class StructureExportHandler implements ExportHandlerInterface
{
    public static function getOptionKey(): string
    {
        return 'structure';
    }

    public function export(Project $project, string $tempDir): array
    {
        $collections = [];
        foreach ($project->collections as $collection) {
            if (method_exists($collection, 'isDeleted') && $collection->isDeleted()) {
                continue;
            }

            $fields = [];
            foreach ($collection->fields as $field) {
                if (method_exists($field, 'isDeleted') && $field->isDeleted()) {
                    continue;
                }
                $fields[] = [
                    'name'        => $field->name,
                    'slug'        => $field->slug,
                    'type'        => $field->type,
                    'options'     => $field->options,
                    'order'       => $field->order,
                    'is_required' => $field->isRequired,
                ];
            }

            $collections[] = [
                'name'         => $collection->name,
                'slug'         => $collection->slug,
                'description'  => $collection->description,
                'is_singleton' => $collection->isSingleton,
                'order'        => $collection->order,
                'fields'       => $fields,
            ];
        }

        $data = json_encode(['collections' => $collections], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($tempDir . '/structure.json', $data);

        return [
            'manifest' => ['file' => 'structure.json', 'entityCount' => count($collections)],
            'files'    => ['structure.json'],
        ];
    }
}
```

- [ ] **Step 4: Lancer le test (doit réussir)**

Run: `php vendor/bin/phpunit tests/Service/ExportImport/Export/StructureExportHandlerTest.php`
Expected: PASS.

---

### Task 4: Créer StructureImportHandler

**Files:**
- Create: `src/Service/ExportImport/Import/StructureImportHandler.php`
- Create: `tests/Service/ExportImport/Import/StructureImportHandlerTest.php`

- [ ] **Step 1: Écrire le test unitaire**

```php
<?php

namespace App\Tests\Service\ExportImport\Import;

use App\Dto\ImportOptions;
use App\Entity\Project;
use App\Service\ExportImport\Import\StructureImportHandler;
use Doctrine\ORM\EntityManagerInterface;
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
```

- [ ] **Step 2: Lancer le test (doit échouer)**

Run: `php vendor/bin/phpunit tests/Service/ExportImport/Import/StructureImportHandlerTest.php`
Expected: FAIL.

- [ ] **Step 3: Implémenter StructureImportHandler**

```php
<?php

namespace App\Service\ExportImport\Import;

use App\Dto\ConflictItem;
use App\Dto\ImportOptions;
use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\Service\ExportImport\ImportHandlerInterface;

class StructureImportHandler implements ImportHandlerInterface
{
    public static function getOptionKey(): string
    {
        return 'structure';
    }

    public function import(Project $project, string $extractedDir, ImportOptions $options, array &$uuidMap): void
    {
        $path = $extractedDir . '/structure.json';
        if (!file_exists($path)) {
            return;
        }

        $data = json_decode(file_get_contents($path), true);
        if (!isset($data['collections'])) {
            return;
        }

        $existingSlugs = [];
        foreach ($project->collections as $c) {
            $existingSlugs[$c->slug] = $c;
        }

        foreach ($data['collections'] as $colData) {
            if ($options->strategy === 'skip' && isset($existingSlugs[$colData['slug']])) {
                continue;
            }
            if ($options->strategy === 'overwrite' && isset($existingSlugs[$colData['slug']])) {
                $collection = $existingSlugs[$colData['slug']];
                $collection->fields->clear();
            } else {
                $collection = new Collection();
                $collection->project = $project;
                $collection->slug = $colData['slug'];
                $project->collections->add($collection);
            }

            $collection->name = $colData['name'];
            $collection->description = $colData['description'] ?? null;
            $collection->isSingleton = $colData['is_singleton'] ?? false;
            $collection->order = $colData['order'] ?? 0;

            foreach ($colData['fields'] as $i => $fieldData) {
                $field = new Field();
                $field->collection = $collection;
                $field->name = $fieldData['name'];
                $field->slug = $fieldData['slug'];
                $field->type = $fieldData['type'];
                $field->options = $fieldData['options'] ?? null;
                $field->order = $fieldData['order'] ?? $i;
                $field->isRequired = $fieldData['is_required'] ?? false;
                $collection->fields->add($field);
            }
        }
    }

    public function previewConflicts(Project $project, string $extractedDir): array
    {
        $path = $extractedDir . '/structure.json';
        if (!file_exists($path)) {
            return [];
        }

        $data = json_decode(file_get_contents($path), true);
        $conflicts = [];

        $existingSlugs = [];
        foreach ($project->collections as $c) {
            $existingSlugs[$c->slug] = $c;
        }

        foreach ($data['collections'] ?? [] as $colData) {
            if (isset($existingSlugs[$colData['slug']])) {
                $existing = $existingSlugs[$colData['slug']];
                $conflicts[] = ConflictItem::create(
                    'collection',
                    $colData['slug'],
                    '', // no UUID in structure export for new entities
                    $existing->uuid?->toString() ?? $colData['slug'],
                );
            }
        }

        return $conflicts;
    }
}
```

- [ ] **Step 4: Lancer le test (doit réussir)**

Run: `php vendor/bin/phpunit tests/Service/ExportImport/Import/StructureImportHandlerTest.php`
Expected: PASS.

---

### Task 5: Créer ContentExportHandler

**Files:**
- Create: `src/Service/ExportImport/Export/ContentExportHandler.php`
- Create: `tests/Service/ExportImport/Export/ContentExportHandlerTest.php`

- [ ] **Step 1: Écrire le test unitaire**

```php
<?php

namespace App\Tests\Service\ExportImport\Export;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\ContentFieldValue;
use App\Entity\ContentMediaRelation;
use App\Entity\Field;
use App\Entity\Media;
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
```

- [ ] **Step 2: Lancer le test (doit échouer)**

Run: `php vendor/bin/phpunit tests/Service/ExportImport/Export/ContentExportHandlerTest.php`
Expected: FAIL.

- [ ] **Step 3: Implémenter ContentExportHandler**

```php
<?php

namespace App\Service\ExportImport\Export;

use App\Entity\ContentEntry;
use App\Entity\Project;
use App\Service\ExportImport\ExportHandlerInterface;

class ContentExportHandler implements ExportHandlerInterface
{
    public static function getOptionKey(): string
    {
        return 'content';
    }

    public function export(Project $project, string $tempDir): array
    {
        $entries = [];
        $count = 0;

        foreach ($project->collections as $collection) {
            if (method_exists($collection, 'isDeleted') && $collection->isDeleted()) {
                continue;
            }

            foreach ($collection->contentEntries as $entry) {
                if (method_exists($entry, 'isDeleted') && $entry->isDeleted()) {
                    continue;
                }

                $entries[] = $this->serializeEntry($entry, $collection->slug);
                $count++;
            }
        }

        $data = json_encode(['entries' => $entries], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($tempDir . '/content.json', $data);

        return [
            'manifest' => ['file' => 'content.json', 'entityCount' => $count],
            'files'    => ['content.json'],
        ];
    }

    private function serializeEntry(ContentEntry $entry, string $collectionSlug): array
    {
        $fieldValues = [];
        foreach ($entry->fieldValues as $fv) {
            $value = match ($fv->fieldType) {
                'text', 'longtext', 'richtext', 'email', 'slug', 'color', 'password' => $fv->textValue,
                'number' => $fv->numberValue,
                'boolean' => $fv->booleanValue,
                'date' => $fv->dateValue?->format('Y-m-d'),
                'datetime' => $fv->datetimeValue?->format(\DateTimeInterface::ATOM),
                'json', 'enumeration', 'repeater' => $fv->jsonValue,
                'media' => $this->serializeMediaRelations($fv),
                'relation' => $this->serializeEntryRelations($fv),
                default => $fv->textValue ?? $fv->jsonValue,
            };

            $fieldValues[] = [
                'field_slug' => $fv->field?->slug,
                'field_type' => $fv->fieldType,
                'value'      => $value,
            ];
        }

        return [
            'uuid'            => $entry->uuid?->toString(),
            'locale'          => $entry->locale,
            'status'          => $entry->status,
            'collection_slug' => $collectionSlug,
            'created_at'      => $entry->createdAt?->format(\DateTimeInterface::ATOM),
            'updated_at'      => $entry->updatedAt?->format(\DateTimeInterface::ATOM),
            'field_values'    => $fieldValues,
        ];
    }

    private function serializeMediaRelations(\App\Entity\ContentFieldValue $fv): array
    {
        $uuids = [];
        foreach ($fv->mediaRelations as $mr) {
            if ($mr->media?->uuid) {
                $uuids[] = $mr->media->uuid->toString();
            }
        }
        return $uuids;
    }

    private function serializeEntryRelations(\App\Entity\ContentFieldValue $fv): array
    {
        $uuids = [];
        foreach ($fv->valueRelations as $vr) {
            if ($vr->relatedEntry?->uuid) {
                $uuids[] = $vr->relatedEntry->uuid->toString();
            }
        }
        return $uuids;
    }
}
```

- [ ] **Step 4: Lancer le test (doit réussir)**

Run: `php vendor/bin/phpunit tests/Service/ExportImport/Export/ContentExportHandlerTest.php`
Expected: PASS.

---

### Task 6: Créer ContentImportHandler

**Files:**
- Create: `src/Service/ExportImport/Import/ContentImportHandler.php`
- Create: `tests/Service/ExportImport/Import/ContentImportHandlerTest.php`

- [ ] **Step 1: Écrire le test unitaire**

```php
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
```

- [ ] **Step 2: Lancer le test (doit échouer)**

Run: `php vendor/bin/phpunit tests/Service/ExportImport/Import/ContentImportHandlerTest.php`
Expected: FAIL.

- [ ] **Step 3: Implémenter ContentImportHandler**

```php
<?php

namespace App\Service\ExportImport\Import;

use App\Dto\ConflictItem;
use App\Dto\ImportOptions;
use App\Entity\ContentEntry;
use App\Entity\ContentFieldValue;
use App\Entity\ContentMediaRelation;
use App\Entity\ContentRelationFieldRelation;
use App\Entity\Project;
use App\Service\ExportImport\ImportHandlerInterface;
use Symfony\Component\Uid\Uuid;

class ContentImportHandler implements ImportHandlerInterface
{
    /** @var array<string, \App\Entity\ContentEntry> */
    private array $entryIndex = [];

    public static function getOptionKey(): string
    {
        return 'content';
    }

    public function import(Project $project, string $extractedDir, ImportOptions $options, array &$uuidMap): void
    {
        $path = $extractedDir . '/content.json';
        if (!file_exists($path)) {
            return;
        }

        $data = json_decode(file_get_contents($path), true);
        if (!isset($data['entries'])) {
            return;
        }

        // Index collections by slug
        $collectionsBySlug = [];
        foreach ($project->collections as $c) {
            $collectionsBySlug[$c->slug] = $c;
        }

        // Index fields by slug per collection
        $fieldsBySlug = [];
        foreach ($project->collections as $c) {
            foreach ($c->fields as $f) {
                $fieldsBySlug[$c->slug][$f->slug] = $f;
            }
        }

        // Index existing entries by UUID for conflict detection
        $this->entryIndex = [];
        foreach ($project->collections as $c) {
            foreach ($c->contentEntries as $e) {
                if ($e->uuid) {
                    $this->entryIndex[$e->uuid->toString()] = $e;
                }
            }
        }

        foreach ($data['entries'] as $entryData) {
            $collectionSlug = $entryData['collection_slug'];
            if (!isset($collectionsBySlug[$collectionSlug])) {
                continue;
            }
            $collection = $collectionsBySlug[$collectionSlug];

            $oldUuid = $entryData['uuid'] ?? null;
            $newUuid = null;

            // Handle conflicts
            if ($oldUuid && isset($this->entryIndex[$oldUuid])) {
                if ($options->strategy === 'skip') {
                    $uuidMap[$oldUuid] = $oldUuid;
                    continue;
                }
                if ($options->strategy === 'overwrite') {
                    $entry = $this->entryIndex[$oldUuid];
                    $entry->fieldValues->clear();
                    $newUuid = $oldUuid;
                }
            }

            if ($newUuid === null) {
                $entry = new ContentEntry();
                $entry->project = $project;
                $entry->collection = $collection;

                if ($options->strategy === 'new_uuids' || !$oldUuid) {
                    $entry->uuid = Uuid::v4();
                    $newUuid = $entry->uuid->toString();
                } else {
                    $entry->uuid = $oldUuid ? Uuid::fromString($oldUuid) : Uuid::v4();
                    $newUuid = $entry->uuid->toString();
                }
                $collection->contentEntries->add($entry);
            }

            if ($oldUuid) {
                $uuidMap[$oldUuid] = $newUuid;
            }

            $entry->locale = $entryData['locale'] ?? 'en';
            $entry->status = $entryData['status'] ?? 'draft';

            foreach ($entryData['field_values'] as $fvData) {
                $fieldSlug = $fvData['field_slug'];
                $field = $fieldsBySlug[$collectionSlug][$fieldSlug] ?? null;
                if (!$field) {
                    continue;
                }

                $cfv = new ContentFieldValue();
                $cfv->field = $field;
                $cfv->fieldType = $fvData['field_type'];
                $cfv->contentEntry = $entry;

                $this->setValueOnField($cfv, $fvData['field_type'], $fvData['value']);
                $entry->fieldValues->add($cfv);
            }
        }
    }

    private function setValueOnField(ContentFieldValue $cfv, string $type, mixed $value): void
    {
        match ($type) {
            'text', 'longtext', 'richtext', 'email', 'slug', 'color', 'password' => $cfv->textValue = $value,
            'number' => $cfv->numberValue = $value !== null ? (string) $value : null,
            'boolean' => $cfv->booleanValue = (bool) $value,
            'date' => $cfv->dateValue = $value ? new \DateTimeImmutable($value) : null,
            'datetime' => $cfv->datetimeValue = $value ? new \DateTimeImmutable($value) : null,
            'json', 'enumeration', 'repeater' => $cfv->jsonValue = $value,
            'media' => null, // handled in a second pass after media UUIDs are resolved
            'relation' => null, // handled in a second pass after entry UUIDs are resolved
            default => $cfv->textValue = is_string($value) ? $value : json_encode($value),
        };
    }

    public function previewConflicts(Project $project, string $extractedDir): array
    {
        $path = $extractedDir . '/content.json';
        if (!file_exists($path)) {
            return [];
        }

        $data = json_decode(file_get_contents($path), true);
        $conflicts = [];

        // Build index of existing entry UUIDs
        $existingUuids = [];
        foreach ($project->collections as $c) {
            foreach ($c->contentEntries as $e) {
                if ($e->uuid) {
                    $existingUuids[$e->uuid->toString()] = $e;
                }
            }
        }

        foreach ($data['entries'] ?? [] as $entryData) {
            $uuid = $entryData['uuid'] ?? null;
            if ($uuid && isset($existingUuids[$uuid])) {
                $existing = $existingUuids[$uuid];
                $conflicts[] = ConflictItem::create(
                    'content_entry',
                    $existing->collection?->slug . '/' . $existing->locale,
                    $uuid,
                    $uuid,
                );
            }
        }

        return $conflicts;
    }
}
```

- [ ] **Step 4: Lancer le test (doit réussir)**

Run: `php vendor/bin/phpunit tests/Service/ExportImport/Import/ContentImportHandlerTest.php`
Expected: PASS.

---

### Task 7: Créer MediaExportHandler

**Files:**
- Create: `src/Service/ExportImport/Export/MediaExportHandler.php`
- Create: `tests/Service/ExportImport/Export/MediaExportHandlerTest.php`

- [ ] **Step 1: Écrire le test unitaire**

```php
<?php

namespace App\Tests\Service\ExportImport\Export;

use App\Entity\AssetMetadata;
use App\Entity\Media;
use App\Entity\Project;
use App\Service\ExportImport\Export\MediaExportHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class MediaExportHandlerTest extends TestCase
{
    public function testGetOptionKey(): void
    {
        $this->assertSame('media', MediaExportHandler::getOptionKey());
    }

    public function testExportWritesMediaMetadata(): void
    {
        $handler = new MediaExportHandler();

        $project = new Project();
        $project->uuid = Uuid::v4();

        $media = new Media();
        $media->uuid = Uuid::v4();
        $media->fileName = 'abc123.jpg';
        $media->originalName = 'photo.jpg';
        $media->mimeType = 'image/jpeg';
        $media->fileSize = 12345;
        $media->alt = 'A photo';
        $media->caption = 'Caption text';
        $media->project = $project;

        $metadata = new AssetMetadata();
        $metadata->width = 800;
        $metadata->height = 600;
        $metadata->media = $media;
        $media->metadata = $metadata;

        // Simuler la collection medias du projet
        $project->addMedia($media);

        $tempDir = sys_get_temp_dir() . '/export-test-' . uniqid();
        mkdir($tempDir);
        mkdir($tempDir . '/media');

        try {
            $result = $handler->export($project, $tempDir);

            $this->assertFileExists($tempDir . '/media.json');
            $data = json_decode(file_get_contents($tempDir . '/media.json'), true);
            $this->assertCount(1, $data['media']);
            $this->assertSame('photo.jpg', $data['media'][0]['original_name']);
            $this->assertSame('abc123.jpg', $data['media'][0]['file_name']);
            $this->assertSame('A photo', $data['media'][0]['alt']);
        } finally {
            unlink($tempDir . '/media.json');
            rmdir($tempDir . '/media');
            rmdir($tempDir);
        }
    }
}
```

- [ ] **Step 2: Lancer le test (doit échouer)**

Run: `php vendor/bin/phpunit tests/Service/ExportImport/Export/MediaExportHandlerTest.php`
Expected: FAIL.

- [ ] **Step 3: Implémenter MediaExportHandler**

```php
<?php

namespace App\Service\ExportImport\Export;

use App\Entity\Project;
use App\Service\ExportImport\ExportHandlerInterface;

class MediaExportHandler implements ExportHandlerInterface
{
    public function __construct(private string $projectDir) {}

    public static function getOptionKey(): string
    {
        return 'media';
    }

    public function export(Project $project, string $tempDir): array
    {
        $mediaDir = $tempDir . '/media';
        if (!is_dir($mediaDir)) {
            mkdir($mediaDir, 0777, true);
        }

        $mediaData = [];
        $count = 0;
        $files = [];

        foreach ($project->getMedia() as $media) {
            if (method_exists($media, 'isDeleted') && $media->isDeleted()) {
                continue;
            }

            // Copy file to temp directory
            $sourcePath = $this->projectDir . '/public/uploads/media/' . $project->uuid?->toString() . '/' . $media->fileName;
            if ($media->fileName && file_exists($sourcePath)) {
                copy($sourcePath, $mediaDir . '/' . $media->fileName);
                $files[] = 'media/' . $media->fileName;
            }

            $mediaData[] = [
                'uuid'          => $media->uuid?->toString(),
                'file_name'     => $media->fileName,
                'original_name' => $media->originalName,
                'mime_type'     => $media->mimeType,
                'file_size'     => $media->fileSize,
                'alt'           => $media->alt,
                'caption'       => $media->caption,
                'width'         => $media->metadata?->width,
                'height'        => $media->metadata?->height,
            ];
            $count++;
        }

        $data = json_encode(['media' => $mediaData], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($tempDir . '/media.json', $data);

        return [
            'manifest' => ['file' => 'media.json', 'entityCount' => $count],
            'files'    => array_merge(['media.json'], $files),
        ];
    }
}
```

- [ ] **Step 4: Lancer le test (doit réussir)**

Run: `php vendor/bin/phpunit tests/Service/ExportImport/Export/MediaExportHandlerTest.php`
Expected: PASS.

---

### Task 8: Créer MediaImportHandler

**Files:**
- Create: `src/Service/ExportImport/Import/MediaImportHandler.php`
- Create: `tests/Service/ExportImport/Import/MediaImportHandlerTest.php`

- [ ] **Step 1: Écrire le test**

```php
<?php

namespace App\Tests\Service\ExportImport\Import;

use App\Dto\ImportOptions;
use App\Entity\Project;
use App\Service\ExportImport\Import\MediaImportHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class MediaImportHandlerTest extends TestCase
{
    public function testGetOptionKey(): void
    {
        $this->assertSame('media', MediaImportHandler::getOptionKey());
    }

    public function testImportCreatesMediaRecords(): void
    {
        $projectDir = sys_get_temp_dir();
        $handler = new MediaImportHandler($projectDir);

        $project = new Project();
        $project->uuid = Uuid::v4();
        $project->name = 'Test';

        $tempDir = sys_get_temp_dir() . '/import-test-' . uniqid();
        mkdir($tempDir);
        mkdir($tempDir . '/media');

        $mediaUuid = Uuid::v4()->toString();

        // Create a dummy media file
        $targetDir = $projectDir . '/public/uploads/media/' . $project->uuid->toString();
        mkdir($targetDir, 0777, true);
        file_put_contents($tempDir . '/media/test.jpg', 'fake-image-content');

        $mediaData = [
            'media' => [
                [
                    'uuid'          => $mediaUuid,
                    'file_name'     => 'test.jpg',
                    'original_name' => 'photo.jpg',
                    'mime_type'     => 'image/jpeg',
                    'file_size'     => 12345,
                    'alt'           => 'Alt text',
                    'caption'       => null,
                    'width'         => 800,
                    'height'        => 600,
                ],
            ],
        ];
        file_put_contents($tempDir . '/media.json', json_encode($mediaData));

        try {
            $uuidMap = [];
            $options = new ImportOptions();
            $handler->import($project, $tempDir, $options, $uuidMap);

            $mediaEntities = $project->getMedia();
            $this->assertCount(1, $mediaEntities);
            $media = $mediaEntities->first();
            $this->assertSame('photo.jpg', $media->originalName);
            $this->assertSame('Alt text', $media->alt);
            $this->assertSame(800, $media->metadata?->width);
            $this->assertArrayHasKey($mediaUuid, $uuidMap);

            // File should be copied
            $this->assertFileExists($targetDir . '/test.jpg');
        } finally {
            unlink($tempDir . '/media/test.jpg');
            unlink($tempDir . '/media.json');
            rmdir($tempDir . '/media');
            rmdir($tempDir);
            @unlink($targetDir . '/test.jpg');
            @rmdir($targetDir);
        }
    }
}
```

- [ ] **Step 2: Lancer le test (doit échouer)**

Run: `php vendor/bin/phpunit tests/Service/ExportImport/Import/MediaImportHandlerTest.php`
Expected: FAIL.

- [ ] **Step 3: Implémenter MediaImportHandler**

```php
<?php

namespace App\Service\ExportImport\Import;

use App\Dto\ConflictItem;
use App\Dto\ImportOptions;
use App\Entity\AssetMetadata;
use App\Entity\Media;
use App\Entity\Project;
use App\Service\ExportImport\ImportHandlerInterface;
use Symfony\Component\Uid\Uuid;

class MediaImportHandler implements ImportHandlerInterface
{
    public function __construct(private string $projectDir) {}

    public static function getOptionKey(): string
    {
        return 'media';
    }

    public function import(Project $project, string $extractedDir, ImportOptions $options, array &$uuidMap): void
    {
        $path = $extractedDir . '/media.json';
        if (!file_exists($path)) {
            return;
        }

        $data = json_decode(file_get_contents($path), true);
        if (!isset($data['media'])) {
            return;
        }

        $existingByUuid = [];
        foreach ($project->getMedia() as $m) {
            if ($m->uuid) {
                $existingByUuid[$m->uuid->toString()] = $m;
            }
        }

        $targetMediaDir = $this->projectDir . '/public/uploads/media/' . $project->uuid?->toString();
        if (!is_dir($targetMediaDir)) {
            mkdir($targetMediaDir, 0777, true);
        }

        foreach ($data['media'] as $mediaData) {
            $oldUuid = $mediaData['uuid'] ?? null;

            if ($oldUuid && isset($existingByUuid[$oldUuid])) {
                if ($options->strategy === 'skip') {
                    $uuidMap[$oldUuid] = $oldUuid;
                    continue;
                }
                if ($options->strategy === 'overwrite') {
                    // Remove old file, create new record
                }
            }

            $media = new Media();
            $media->project = $project;
            $media->uuid = ($options->strategy === 'new_uuids' || !$oldUuid)
                ? Uuid::v4()
                : Uuid::fromString($oldUuid);
            $newUuid = $media->uuid->toString();
            if ($oldUuid) {
                $uuidMap[$oldUuid] = $newUuid;
            }

            $media->fileName = $mediaData['file_name'];
            $media->originalName = $mediaData['original_name'];
            $media->mimeType = $mediaData['mime_type'] ?? null;
            $media->fileSize = $mediaData['file_size'] ?? null;
            $media->alt = $mediaData['alt'] ?? null;
            $media->caption = $mediaData['caption'] ?? null;

            // Copy file from extracted archive
            $sourceFile = $extractedDir . '/media/' . $mediaData['file_name'];
            if (file_exists($sourceFile)) {
                copy($sourceFile, $targetMediaDir . '/' . $mediaData['file_name']);
            }

            // Restore metadata
            if (isset($mediaData['width']) || isset($mediaData['height'])) {
                $metadata = new AssetMetadata();
                $metadata->media = $media;
                $metadata->width = $mediaData['width'] ?? null;
                $metadata->height = $mediaData['height'] ?? null;
                $media->metadata = $metadata;
            }
        }
    }

    public function previewConflicts(Project $project, string $extractedDir): array
    {
        $path = $extractedDir . '/media.json';
        if (!file_exists($path)) {
            return [];
        }

        $data = json_decode(file_get_contents($path), true);
        $conflicts = [];

        $existingByUuid = [];
        foreach ($project->getMedia() as $m) {
            if ($m->uuid) {
                $existingByUuid[$m->uuid->toString()] = $m;
            }
        }

        foreach ($data['media'] ?? [] as $mediaData) {
            $uuid = $mediaData['uuid'] ?? null;
            if ($uuid && isset($existingByUuid[$uuid])) {
                $existing = $existingByUuid[$uuid];
                $conflicts[] = ConflictItem::create(
                    'media',
                    $existing->originalName ?? $existing->fileName ?? '',
                    $uuid,
                    $uuid,
                );
            }
        }

        return $conflicts;
    }
}
```

- [ ] **Step 4: Lancer le test (doit réussir)**

Run: `php vendor/bin/phpunit tests/Service/ExportImport/Import/MediaImportHandlerTest.php`
Expected: PASS.

---

### Task 9: Créer SettingsExportHandler

**Files:**
- Create: `src/Service/ExportImport/Export/SettingsExportHandler.php`
- Create: `tests/Service/ExportImport/Export/SettingsExportHandlerTest.php`

- [ ] **Step 1: Écrire le test**

```php
<?php

namespace App\Tests\Service\ExportImport\Export;

use App\Entity\ApiToken;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\Webhook;
use App\Enum\ProjectMemberStatus;
use App\Service\ExportImport\Export\SettingsExportHandler;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class SettingsExportHandlerTest extends TestCase
{
    public function testGetOptionKey(): void
    {
        $this->assertSame('settings', SettingsExportHandler::getOptionKey());
    }

    public function testExportWritesSettingsJson(): void
    {
        $handler = new SettingsExportHandler();

        $project = new Project();
        $project->uuid = Uuid::v4();
        $project->defaultLocale = 'fr';
        $project->locales = ['fr', 'en', 'de'];
        $project->publicApi = true;

        $member = new ProjectMember();
        $member->email = 'user@test.com';
        $member->status = ProjectMemberStatus::Active;
        $member->role = null;
        $member->project = $project;

        $token = new ApiToken();
        $token->name = 'My API Token';
        $token->abilities = ['read', 'write'];
        $token->project = $project;

        $webhook = new Webhook();
        $webhook->name = 'Notify';
        $webhook->url = 'https://example.com/hook';
        $webhook->events = ['content.created'];
        $webhook->isActive = true;
        $webhook->project = $project;

        // Use reflection to set private properties for collections
        $r = new \ReflectionClass($project);
        $membersProp = $r->getProperty('projectMembers');
        $membersProp->setAccessible(true);
        $membersProp->setValue($project, new ArrayCollection([$member]));

        $tempDir = sys_get_temp_dir() . '/export-test-' . uniqid();
        mkdir($tempDir);

        try {
            $result = $handler->export($project, $tempDir);

            $this->assertFileExists($tempDir . '/settings.json');
            $data = json_decode(file_get_contents($tempDir . '/settings.json'), true);

            $this->assertSame('fr', $data['default_locale']);
            $this->assertSame(['fr', 'en', 'de'], $data['locales']);
            $this->assertCount(1, $data['members']);
            $this->assertSame('user@test.com', $data['members'][0]['email']);
            $this->assertCount(1, $data['api_tokens']);
            $this->assertSame('My API Token', $data['api_tokens'][0]['name']);
            $this->assertCount(1, $data['webhooks']);
            $this->assertSame('Notify', $data['webhooks'][0]['name']);

            $this->assertSame('settings.json', $result['manifest']['file']);
        } finally {
            unlink($tempDir . '/settings.json');
            rmdir($tempDir);
        }
    }
}
```

- [ ] **Step 2: Lancer le test (doit échouer)**

Run: `php vendor/bin/phpunit tests/Service/ExportImport/Export/SettingsExportHandlerTest.php`
Expected: FAIL.

- [ ] **Step 3: Implémenter SettingsExportHandler**

```php
<?php

namespace App\Service\ExportImport\Export;

use App\Entity\Project;
use App\Service\ExportImport\ExportHandlerInterface;

class SettingsExportHandler implements ExportHandlerInterface
{
    public static function getOptionKey(): string
    {
        return 'settings';
    }

    public function export(Project $project, string $tempDir): array
    {
        $members = [];
        foreach ($project->projectMembers as $member) {
            $members[] = [
                'email'     => $member->email,
                'status'    => $member->status->value,
                'role_name' => $member->role?->name,
            ];
        }

        $tokens = [];
        foreach ($project->getApiTokens() as $token) {
            $tokens[] = [
                'name'      => $token->name,
                'abilities' => $token->abilities,
            ];
        }

        $webhooks = [];
        foreach ($project->getWebhooks() as $webhook) {
            $webhooks[] = [
                'name'      => $webhook->name,
                'url'       => $webhook->url,
                'events'    => $webhook->events,
                'is_active' => $webhook->isActive,
            ];
        }

        $data = [
            'default_locale' => $project->defaultLocale,
            'locales'        => $project->locales,
            'public_api'     => $project->publicApi,
            'members'        => $members,
            'api_tokens'     => $tokens,
            'webhooks'       => $webhooks,
        ];

        file_put_contents(
            $tempDir . '/settings.json',
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );

        return [
            'manifest' => ['file' => 'settings.json', 'entityCount' => count($members) + count($tokens) + count($webhooks)],
            'files'    => ['settings.json'],
        ];
    }
}
```

- [ ] **Step 4: Lancer le test (doit réussir)**

Run: `php vendor/bin/phpunit tests/Service/ExportImport/Export/SettingsExportHandlerTest.php`
Expected: PASS.

---

### Task 10: Créer SettingsImportHandler

**Files:**
- Create: `src/Service/ExportImport/Import/SettingsImportHandler.php`
- Create: `tests/Service/ExportImport/Import/SettingsImportHandlerTest.php`

- [ ] **Step 1: Écrire le test**

```php
<?php

namespace App\Tests\Service\ExportImport\Import;

use App\Dto\ImportOptions;
use App\Entity\Project;
use App\Service\ExportImport\Import\SettingsImportHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class SettingsImportHandlerTest extends TestCase
{
    public function testGetOptionKey(): void
    {
        $this->assertSame('settings', SettingsImportHandler::getOptionKey());
    }

    public function testImportRestoresProjectSettings(): void
    {
        $handler = new SettingsImportHandler();

        $project = new Project();
        $project->uuid = Uuid::v4();
        $project->defaultLocale = 'en';
        $project->locales = ['en'];

        $tempDir = sys_get_temp_dir() . '/import-test-' . uniqid();
        mkdir($tempDir);

        $settingsData = [
            'default_locale' => 'fr',
            'locales'        => ['fr', 'en'],
            'public_api'     => true,
            'members'        => [],
            'api_tokens'     => [],
            'webhooks'       => [],
        ];
        file_put_contents($tempDir . '/settings.json', json_encode($settingsData));

        try {
            $uuidMap = [];
            $options = new ImportOptions();
            $handler->import($project, $tempDir, $options, $uuidMap);

            $this->assertSame('fr', $project->defaultLocale);
            $this->assertSame(['fr', 'en'], $project->locales);
            $this->assertTrue($project->publicApi);
        } finally {
            unlink($tempDir . '/settings.json');
            rmdir($tempDir);
        }
    }

    public function testPreviewConflictsAlwaysReturnsEmpty(): void
    {
        $handler = new SettingsImportHandler();
        $project = new Project();
        $project->uuid = Uuid::v4();
        $project->name = 'Test';

        $tempDir = sys_get_temp_dir() . '/import-test-' . uniqid();
        mkdir($tempDir);
        file_put_contents($tempDir . '/settings.json', json_encode(['locales' => ['en']]));

        try {
            $conflicts = $handler->previewConflicts($project, $tempDir);
            $this->assertCount(0, $conflicts);
        } finally {
            unlink($tempDir . '/settings.json');
            rmdir($tempDir);
        }
    }
}
```

- [ ] **Step 2: Lancer le test (doit échouer)**

Run: `php vendor/bin/phpunit tests/Service/ExportImport/Import/SettingsImportHandlerTest.php`
Expected: FAIL.

- [ ] **Step 3: Implémenter SettingsImportHandler**

```php
<?php

namespace App\Service\ExportImport\Import;

use App\Dto\ConflictItem;
use App\Dto\ImportOptions;
use App\Entity\ApiToken;
use App\Entity\Project;
use App\Entity\Webhook;
use App\Service\ExportImport\ImportHandlerInterface;

class SettingsImportHandler implements ImportHandlerInterface
{
    public static function getOptionKey(): string
    {
        return 'settings';
    }

    public function import(Project $project, string $extractedDir, ImportOptions $options, array &$uuidMap): void
    {
        $path = $extractedDir . '/settings.json';
        if (!file_exists($path)) {
            return;
        }

        $data = json_decode(file_get_contents($path), true);
        if (!$data) {
            return;
        }

        if (isset($data['default_locale'])) {
            $project->defaultLocale = $data['default_locale'];
        }
        if (isset($data['locales'])) {
            $project->locales = $data['locales'];
        }
        if (isset($data['public_api'])) {
            $project->publicApi = $data['public_api'];
        }

        // API tokens: regenerate new tokens (hashes can't be exported)
        foreach ($data['api_tokens'] ?? [] as $tokenData) {
            $token = new ApiToken();
            $token->project = $project;
            $token->name = $tokenData['name'];
            $token->abilities = $tokenData['abilities'] ?? [];
        }

        // Webhooks: create new (secrets can't be exported)
        foreach ($data['webhooks'] ?? [] as $webhookData) {
            $webhook = new Webhook();
            $webhook->project = $project;
            $webhook->name = $webhookData['name'];
            $webhook->url = $webhookData['url'];
            $webhook->events = $webhookData['events'] ?? [];
            $webhook->isActive = $webhookData['is_active'] ?? false;
        }
    }

    public function previewConflicts(Project $project, string $extractedDir): array
    {
        return []; // No conflicts for settings (always merged)
    }
}
```

- [ ] **Step 4: Lancer le test (doit réussir)**

Run: `php vendor/bin/phpunit tests/Service/ExportImport/Import/SettingsImportHandlerTest.php`
Expected: PASS.

---

### Task 11: Créer ConflictResolver

**Files:**
- Create: `src/Service/ExportImport/ConflictResolver.php`
- Create: `tests/Service/ExportImport/ConflictResolverTest.php`

- [ ] **Step 1: Écrire le test**

```php
<?php

namespace App\Tests\Service\ExportImport;

use App\Dto\ConflictItem;
use App\Service\ExportImport\ConflictResolver;
use PHPUnit\Framework\TestCase;

class ConflictResolverTest extends TestCase
{
    public function testApplyStrategyOverwrite(): void
    {
        $resolver = new ConflictResolver();

        $conflicts = [
            ConflictItem::create('collection', 'articles', '', 'abc-123'),
            ConflictItem::create('content_entry', 'articles/en', 'def-456', 'def-456'),
        ];

        $resolved = $resolver->applyStrategy($conflicts, 'overwrite');
        foreach ($resolved as $item) {
            $this->assertSame('overwrite', $item->chosenAction);
        }
    }

    public function testApplyStrategySkip(): void
    {
        $resolver = new ConflictResolver();

        $conflicts = [
            ConflictItem::create('collection', 'articles', '', 'abc-123'),
        ];

        $resolved = $resolver->applyStrategy($conflicts, 'skip');
        $this->assertSame('skip', $resolved[0]->chosenAction);
    }

    public function testApplyStrategyNewUuids(): void
    {
        $resolver = new ConflictResolver();

        $conflicts = [
            ConflictItem::create('content_entry', 'page/fr', 'old-1', 'old-1'),
        ];

        $resolved = $resolver->applyStrategy($conflicts, 'new_uuids');
        $this->assertSame('new_uuid', $resolved[0]->chosenAction);
    }

    public function testHasConflicts(): void
    {
        $resolver = new ConflictResolver();
        $this->assertTrue($resolver->hasConflicts([
            ConflictItem::create('media', 'img.jpg', 'a', 'a'),
        ]));
        $this->assertFalse($resolver->hasConflicts([]));
    }
}
```

- [ ] **Step 2: Lancer le test (doit échouer)**

Run: `php vendor/bin/phpunit tests/Service/ExportImport/ConflictResolverTest.php`
Expected: FAIL.

- [ ] **Step 3: Implémenter ConflictResolver**

```php
<?php

namespace App\Service\ExportImport;

use App\Dto\ConflictItem;

class ConflictResolver
{
    /**
     * @param ConflictItem[] $conflicts
     * @return ConflictItem[]
     */
    public function applyStrategy(array $conflicts, string $strategy): array
    {
        foreach ($conflicts as $item) {
            $item->chosenAction = $strategy;
        }
        return $conflicts;
    }

    /**
     * @param ConflictItem[] $conflicts
     */
    public function hasConflicts(array $conflicts): bool
    {
        return count($conflicts) > 0;
    }
}
```

- [ ] **Step 4: Lancer le test (doit réussir)**

Run: `php vendor/bin/phpunit tests/Service/ExportImport/ConflictResolverTest.php`
Expected: PASS.

---

### Task 12: Créer ProjectExporter (Orchestrateur)

**Files:**
- Create: `src/Service/ExportImport/ProjectExporter.php`
- Create: `tests/Service/ExportImport/ProjectExporterTest.php`

- [ ] **Step 1: Écrire le test**

```php
<?php

namespace App\Tests\Service\ExportImport;

use App\Dto\ExportOptions;
use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\Service\ExportImport\Export\ContentExportHandler;
use App\Service\ExportImport\Export\MediaExportHandler;
use App\Service\ExportImport\Export\SettingsExportHandler;
use App\Service\ExportImport\Export\StructureExportHandler;
use App\Service\ExportImport\ProjectExporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class ProjectExporterTest extends TestCase
{
    public function testExportCreatesZipFile(): void
    {
        $projectDir = realpath(__DIR__ . '/../../../');
        $exporter = new ProjectExporter(
            new StructureExportHandler(),
            new ContentExportHandler(),
            new MediaExportHandler($projectDir),
            new SettingsExportHandler(),
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

            $zip->close();
        } finally {
            @unlink($zipPath);
        }
    }
}
```

- [ ] **Step 2: Lancer le test (doit échouer)**

Run: `php vendor/bin/phpunit tests/Service/ExportImport/ProjectExporterTest.php`
Expected: FAIL.

- [ ] **Step 3: Implémenter ProjectExporter**

```php
<?php

namespace App\Service\ExportImport;

use App\Dto\ExportOptions;
use App\Entity\Project;

class ProjectExporter
{
    /** @var array<string, ExportHandlerInterface> */
    private array $handlers = [];

    public function __construct(
        Export\StructureExportHandler $structureHandler,
        Export\ContentExportHandler $contentHandler,
        Export\MediaExportHandler $mediaHandler,
        Export\SettingsExportHandler $settingsHandler,
    ) {
        $this->handlers['structure'] = $structureHandler;
        $this->handlers['content'] = $contentHandler;
        $this->handlers['media'] = $mediaHandler;
        $this->handlers['settings'] = $settingsHandler;
    }

    public function export(Project $project, ExportOptions $options, string $outputPath): void
    {
        $tempDir = sys_get_temp_dir() . '/jambo-export-' . $project->uuid?->toString();
        if (is_dir($tempDir)) {
            $this->removeDir($tempDir);
        }
        mkdir($tempDir, 0777, true);

        try {
            $included = [];
            $manifestEntries = [];

            foreach ($this->handlers as $key => $handler) {
                $enabled = match ($key) {
                    'structure' => $options->structure,
                    'content' => $options->content,
                    'media' => $options->media,
                    'settings' => $options->settings,
                    default => false,
                };

                if (!$enabled) {
                    continue;
                }

                $result = $handler->export($project, $tempDir);
                $included[] = $key;
                if (isset($result['manifest'])) {
                    $manifestEntries[$key] = $result['manifest'];
                }
            }

            // Write manifest.json
            $manifest = [
                'version'     => '1.0',
                'exported_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'project'     => [
                    'name'           => $project->name,
                    'uuid'           => $project->uuid?->toString(),
                    'default_locale' => $project->defaultLocale,
                ],
                'included' => $included,
                'counts'   => $manifestEntries,
            ];
            file_put_contents($tempDir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            // Create ZIP
            $zip = new \ZipArchive();
            $zip->open($outputPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($tempDir) + 1);
                $relativePath = str_replace('\\', '/', $relativePath);
                $zip->addFile($filePath, $relativePath);
            }

            $zip->close();
        } finally {
            $this->removeDir($tempDir);
        }
    }

    public function streamExport(Project $project, ExportOptions $options): string
    {
        $outputPath = sys_get_temp_dir() . '/jambo-stream-' . uniqid() . '.zip';
        $this->export($project, $options, $outputPath);
        return $outputPath;
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
```

- [ ] **Step 4: Lancer le test (doit réussir)**

Run: `php vendor/bin/phpunit tests/Service/ExportImport/ProjectExporterTest.php`
Expected: PASS.

---

### Task 13: Créer ProjectImporter (Orchestrateur)

**Files:**
- Create: `src/Service/ExportImport/ProjectImporter.php`
- Create: `tests/Service/ExportImport/ProjectImporterTest.php`

- [ ] **Step 1: Écrire le test**

```php
<?php

namespace App\Tests\Service\ExportImport;

use App\Dto\ImportOptions;
use App\Entity\Project;
use App\Service\ExportImport\Import\StructureImportHandler;
use App\Service\ExportImport\Import\ContentImportHandler;
use App\Service\ExportImport\Import\MediaImportHandler;
use App\Service\ExportImport\Import\SettingsImportHandler;
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
        file_put_contents($tempDir . '/manifest.json', json_encode(['included' => ['structure', 'content']]));
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
}
```

- [ ] **Step 2: Lancer le test (doit échouer)**

Run: `php vendor/bin/phpunit tests/Service/ExportImport/ProjectImporterTest.php`
Expected: FAIL.

- [ ] **Step 3: Implémenter ProjectImporter**

```php
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
    ) {
        $this->handlers['structure'] = $structureHandler;
        $this->handlers['content'] = $contentHandler;
        $this->handlers['media'] = $mediaHandler;
        $this->handlers['settings'] = $settingsHandler;
    }

    public function extractZip(string $zipPath): string
    {
        $tempDir = sys_get_temp_dir() . '/jambo-import-' . uniqid();
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

        // Import in order: structure → media → content → settings
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
```

- [ ] **Step 4: Lancer le test (doit réussir)**

Run: `php vendor/bin/phpunit tests/Service/ExportImport/ProjectImporterTest.php`
Expected: PASS.

---

### Task 14: Créer ProjectExportImportController

**Files:**
- Create: `src/Controller/Api/ProjectExportImportController.php`

- [ ] **Step 1: Enregistrer les nouveaux services dans config/services.yaml**

Modify: `config/services.yaml` (ajouter le paramètre `project_dir` pour les handlers media)

```yaml
services:
    App\Service\ExportImport\Export\MediaExportHandler:
        arguments:
            $projectDir: '%kernel.project_dir%'

    App\Service\ExportImport\Import\MediaImportHandler:
        arguments:
            $projectDir: '%kernel.project_dir%'
```

- [ ] **Step 2: Implémenter le contrôleur**

```php
<?php

namespace App\Controller\Api;

use App\Dto\ExportOptions;
use App\Dto\ImportOptions;
use App\Entity\Project;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use App\Service\ExportImport\ProjectExporter;
use App\Service\ExportImport\ProjectImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/projects', name: 'api_project_export_import_')]
class ProjectExportImportController extends AbstractController
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private UserRepository $userRepository,
        private ProjectExporter $exporter,
        private ProjectImporter $importer,
        private EntityManagerInterface $em,
    ) {}

    #[Route('/{uuid}/export', name: 'export', methods: ['GET'])]
    public function export(string $uuid, Request $request): Response
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $uuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $options = ExportOptions::fromRequest($request->query->all());
        if (empty($options->getEnabledOptions())) {
            $options->structure = true;
        }

        $zipPath = $this->exporter->streamExport($project, $options);

        $filename = sprintf('export-%s-%s.zip',
            preg_replace('/[^a-zA-Z0-9_-]/', '-', $project->name),
            (new \DateTimeImmutable())->format('Y-m-d-His'),
        );

        $response = new BinaryFileResponse($zipPath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
        $response->deleteFileAfterSend();

        return $response;
    }

    #[Route('/{uuid}/export/preview', name: 'export_preview', methods: ['GET'])]
    public function exportPreview(string $uuid): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $uuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $collectionCount = count($project->collections);
        $entryCount = 0;
        $mediaCount = count($project->getMedia());

        foreach ($project->collections as $collection) {
            $entryCount += count($collection->contentEntries);
        }

        return $this->json([
            'data' => [
                'collections' => $collectionCount,
                'entries'     => $entryCount,
                'media'       => $mediaCount,
            ],
        ]);
    }

    #[Route('/import', name: 'import_new', methods: ['POST'])]
    public function importNew(Request $request): JsonResponse
    {
        $uploadedFile = $request->files->get('file');
        if (!$uploadedFile) {
            return $this->json(['error' => 'No file uploaded'], 422);
        }

        $data = $request->request->all();
        $options = ImportOptions::fromRequest($data);
        $options->createNewProject = true;

        if (empty($options->newProjectName)) {
            return $this->json(['error' => 'new_project_name is required'], 422);
        }

        $extractedDir = null;
        try {
            $extractedDir = $this->importer->extractZip($uploadedFile->getPathname());
            $manifest = $this->importer->validateManifest($extractedDir);

            $project = new Project();
            $project->name = $options->newProjectName;
            $project->defaultLocale = $manifest['project']['default_locale'] ?? 'en';
            $project->locales = $manifest['project']['default_locale'] ? [$manifest['project']['default_locale']] : ['en'];

            $this->em->persist($project);
            $this->em->flush();

            $this->importer->import($project, $extractedDir, $options);
            $this->em->flush();

            return $this->json([
                'data' => [
                    'uuid' => $project->uuid?->toString(),
                    'name' => $project->name,
                ],
            ], 201);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Import failed: ' . $e->getMessage()], 500);
        } finally {
            if ($extractedDir) {
                $this->importer->cleanup($extractedDir);
            }
        }
    }

    #[Route('/import/preview', name: 'import_preview', methods: ['POST'])]
    public function importPreview(Request $request): JsonResponse
    {
        $uploadedFile = $request->files->get('file');
        if (!$uploadedFile) {
            return $this->json(['error' => 'No file uploaded'], 422);
        }

        $extractedDir = null;
        try {
            $extractedDir = $this->importer->extractZip($uploadedFile->getPathname());
            $manifest = $this->importer->validateManifest($extractedDir);

            // For preview, create a temporary empty project
            $tempProject = new Project();
            $tempProject->name = $manifest['project']['name'] ?? 'Preview';
            $tempProject->defaultLocale = $manifest['project']['default_locale'] ?? 'en';
            $tempProject->locales = $manifest['project']['default_locale'] ? [$manifest['project']['default_locale']] : ['en'];

            $conflicts = $this->importer->previewConflicts($tempProject, $extractedDir);

            return $this->json([
                'data' => [
                    'manifest'  => $manifest,
                    'conflicts' => array_map(fn ($c) => $c->toArray(), $conflicts),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Preview failed: ' . $e->getMessage()], 500);
        } finally {
            if ($extractedDir) {
                $this->importer->cleanup($extractedDir);
            }
        }
    }

    #[Route('/{uuid}/import/merge', name: 'import_merge', methods: ['POST'])]
    public function importMerge(string $uuid, Request $request): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $uuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $uploadedFile = $request->files->get('file');
        if (!$uploadedFile) {
            return $this->json(['error' => 'No file uploaded'], 422);
        }

        $data = $request->request->all();
        $options = ImportOptions::fromRequest($data);
        $options->createNewProject = false;

        $extractedDir = null;
        try {
            $extractedDir = $this->importer->extractZip($uploadedFile->getPathname());
            $this->importer->validateManifest($extractedDir);

            $this->em->beginTransaction();
            try {
                $this->importer->import($project, $extractedDir, $options);
                $this->em->flush();
                $this->em->commit();
            } catch (\Throwable $e) {
                $this->em->rollback();
                throw $e;
            }

            return $this->json(['data' => ['uuid' => $project->uuid?->toString()]]);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Import failed: ' . $e->getMessage()], 500);
        } finally {
            if ($extractedDir) {
                $this->importer->cleanup($extractedDir);
            }
        }
    }
}
```

- [ ] **Step 3: Vérifier les routes**

Run: `php bin/console debug:router | grep export`
Expected: routes listées pour export, export_preview, import_new, import_preview, import_merge.

---

### Task 15: Créer les commandes CLI

**Files:**
- Create: `src/Command/ProjectExportCommand.php`
- Create: `src/Command/ProjectImportCommand.php`

- [ ] **Step 1: Implémenter ProjectExportCommand**

```php
<?php

namespace App\Command;

use App\Dto\ExportOptions;
use App\Repository\ProjectRepository;
use App\Service\ExportImport\ProjectExporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'project:export',
    description: 'Export a project to a ZIP file',
)]
class ProjectExportCommand extends Command
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private ProjectExporter $exporter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('uuid', InputArgument::REQUIRED, 'UUID of the project to export')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output ZIP file path')
            ->addOption('with-content', null, InputOption::VALUE_NONE, 'Include content entries')
            ->addOption('with-media', null, InputOption::VALUE_NONE, 'Include media files')
            ->addOption('with-settings', null, InputOption::VALUE_NONE, 'Include project settings');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $uuid = $input->getArgument('uuid');

        $project = $this->projectRepository->findOneBy(['uuid' => $uuid]);
        if (!$project) {
            $io->error("Project not found: $uuid");
            return Command::FAILURE;
        }

        $options = new ExportOptions();
        $options->content = $input->getOption('with-content');
        $options->media = $input->getOption('with-media');
        $options->settings = $input->getOption('with-settings');

        $outputPath = $input->getOption('output')
            ?? sprintf('export-%s-%s.zip', $project->name, date('Y-m-d-His'));

        $io->section("Exporting project: {$project->name}");
        $io->listing($options->getEnabledOptions());

        $this->exporter->export($project, $options, $outputPath);

        $size = filesize($outputPath);
        $io->success(sprintf('Exported to %s (%s)', $outputPath, $this->formatBytes($size)));

        return Command::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}
```

- [ ] **Step 2: Implémenter ProjectImportCommand**

```php
<?php

namespace App\Command;

use App\Dto\ImportOptions;
use App\Entity\Project;
use App\Repository\ProjectRepository;
use App\Service\ExportImport\ProjectImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'project:import',
    description: 'Import a project from a ZIP file',
)]
class ProjectImportCommand extends Command
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private ProjectImporter $importer,
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the ZIP file to import')
            ->addOption('project-name', null, InputOption::VALUE_OPTIONAL, 'Name for the new project')
            ->addOption('owner', null, InputOption::VALUE_OPTIONAL, 'Owner email')
            ->addOption('target-project', null, InputOption::VALUE_OPTIONAL, 'UUID of existing project to merge into')
            ->addOption('strategy', null, InputOption::VALUE_OPTIONAL, 'Conflict resolution: overwrite, skip, or new-uuids', 'skip')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview conflicts only, do not import');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filePath = $input->getArgument('file');

        if (!file_exists($filePath)) {
            $io->error("File not found: $filePath");
            return Command::FAILURE;
        }

        $options = new ImportOptions();
        $options->strategy = $input->getOption('strategy') ?? 'skip';

        $targetUuid = $input->getOption('target-project');

        $extractedDir = null;
        try {
            $extractedDir = $this->importer->extractZip($filePath);
            $manifest = $this->importer->validateManifest($extractedDir);

            $io->section('Export package info');
            $io->definitionList(
                ['Version' => $manifest['version']],
                ['Exported at' => $manifest['exported_at']],
                ['Source project' => $manifest['project']['name']],
                ['Includes' => implode(', ', $manifest['included'])],
            );

            if ($targetUuid) {
                $project = $this->projectRepository->findOneBy(['uuid' => $targetUuid]);
                if (!$project) {
                    $io->error("Target project not found: $targetUuid");
                    return Command::FAILURE;
                }
                $options->createNewProject = false;
            } else {
                $project = new Project();
                $project->name = $input->getOption('project-name') ?? $manifest['project']['name'] . ' (imported)';
                $project->defaultLocale = $manifest['project']['default_locale'] ?? 'en';
                $project->locales = $project->defaultLocale ? [$project->defaultLocale] : ['en'];
                $options->createNewProject = true;
            }

            // Preview conflicts
            $conflicts = $this->importer->previewConflicts($project, $extractedDir);

            if (!empty($conflicts)) {
                $io->section('Conflicts detected');
                $rows = array_map(fn ($c) => [
                    $c->entityType,
                    $c->entityName,
                    $c->entityUuid,
                    $options->strategy,
                ], $conflicts);
                $io->table(['Type', 'Name', 'UUID', 'Action'], $rows);
            } else {
                $io->success('No conflicts detected');
            }

            if ($input->getOption('dry-run')) {
                return Command::SUCCESS;
            }

            if (!$io->confirm('Proceed with import?', true)) {
                return Command::SUCCESS;
            }

            if ($options->createNewProject) {
                $this->em->persist($project);
                $this->em->flush();
            }

            $this->em->beginTransaction();
            try {
                $this->importer->import($project, $extractedDir, $options);
                $this->em->flush();
                $this->em->commit();
            } catch (\Throwable $e) {
                $this->em->rollback();
                throw $e;
            }

            $io->success(sprintf('Import complete. Project UUID: %s', $project->uuid?->toString()));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Import failed: ' . $e->getMessage());
            return Command::FAILURE;
        } finally {
            if ($extractedDir) {
                $this->importer->cleanup($extractedDir);
            }
        }
    }
}
```

- [ ] **Step 3: Vérifier les commandes**

Run: `php bin/console list | grep project:`
Expected: `project:export` et `project:import` apparaissent.

---

### Task 16: Créer les composants React (ExportModal, ImportModal)

**Files:**
- Create: `assets/js/pages/Projects/Export/ExportModal.tsx`
- Create: `assets/js/pages/Projects/Import/ImportModal.tsx`

- [ ] **Step 1: Implémenter ExportModal.tsx**

```tsx
import { useState } from 'react';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Checkbox } from '@/components/ui/checkbox';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import axios from 'axios';
import { toast } from 'sonner';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    projectUuid: string;
    projectName: string;
}

export default function ExportModal({ open, onOpenChange, projectUuid, projectName }: Props) {
    const { t } = useTranslation();
    const [structure, setStructure] = useState(true);
    const [content, setContent] = useState(false);
    const [media, setMedia] = useState(false);
    const [settings, setSettings] = useState(false);
    const [processing, setProcessing] = useState(false);

    const handleExport = async () => {
        setProcessing(true);
        try {
            const params = new URLSearchParams();
            if (structure) params.set('structure', '1');
            if (content) params.set('content', '1');
            if (media) params.set('media', '1');
            if (settings) params.set('settings', '1');

            const response = await axios.get(
                `/api/projects/${projectUuid}/export?${params.toString()}`,
                { responseType: 'blob' },
            );

            const url = window.URL.createObjectURL(new Blob([response.data]));
            const link = document.createElement('a');
            link.href = url;
            const disposition = response.headers['content-disposition'];
            const filename = disposition?.match(/filename="?(.+?)"?$/)?.[1] ?? `export-${projectName}.zip`;
            link.setAttribute('download', filename);
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.URL.revokeObjectURL(url);

            toast.success(t('projects.export.success'));
            onOpenChange(false);
        } catch (e) {
            console.error(e);
            toast.error(t('projects.export.error'));
        } finally {
            setProcessing(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('projects.export.title')} — {projectName}</DialogTitle>
                </DialogHeader>
                <div className="space-y-4 py-4">
                    <div className="flex items-center space-x-2">
                        <Checkbox id="structure" checked={structure} onCheckedChange={(v) => setStructure(!!v)} disabled />
                        <Label htmlFor="structure">{t('projects.export.structure')}</Label>
                    </div>
                    <div className="flex items-center space-x-2">
                        <Checkbox id="content" checked={content} onCheckedChange={(v) => setContent(!!v)} />
                        <Label htmlFor="content">{t('projects.export.content')}</Label>
                    </div>
                    <div className="flex items-center space-x-2">
                        <Checkbox id="media" checked={media} onCheckedChange={(v) => setMedia(!!v)} />
                        <Label htmlFor="media">{t('projects.export.media')}</Label>
                    </div>
                    <div className="flex items-center space-x-2">
                        <Checkbox id="settings" checked={settings} onCheckedChange={(v) => setSettings(!!v)} />
                        <Label htmlFor="settings">{t('projects.export.settings')}</Label>
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        {t('common.cancel')}
                    </Button>
                    <Button onClick={handleExport} disabled={processing}>
                        {processing ? t('common.exporting') : t('projects.export.button')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
```

- [ ] **Step 2: Implémenter ImportModal.tsx**

```tsx
import { useState, useCallback } from 'react';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { useTranslation } from '@/lib/i18n';
import { useDropzone } from 'react-dropzone';
import axios from 'axios';
import { toast } from 'sonner';
import { router } from '@inertiajs/react';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

interface ConflictItem {
    entity_type: string;
    entity_name: string;
    entity_uuid: string;
    existing_uuid: string;
    suggested_action: string;
    chosen_action: string;
}

export default function ImportModal({ open, onOpenChange }: Props) {
    const { t } = useTranslation();
    const [file, setFile] = useState<File | null>(null);
    const [mode, setMode] = useState<'new' | 'merge'>('new');
    const [projectName, setProjectName] = useState('');
    const [targetProjectUuid, setTargetProjectUuid] = useState('');
    const [strategy, setStrategy] = useState('skip');
    const [step, setStep] = useState<'upload' | 'conflicts' | 'importing'>('upload');
    const [conflicts, setConflicts] = useState<ConflictItem[]>([]);
    const [manifest, setManifest] = useState<any>(null);
    const [processing, setProcessing] = useState(false);

    const onDrop = useCallback((acceptedFiles: File[]) => {
        if (acceptedFiles.length > 0) {
            setFile(acceptedFiles[0]);
            handlePreview(acceptedFiles[0]);
        }
    }, []);

    const { getRootProps, getInputProps, isDragActive } = useDropzone({
        onDrop,
        accept: { 'application/zip': ['.zip'] },
        maxFiles: 1,
    });

    const handlePreview = async (uploadedFile?: File) => {
        const f = uploadedFile || file;
        if (!f) return;

        setProcessing(true);
        try {
            const formData = new FormData();
            formData.append('file', f);

            const { data } = await axios.post('/api/projects/import/preview', formData);
            setManifest(data.data.manifest);
            setConflicts(data.data.conflicts || []);
            setStep('conflicts');
        } catch (e) {
            console.error(e);
            toast.error(t('projects.import.preview_error'));
        } finally {
            setProcessing(false);
        }
    };

    const handleImport = async () => {
        if (!file) return;

        setProcessing(true);
        setStep('importing');
        try {
            const formData = new FormData();
            formData.append('file', file);
            formData.append('strategy', strategy);
            formData.append('create_new_project', mode === 'new' ? '1' : '0');
            if (mode === 'new') {
                formData.append('new_project_name', projectName || manifest?.project?.name || 'Imported');
            }

            const url = mode === 'new'
                ? '/api/projects/import'
                : `/api/projects/${targetProjectUuid}/import/merge`;

            const { data } = await axios.post(url, formData);
            toast.success(t('projects.import.success'));
            onOpenChange(false);
            router.visit(`/projects/${data.data.uuid}`);
        } catch (e) {
            console.error(e);
            toast.error(t('projects.import.error'));
        } finally {
            setProcessing(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('projects.import.title')}</DialogTitle>
                </DialogHeader>

                {step === 'upload' && (
                    <div className="space-y-4 py-4">
                        <div {...getRootProps()} className="border-2 border-dashed rounded-lg p-8 text-center cursor-pointer hover:bg-muted/50 transition-colors">
                            <input {...getInputProps()} />
                            {isDragActive
                                ? <p>{t('projects.import.drop_here')}</p>
                                : <p>{file ? file.name : t('projects.import.drop_zone')}</p>
                            }
                        </div>
                        <RadioGroup value={mode} onValueChange={(v) => setMode(v as 'new' | 'merge')}>
                            <div className="flex items-center space-x-2">
                                <RadioGroupItem value="new" id="mode-new" />
                                <Label htmlFor="mode-new">{t('projects.import.new_project')}</Label>
                            </div>
                            <div className="flex items-center space-x-2">
                                <RadioGroupItem value="merge" id="mode-merge" />
                                <Label htmlFor="mode-merge">{t('projects.import.merge')}</Label>
                            </div>
                        </RadioGroup>
                        {mode === 'new' && (
                            <Input
                                placeholder={t('projects.import.project_name')}
                                value={projectName}
                                onChange={(e) => setProjectName(e.target.value)}
                            />
                        )}
                        {mode === 'merge' && (
                            <Input
                                placeholder={t('projects.import.target_uuid')}
                                value={targetProjectUuid}
                                onChange={(e) => setTargetProjectUuid(e.target.value)}
                            />
                        )}
                    </div>
                )}

                {step === 'conflicts' && conflicts.length > 0 && (
                    <div className="space-y-4 py-4">
                        <h4 className="font-medium">{t('projects.import.conflicts_detected')}</h4>
                        <RadioGroup value={strategy} onValueChange={setStrategy}>
                            <div className="flex items-center space-x-2">
                                <RadioGroupItem value="skip" id="strat-skip" />
                                <Label htmlFor="strat-skip">{t('projects.import.strategy_skip')}</Label>
                            </div>
                            <div className="flex items-center space-x-2">
                                <RadioGroupItem value="overwrite" id="strat-overwrite" />
                                <Label htmlFor="strat-overwrite">{t('projects.import.strategy_overwrite')}</Label>
                            </div>
                            <div className="flex items-center space-x-2">
                                <RadioGroupItem value="new_uuids" id="strat-new" />
                                <Label htmlFor="strat-new">{t('projects.import.strategy_new_uuids')}</Label>
                            </div>
                        </RadioGroup>
                        <div className="max-h-48 overflow-y-auto border rounded">
                            {conflicts.map((c, i) => (
                                <div key={i} className="flex justify-between px-3 py-1.5 border-b last:border-0 text-sm">
                                    <span className="font-mono text-xs bg-muted px-1 rounded">{c.entity_type}</span>
                                    <span>{c.entity_name}</span>
                                    <span className="text-muted-foreground">{strategy}</span>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {step === 'conflicts' && conflicts.length === 0 && (
                    <div className="py-4 text-center text-muted-foreground">
                        {t('projects.import.no_conflicts')}
                    </div>
                )}

                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        {t('common.cancel')}
                    </Button>
                    {(step === 'upload' || step === 'conflicts') && (
                        <Button onClick={handleImport} disabled={!file || processing || (mode === 'new' && !projectName)}>
                            {processing ? t('common.importing') : t('projects.import.button')}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
```

- [ ] **Step 3: Ajouter les clés de traduction**

Modify: `translations/messages.fr.php` — ajouter les clés manquantes pour export/import.

```php
'projects.export.title' => 'Exporter le projet',
'projects.export.structure' => 'Structure (collections, champs)',
'projects.export.content' => 'Contenu (entrées, valeurs)',
'projects.export.media' => 'Médias (fichiers)',
'projects.export.settings' => 'Paramètres (locales, membres, tokens, webhooks)',
'projects.export.button' => 'Exporter',
'projects.export.success' => 'Projet exporté avec succès',
'projects.export.error' => 'Erreur lors de l\'export',
'projects.import.title' => 'Importer un projet',
'projects.import.drop_zone' => 'Glissez un fichier .zip ou cliquez pour sélectionner',
'projects.import.drop_here' => 'Déposez le fichier ici',
'projects.import.new_project' => 'Créer un nouveau projet',
'projects.import.merge' => 'Fusionner dans un projet existant',
'projects.import.project_name' => 'Nom du nouveau projet',
'projects.import.target_uuid' => 'UUID du projet cible',
'projects.import.conflicts_detected' => 'Conflits détectés',
'projects.import.strategy_skip' => 'Ignorer les doublons',
'projects.import.strategy_overwrite' => 'Écraser les données existantes',
'projects.import.strategy_new_uuids' => 'Générer de nouveaux UUIDs',
'projects.import.no_conflicts' => 'Aucun conflit détecté',
'projects.import.button' => 'Importer',
'projects.import.success' => 'Projet importé avec succès',
'projects.import.error' => 'Erreur lors de l\'import',
'projects.import.preview_error' => 'Erreur lors de l\'analyse du fichier',
```

---

### Task 17: Test d'intégration (roundtrip)

**Files:**
- Create: `tests/Service/ExportImport/ExportImportRoundtripTest.php`

- [ ] **Step 1: Écrire le test d'intégration roundtrip**

```php
<?php

namespace App\Tests\Service\ExportImport;

use App\Dto\ExportOptions;
use App\Dto\ImportOptions;
use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\ContentFieldValue;
use App\Entity\Field;
use App\Entity\Project;
use App\Service\ExportImport\Export\ContentExportHandler;
use App\Service\ExportImport\Export\MediaExportHandler;
use App\Service\ExportImport\Export\SettingsExportHandler;
use App\Service\ExportImport\Export\StructureExportHandler;
use App\Service\ExportImport\Import\ContentImportHandler;
use App\Service\ExportImport\Import\MediaImportHandler;
use App\Service\ExportImport\Import\SettingsImportHandler;
use App\Service\ExportImport\Import\StructureImportHandler;
use App\Service\ExportImport\ProjectExporter;
use App\Service\ExportImport\ProjectImporter;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class ExportImportRoundtripTest extends TestCase
{
    public function testFullRoundtripStructureAndContent(): void
    {
        $projectDir = realpath(__DIR__ . '/../../..');

        // --- Setup: create a project with structure + content ---
        $source = new Project();
        $source->name = 'Roundtrip Test';
        $source->uuid = Uuid::v4();
        $source->defaultLocale = 'fr';
        $source->locales = ['fr', 'en'];

        $collection = new Collection();
        $collection->name = 'Articles';
        $collection->slug = 'articles';
        $collection->project = $source;
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
        $exporter = new ProjectExporter(
            new StructureExportHandler(),
            new ContentExportHandler(),
            new MediaExportHandler($projectDir),
            new SettingsExportHandler(),
        );

        $exportOptions = new ExportOptions();
        $exportOptions->structure = true;
        $exportOptions->content = true;

        $zipPath = sys_get_temp_dir() . '/roundtrip-' . uniqid() . '.zip';
        $exporter->export($source, $exportOptions, $zipPath);

        $this->assertFileExists($zipPath);

        // --- Import ---
        $importer = new ProjectImporter(
            new StructureImportHandler(),
            new ContentImportHandler(),
            new MediaImportHandler($projectDir),
            new SettingsImportHandler(),
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
            $importer->import($target, $extractedDir, $importOptions, $uuidMap);

            // --- Assertions ---
            $this->assertCount(1, $target->collections);
            $importedCollection = $target->collections->first();
            $this->assertSame('articles', $importedCollection->slug);
            $this->assertCount(1, $importedCollection->fields);
            $this->assertSame('title', $importedCollection->fields->first()->slug);

            $this->assertCount(1, $importedCollection->contentEntries);
            $importedEntry = $importedCollection->contentEntries->first();
            $this->assertSame('published', $importedEntry->status);
            $this->assertCount(1, $importedEntry->fieldValues);
            $this->assertSame('Bonjour le monde', $importedEntry->fieldValues->first()->textValue);

            // Verify UUIDs are different (new_uuids strategy)
            $this->assertNotSame($entry->uuid->toString(), $importedEntry->uuid->toString());
        } finally {
            $importer->cleanup($extractedDir);
            @unlink($zipPath);
        }
    }
}
```

- [ ] **Step 2: Lancer le test (doit échouer)**

Run: `php vendor/bin/phpunit tests/Service/ExportImport/ExportImportRoundtripTest.php`
Expected: FAIL (entités non persistées via EntityManager — les relations internes peuvent ne pas fonctionner comme avec Doctrine).

- [ ] **Step 3: Adapter et corriger**

Run: `php vendor/bin/phpunit tests/Service/ExportImport/ExportImportRoundtripTest.php`
Expected: PASS après corrections.

---

## Ordre d'exécution recommandé

1. Task 1 → DTOs (pas de dépendances)
2. Task 2 → Interfaces (dépend des DTOs)
3. Tasks 3-6 → Export handlers (indépendants entre eux)
4. Tasks 7-10 → Import handlers (indépendants entre eux)
5. Task 11 → ConflictResolver
6. Task 12 → ProjectExporter (dépend des export handlers)
7. Task 13 → ProjectImporter (dépend des import handlers)
8. Task 14 → Controller (dépend des deux orchestrateurs)
9. Task 15 → CLI commands (dépend des deux orchestrateurs)
10. Task 16 → React components
11. Task 17 → Test roundtrip (validation finale)
