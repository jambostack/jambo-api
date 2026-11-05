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
use Symfony\Contracts\Translation\TranslatorInterface;

class StructureImportHandler implements ImportHandlerInterface
{
    private const ALLOWED_FIELD_TYPES = [
        'text', 'longtext', 'richtext', 'wysiwyg', 'markdown', 'number', 'decimal', 'rating',
        'boolean', 'checkbox', 'date', 'datetime', 'email', 'url', 'slug', 'color', 'icon',
        'code', 'json', 'array', 'repeater', 'enumeration', 'tags', 'media', 'relation',
    ];

    /** @var EndUserField[] */
    private array $importedEndUserFields = [];

    public function __construct(
        private EndUserFieldRepository $endUserFieldRepository,
        private ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters, 'messages');
        }
        return $default ?? $id;
    }

    private function getArrayDepth(array $array): int
    {
        $maxDepth = 1;
        foreach ($array as $value) {
            if (is_array($value)) {
                $depth = $this->getArrayDepth($value) + 1;
                if ($depth > $maxDepth) {
                    $maxDepth = $depth;
                }
            }
        }
        return $maxDepth;
    }

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
            throw new \RuntimeException($this->trans('import.structure.collections_must_be_array', [], 'Invalid structure.json format: collections must be an array.'));
        }
        if (count($collections) > 100) {
            throw new \RuntimeException($this->trans('import.structure.too_many_collections', ['%max%' => 100], 'structure.json contains too many collections (max 100).'));
        }

        $existingSlugs = [];
        $activeCollectionsCount = 0;
        foreach ($project->collections as $c) {
            $existingSlugs[$c->slug] = $c;
            if (!method_exists($c, 'isDeleted') || !$c->isDeleted()) {
                $activeCollectionsCount++;
            }
        }

        $newSlugsCount = 0;
        foreach ($collections as $colData) {
            if (is_array($colData) && !empty($colData['slug'])) {
                $s = trim($colData['slug']);
                if (!isset($existingSlugs[$s])) {
                    $newSlugsCount++;
                }
            }
        }

        if ($activeCollectionsCount + $newSlugsCount > 100) {
            throw new \RuntimeException($this->trans('import.structure.project_collection_quota_exceeded', [
                '%max%'      => 100,
                '%current%'  => $activeCollectionsCount,
                '%incoming%' => $newSlugsCount,
            ], sprintf('Importing collections would exceed project limit of 100 collections (current: %d, new: %d).', $activeCollectionsCount, $newSlugsCount)));
        }

        $availableCollectionSlugs = array_keys($existingSlugs);
        foreach ($collections as $cData) {
            if (is_array($cData) && !empty($cData['slug'])) {
                $availableCollectionSlugs[] = trim($cData['slug']);
            }
        }
        $availableCollectionSlugs = array_unique($availableCollectionSlugs);

        foreach ($collections as $colData) {
            if (!is_array($colData) || empty($colData['slug']) || !is_string($colData['slug'])) {
                throw new \RuntimeException($this->trans('import.structure.collection_missing_slug', [], 'Collection missing required valid slug.'));
            }
            $colSlug = trim($colData['slug']);
            if (!preg_match('/^[a-z0-9_-]+$/i', $colSlug) || strlen($colSlug) > 128) {
                throw new \RuntimeException($this->trans('import.structure.invalid_collection_slug', ['%slug%' => $colSlug], sprintf('Invalid collection slug format: "%s".', $colSlug)));
            }
            $colName = preg_replace('/[\x00-\x1F\x7F]/', '', (string) ($colData['name'] ?? $colSlug));
            if (strlen($colName) > 255) {
                $colName = substr($colName, 0, 255);
            }

            $fieldsData = $colData['fields'] ?? [];
            if (!is_array($fieldsData)) {
                throw new \RuntimeException($this->trans('import.structure.fields_must_be_array', ['%slug%' => $colSlug], sprintf('Collection "%s" fields must be an array.', $colSlug)));
            }
            if (count($fieldsData) > 200) {
                throw new \RuntimeException($this->trans('import.structure.too_many_fields', ['%slug%' => $colSlug, '%max%' => 200], sprintf('Collection "%s" contains too many fields (max 200).', $colSlug)));
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
            $collection->description = isset($colData['description']) ? mb_substr(preg_replace('/[\x00-\x1F\x7F]/', '', (string) $colData['description']), 0, 2000) : null;
            $collection->isSingleton = (bool) ($colData['is_singleton'] ?? false);
            $collection->order = (int) ($colData['order'] ?? 0);

            $seenFieldSlugs = [];
            foreach ($fieldsData as $i => $fieldData) {
                if (!is_array($fieldData) || empty($fieldData['slug']) || !is_string($fieldData['slug'])) {
                    throw new \RuntimeException($this->trans('import.structure.field_missing_slug', ['%index%' => $i, '%collection%' => $colSlug], sprintf('Field at index %d in collection "%s" missing slug.', $i, $colSlug)));
                }
                $fieldSlug = trim($fieldData['slug']);
                if (!preg_match('/^[a-z0-9_-]+$/i', $fieldSlug) || strlen($fieldSlug) > 128) {
                    throw new \RuntimeException($this->trans('import.structure.invalid_field_slug', ['%slug%' => $fieldSlug], sprintf('Invalid field slug format: "%s".', $fieldSlug)));
                }
                if (isset($seenFieldSlugs[$fieldSlug])) {
                    throw new \RuntimeException($this->trans('import.structure.duplicate_field_slug', [
                        '%slug%'       => $fieldSlug,
                        '%collection%' => $colSlug,
                    ], sprintf('Duplicate field slug "%s" in collection "%s".', $fieldSlug, $colSlug)));
                }
                $seenFieldSlugs[$fieldSlug] = true;

                $fieldType = strtolower((string) ($fieldData['type'] ?? 'text'));
                if (!in_array($fieldType, self::ALLOWED_FIELD_TYPES, true)) {
                    throw new \RuntimeException($this->trans('import.structure.invalid_field_type', ['%type%' => $fieldType, '%field%' => $fieldSlug, '%collection%' => $colSlug], sprintf('Invalid field type "%s" for field "%s" in collection "%s".', $fieldType, $fieldSlug, $colSlug)));
                }

                $fieldOptions = is_array($fieldData['options'] ?? null) ? $fieldData['options'] : null;
                if ($fieldOptions !== null && strlen((string) json_encode($fieldOptions)) > 32768) {
                    $fieldOptions = null;
                }
                if ($fieldOptions !== null && $this->getArrayDepth($fieldOptions) > 6) {
                    throw new \RuntimeException($this->trans('import.structure.options_too_deep', [
                        '%field%'      => $fieldSlug,
                        '%collection%' => $colSlug,
                    ], sprintf('Field "%s" in collection "%s" has options nested too deeply (max 6 levels).', $fieldSlug, $colSlug)));
                }

                // Normalisation et prévention des relations orphelines
                if ($fieldType === 'relation' && is_array($fieldOptions)) {
                    $relation = is_array($fieldOptions['relation'] ?? null) ? $fieldOptions['relation'] : [];
                    $relType = (int) ($relation['type'] ?? $fieldOptions['relationType'] ?? 1);
                    $targetSlug = $relation['collection_slug'] ?? $fieldOptions['targetCollection'] ?? null;

                    if ($targetSlug === 'end_users') {
                        $fieldOptions = [
                            'targetCollection' => 'end_users',
                            'relation' => ['type' => $relType, 'collection_slug' => 'end_users'],
                        ];
                    } elseif (is_string($targetSlug) && in_array($targetSlug, $availableCollectionSlugs, true)) {
                        $fieldOptions = [
                            'relation' => [
                                'type' => $relType,
                                'collection_slug' => $targetSlug,
                            ],
                        ];
                    } else {
                        // Relation inter-collection orpheline détectée (cible inexistante ou ID arbitraire)
                        $fieldOptions = [
                            'relation' => [
                                'type' => $relType,
                                'orphan' => true,
                                'collection_slug' => null,
                            ],
                        ];
                    }
                }

                $fieldName = preg_replace('/[\x00-\x1F\x7F]/', '', (string) ($fieldData['name'] ?? $fieldSlug));
                if (strlen($fieldName) > 255) {
                    $fieldName = substr($fieldName, 0, 255);
                }

                $field = new Field();
                $field->collection = $collection;
                $field->name = $fieldName;
                $field->slug = $fieldSlug;
                $field->type = $fieldType;
                $field->options = $fieldOptions;
                $field->order = (int) ($fieldData['order'] ?? $i);
                $field->isRequired = (bool) ($fieldData['is_required'] ?? false);
                $collection->fields->add($field);
            }
        }

        if (!empty($data['end_user_fields']) && is_array($data['end_user_fields'])) {
            if (count($data['end_user_fields']) > 100) {
                throw new \RuntimeException($this->trans('import.structure.too_many_end_user_fields', ['%max%' => 100], 'structure.json contains too many end_user_fields (max 100).'));
            }

            $existingFieldSlugs = [];
            foreach ($this->endUserFieldRepository->findByProject($project) as $f) {
                if (!$f->isSystem) {
                    $existingFieldSlugs[$f->slug] = $f;
                }
            }

            foreach ($data['end_user_fields'] as $i => $fieldData) {
                if (!is_array($fieldData) || empty($fieldData['slug']) || !is_string($fieldData['slug'])) {
                    continue;
                }
                $fieldSlug = trim($fieldData['slug']);
                if (!preg_match('/^[a-z0-9_-]+$/i', $fieldSlug) || strlen($fieldSlug) > 128) {
                    continue;
                }

                $slugExists = isset($existingFieldSlugs[$fieldSlug]);

                // EndUserFields are identified by slug (no UUID) — new_uuids behaves like skip
                if ($slugExists && $options->strategy !== 'overwrite') {
                    continue;
                }

                if ($slugExists) {
                    $field = $existingFieldSlugs[$fieldSlug];
                } else {
                    $field = new EndUserField();
                    $field->project = $project;
                    $field->slug = $fieldSlug;
                    $field->isSystem = false;
                    $this->importedEndUserFields[] = $field;
                }

                $rawType = strtolower((string) ($fieldData['type'] ?? 'text'));
                $fieldType = in_array($rawType, self::ALLOWED_FIELD_TYPES, true) ? $rawType : 'text';

                $fieldOptions = is_array($fieldData['options'] ?? null) ? $fieldData['options'] : null;
                if ($fieldOptions !== null && strlen((string) json_encode($fieldOptions)) > 32768) {
                    $fieldOptions = null;
                }

                $fieldName = preg_replace('/[\x00-\x1F\x7F]/', '', (string) ($fieldData['name'] ?? $fieldSlug));
                if (strlen($fieldName) > 255) {
                    $fieldName = substr($fieldName, 0, 255);
                }

                $field->name = $fieldName;
                $field->type = $fieldType;
                $field->options = $fieldOptions;
                $field->order = (int) ($fieldData['order'] ?? $i);
                $field->isRequired = (bool) ($fieldData['is_required'] ?? false);
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
