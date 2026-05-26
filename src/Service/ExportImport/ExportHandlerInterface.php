<?php

namespace App\Service\ExportImport;

use App\Entity\Project;

interface ExportHandlerInterface
{
    /**
     * Export data for a given project to a temporary directory.
     * @return array{manifest: array{file: string, entityCount: int}, files: string[]}
     */
    public function export(Project $project, string $tempDir): array;

    /** Returns the option key used in manifest.json and export options (e.g. 'structure', 'content') */
    public static function getOptionKey(): string;
}
