<?php

namespace App\Service\ExportImport;

use App\Dto\ConflictItem;
use App\Dto\ImportOptions;
use App\Entity\Project;

interface ImportHandlerInterface
{
    /**
     * Import data from an extracted ZIP into the given project.
     * @param array<string, string> $uuidMap Maps old UUIDs to new UUIDs (populated by previous handlers)
     */
    public function import(Project $project, string $extractedDir, ImportOptions $options, array &$uuidMap): void;

    /**
     * Preview conflicts without importing.
     * @return ConflictItem[]
     */
    public function previewConflicts(Project $project, string $extractedDir): array;

    /** Returns the option key matching the export handler (e.g. 'structure', 'content') */
    public static function getOptionKey(): string;
}
