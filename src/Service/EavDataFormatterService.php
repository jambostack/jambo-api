<?php

namespace App\Service;

use App\Entity\ContentEntry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class EavDataFormatterService
{
    public function __construct(
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Formats a ContentEntry and its EAV field values into a flat JSON-friendly array.
     */
    public function formatEntry(ContentEntry $entry): array
    {
        $data = [
            'id'         => $entry->id,
            'uuid'       => $entry->uuid?->toRfc4122(),
            'locale'     => $entry->locale,
            'status'     => $entry->status,
            'collection' => $entry->collection?->slug,
            'created_at' => $entry->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at' => $entry->updatedAt->format(\DateTimeInterface::ATOM),
            'deleted_at' => $entry->deletedAt?->format(\DateTimeInterface::ATOM),
            'creator'    => $entry->createdBy ? ['name' => $entry->createdBy->name ?: $entry->createdBy->email] : null,
            'updater'    => $entry->updatedBy ? ['name' => $entry->updatedBy->name ?: $entry->updatedBy->email] : null,
        ];

        foreach ($entry->fieldValues as $fieldValue) {
            $fieldName = $fieldValue->field?->slug;
            if (!$fieldName) {
                $this->logger->warning('orphan field value detected: ContentFieldValue#{id} has no field relation', [
                    'id' => $fieldValue->id,
                ]);
                continue;
            }

            $value = match ($fieldValue->fieldType) {
                'text', 'textarea', 'richtext', 'wysiwyg', 'markdown',
                'email', 'url', 'color', 'password', 'slug', 'longtext',
                'time'                                                   => $fieldValue->textValue,
                'number', 'decimal'                                      => $fieldValue->numberValue !== null ? (float) $fieldValue->numberValue : null,
                'boolean', 'checkbox'                                    => $fieldValue->booleanValue,
                'date'                                                   => $fieldValue->dateValue?->format('Y-m-d'),
                'datetime'                                               => $fieldValue->datetimeValue?->format(\DateTimeInterface::ATOM),
                'json', 'array', 'repeater',
                'media', 'relation', 'enumeration'                       => $fieldValue->jsonValue,
                default                                                  => $fieldValue->textValue,
            };

            $data[$fieldName] = $value;
        }

        return $data;
    }
}
