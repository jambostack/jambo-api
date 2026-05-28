<?php

namespace App\Service\ExportImport\Import;

use App\Dto\ConflictItem;
use App\Dto\ImportOptions;
use App\Entity\Collection;
use App\Entity\EndUserField;
use App\Entity\Field;
use App\Entity\Project;
use App\Repository\EndUserFieldRepository;
use App\Service\ExportImport\ImportHandlerInterface;

class StructureImportHandler implements ImportHandlerInterface
{
    /** @var EndUserField[] */
    private array $importedEndUserFields = [];

    public function __construct(private EndUserFieldRepository $endUserFieldRepository) {}

    public static function getOptionKey(): string
    {
        return 'structure';
    }

    /** @return EndUserField[] */
    public function getImportedEndUserFields(): array
    {
        return $this->importedEndUserFields;
    }

    public function import(Project $project, string $extractedDir, ImportOptions $options, array &$uuidMap): void
    {
        $this->importedEndUserFields = [];
        $path = $extractedDir . '/structure.json';
        if (!file_exists($path)) {
            return;
        }

        $data = json_decode(file_get_contents($path), true);
        if (empty($data['collections']) && empty($data['end_user_fields'])) {
            return;
        }

        $existingSlugs = [];
        foreach ($project->collections as $c) {
            $existingSlugs[$c->slug] = $c;
        }

        foreach ($data['collections'] ?? [] as $colData) {
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

        if (!empty($data['end_user_fields'])) {
            $existingFieldSlugs = [];
            foreach ($this->endUserFieldRepository->findByProject($project) as $f) {
                if (!$f->isSystem) {
                    $existingFieldSlugs[$f->slug] = $f;
                }
            }

            foreach ($data['end_user_fields'] as $i => $fieldData) {
                $slugExists = isset($existingFieldSlugs[$fieldData['slug']]);

                // EndUserFields are identified by slug (no UUID) — new_uuids behaves like skip
                if ($slugExists && $options->strategy !== 'overwrite') {
                    continue;
                }

                if ($slugExists) {
                    $field = $existingFieldSlugs[$fieldData['slug']];
                } else {
                    $field = new EndUserField();
                    $field->project = $project;
                    $field->slug = $fieldData['slug'];
                    $field->isSystem = false;
                    $this->importedEndUserFields[] = $field;
                }

                $field->name = $fieldData['name'];
                $field->type = $fieldData['type'];
                $field->options = $fieldData['options'] ?? null;
                $field->order = $fieldData['order'] ?? $i;
                $field->isRequired = $fieldData['is_required'] ?? false;
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

        $existingFieldSlugs = [];
        foreach ($this->endUserFieldRepository->findByProject($project) as $f) {
            if (!$f->isSystem) {
                $existingFieldSlugs[$f->slug] = true;
            }
        }
        foreach ($data['end_user_fields'] ?? [] as $fieldData) {
            if (isset($existingFieldSlugs[$fieldData['slug']])) {
                $conflicts[] = ConflictItem::create(
                    'end_user_field',
                    $fieldData['slug'],
                    '',
                    $fieldData['slug'],
                );
            }
        }

        return $conflicts;
    }
}
