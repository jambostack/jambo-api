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
                'text', 'longtext', 'richtext', 'email', 'slug', 'color', 'password',
                'url', 'markdown', 'code', 'icon', 'uuid'             => $fv->textValue,
                'number', 'rating'                                   => $fv->numberValue,
                'boolean'                                            => $fv->booleanValue,
                'date'                                               => $fv->dateValue?->format('Y-m-d'),
                'datetime'                                           => $fv->datetimeValue?->format(\DateTimeInterface::ATOM),
                'json', 'enumeration', 'repeater', 'tags'            => $fv->jsonValue,
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
