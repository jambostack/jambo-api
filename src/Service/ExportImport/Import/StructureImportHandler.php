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
                    '',
                    $existing->uuid?->toString() ?? $colData['slug'],
                );
            }
        }

        return $conflicts;
    }
}
