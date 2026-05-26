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
