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
    private const ALLOWED_FIELD_TYPES = [
        'text', 'longtext', 'richtext', 'wysiwyg', 'markdown', 'number', 'decimal', 'rating',
        'boolean', 'checkbox', 'date', 'datetime', 'email', 'url', 'slug', 'color', 'icon',
        'code', 'json', 'array', 'repeater', 'enumeration', 'tags', 'media', 'relation',
    ];

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
        if (!is_array($data) || (empty($data['collections']) && empty($data['end_user_fields']))) {
            return;
        }

        $collections = $data['collections'] ?? [];
        if (!is_array($collections)) {
            throw new \RuntimeException('Invalid structure.json format: collections must be an array.');
        }
        if (count($collections) > 100) {
            throw new \RuntimeException('structure.json contains too many collections (max 100).');
        }

        $existingSlugs = [];
        foreach ($project->collections as $c) {
            $existingSlugs[$c->slug] = $c;
        }

        foreach ($collections as $colData) {
            if (!is_array($colData) || empty($colData['slug']) || !is_string($colData['slug'])) {
                throw new \RuntimeException('Collection missing required valid slug.');
            }
            $colSlug = trim($colData['slug']);
            if (!preg_match('/^[a-z0-9_-]+$/i', $colSlug) || strlen($colSlug) > 128) {
                throw new \RuntimeException(sprintf('Invalid collection slug format: "%s".', $colSlug));
            }
            $colName = (string) ($colData['name'] ?? $colSlug);
            if (strlen($colName) > 255) {
                $colName = substr($colName, 0, 255);
            }

            $fieldsData = $colData['fields'] ?? [];
            if (!is_array($fieldsData)) {
                throw new \RuntimeException(sprintf('Collection "%s" fields must be an array.', $colSlug));
            }
            if (count($fieldsData) > 200) {
                throw new \RuntimeException(sprintf('Collection "%s" contains too many fields (max 200).', $colSlug));
            }

            if ($options->strategy === 'skip' && isset($existingSlugs[$colSlug])) {
                continue;
            }
            if ($options->strategy === 'overwrite' && isset($existingSlugs[$colSlug])) {
                $collection = $existingSlugs[$colSlug];
                $collection->fields->clear();
            } else {
                $collection = new Collection();
                $collection->project = $project;
                $collection->slug = $colSlug;
                $project->collections->add($collection);
            }

            $collection->name = $colName;
            $collection->description = isset($colData['description']) ? (string) $colData['description'] : null;
            $collection->isSingleton = (bool) ($colData['is_singleton'] ?? false);
            $collection->order = (int) ($colData['order'] ?? 0);

            foreach ($fieldsData as $i => $fieldData) {
                if (!is_array($fieldData) || empty($fieldData['slug']) || !is_string($fieldData['slug'])) {
                    throw new \RuntimeException(sprintf('Field at index %d in collection "%s" missing slug.', $i, $colSlug));
                }
                $fieldSlug = trim($fieldData['slug']);
                if (!preg_match('/^[a-z0-9_-]+$/i', $fieldSlug) || strlen($fieldSlug) > 128) {
                    throw new \RuntimeException(sprintf('Invalid field slug format: "%s".', $fieldSlug));
                }
                $fieldType = strtolower((string) ($fieldData['type'] ?? 'text'));
                if (!in_array($fieldType, self::ALLOWED_FIELD_TYPES, true)) {
                    throw new \RuntimeException(sprintf('Invalid field type "%s" for field "%s" in collection "%s".', $fieldType, $fieldSlug, $colSlug));
                }

                $field = new Field();
                $field->collection = $collection;
                $field->name = (string) ($fieldData['name'] ?? $fieldSlug);
                $field->slug = $fieldSlug;
                $field->type = $fieldType;
                $field->options = is_array($fieldData['options'] ?? null) ? $fieldData['options'] : null;
                $field->order = (int) ($fieldData['order'] ?? $i);
                $field->isRequired = (bool) ($fieldData['is_required'] ?? false);
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
