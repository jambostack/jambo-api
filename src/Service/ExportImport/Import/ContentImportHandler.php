<?php

namespace App\Service\ExportImport\Import;

use App\Dto\ConflictItem;
use App\Dto\ImportOptions;
use App\Entity\ContentEntry;
use App\Entity\ContentFieldValue;
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
            'date' => $cfv->dateValue = $value ? new \DateTime($value) : null,
            'datetime' => $cfv->datetimeValue = $value ? new \DateTime($value) : null,
            'json', 'enumeration', 'repeater' => $cfv->jsonValue = $value,
            'media' => null,
            'relation' => null,
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
