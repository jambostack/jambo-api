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
        // Map 'new_uuids' strategy to 'new_uuid' action
        $action = $strategy === 'new_uuids' ? 'new_uuid' : $strategy;

        foreach ($conflicts as $item) {
            $item->chosenAction = $action;
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
