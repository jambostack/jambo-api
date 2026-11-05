<?php

namespace App\Service\ExportImport\Import;

use App\Dto\ConflictItem;
use App\Dto\ImportOptions;
use App\Entity\ContentEntry;
use App\Entity\ContentFieldValue;
use App\Entity\Project;
use App\Service\ExportImport\ImportHandlerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

class ContentImportHandler implements ImportHandlerInterface
{
    /** @var array<string, \App\Entity\ContentEntry> */
    private array $entryIndex = [];

    public function __construct(
        private ?TranslatorInterface $translator = null,
    ) {}

    public static function getOptionKey(): string
    {
        return 'content';
    }

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters, 'messages');
        }
        return $default ?? $id;
    }

    public function import(Project $project, string $extractedDir, ImportOptions $options, array &$uuidMap): void
    {
        $path = $extractedDir . '/content.json';
        if (!file_exists($path)) {
            return;
        }

        $data = json_decode(file_get_contents($path), true);
        if (!isset($data['entries']) || !is_array($data['entries'])) {
            return;
        }

        if (count($data['entries']) > 10000) {
            throw new \RuntimeException($this->trans('import.content.too_many_entries', ['%max%' => 10000], 'content.json contains too many entries (max 10000).'));
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

                // Always generate fresh UUIDs for a brand-new project to avoid
                // global unique-constraint collisions with the source project
                // or with previous imports of the same archive.
                if ($options->strategy === 'new_uuids' || $options->createNewProject || !$oldUuid) {
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

            $rawLocale = trim((string) ($entryData['locale'] ?? 'en'));
            $entry->locale = (preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]+)?$/i', $rawLocale) && strlen($rawLocale) <= 10) ? $rawLocale : 'en';

            $rawStatus = (string) ($entryData['status'] ?? 'draft');
            $entry->status = in_array($rawStatus, ['draft', 'published', 'archived'], true) ? $rawStatus : 'draft';

            $fieldValues = $entryData['field_values'] ?? [];
            if (!is_array($fieldValues)) {
                $fieldValues = [];
            }

            foreach ($fieldValues as $fvData) {
                if (!is_array($fvData) || empty($fvData['field_slug']) || !is_string($fvData['field_slug'])) {
                    continue;
                }
                $fieldSlug = $fvData['field_slug'];
                $field = $fieldsBySlug[$collectionSlug][$fieldSlug] ?? null;
                if (!$field) {
                    continue;
                }

                $fieldType = is_string($fvData['field_type'] ?? null) ? $fvData['field_type'] : $field->type;
                $cfv = new ContentFieldValue();
                $cfv->field = $field;
                $cfv->fieldType = $fieldType;
                $cfv->contentEntry = $entry;

                $this->setValueOnField($cfv, $fieldType, $fvData['value'] ?? null);
                $entry->fieldValues->add($cfv);
            }
        }
    }

    private function setValueOnField(ContentFieldValue $cfv, string $type, mixed $value): void
    {
        $parseDate = static function (mixed $v): ?\DateTime {
            if (!$v || !is_string($v)) {
                return null;
            }
            try {
                return new \DateTime($v);
            } catch (\Throwable) {
                return null;
            }
        };

        match ($type) {
            'text', 'richtext', 'email', 'slug', 'color', 'password',
            'url', 'code', 'icon', 'uuid'                    => $cfv->textValue = is_string($value) ? (strlen($value) > 65536 ? substr($value, 0, 65536) : $value) : (is_scalar($value) ? (string) $value : null),
            'longtext', 'markdown'                           => $cfv->textValue = is_string($value) ? $value : (is_scalar($value) ? (string) $value : null),
            'number', 'rating'                               => $cfv->numberValue = $value !== null ? (string) $value : null,
            'boolean'                                        => $cfv->booleanValue = (bool) $value,
            'date'                                           => $cfv->dateValue = $parseDate($value),
            'datetime'                                       => $cfv->datetimeValue = $parseDate($value),
            'json', 'enumeration', 'repeater', 'tags'        => $cfv->jsonValue = $value,
            'media' => null,
            'relation' => null,
            default => $cfv->textValue = is_string($value) ? (strlen($value) > 65536 ? substr($value, 0, 65536) : $value) : json_encode($value),
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
