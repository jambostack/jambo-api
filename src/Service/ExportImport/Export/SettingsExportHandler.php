<?php

namespace App\Service\ExportImport\Export;

use App\Entity\Project;
use App\Service\ExportImport\ExportHandlerInterface;

class SettingsExportHandler implements ExportHandlerInterface
{
    public static function getOptionKey(): string
    {
        return 'settings';
    }

    public function export(Project $project, string $tempDir): array
    {
        $members = [];
        foreach ($project->projectMembers as $member) {
            $members[] = [
                'email'  => $member->email,
                'status' => $member->status->value,
                'role'   => $member->role?->id,
                'user'   => $member->user?->id,
            ];
        }

        $data = json_encode([
            'default_locale' => $project->defaultLocale,
            'locales'        => $project->locales,
            'public_api'     => $project->publicApi,
            'members'        => $members,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        file_put_contents($tempDir . '/settings.json', $data);

        return [
            'manifest' => ['file' => 'settings.json', 'entityCount' => count($members)],
            'files'    => ['settings.json'],
        ];
    }
}
