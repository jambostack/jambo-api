<?php

namespace App\Service\ExportImport\Import;

use App\Dto\ImportOptions;
use App\Entity\ApiToken;
use App\Entity\Project;
use App\Entity\Webhook;
use App\Service\ExportImport\ImportHandlerInterface;

class SettingsImportHandler implements ImportHandlerInterface
{
    public static function getOptionKey(): string
    {
        return 'settings';
    }

    public function import(Project $project, string $extractedDir, ImportOptions $options, array &$uuidMap): void
    {
        $path = $extractedDir . '/settings.json';
        if (!file_exists($path)) {
            return;
        }

        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            return;
        }

        if (isset($data['default_locale']) && is_string($data['default_locale'])) {
            $rawLocale = trim($data['default_locale']);
            if (preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]+)?$/i', $rawLocale) && strlen($rawLocale) <= 10) {
                $project->defaultLocale = strtolower($rawLocale);
            }
        }

        if (isset($data['locales']) && is_array($data['locales'])) {
            $validLocales = [];
            foreach ($data['locales'] as $loc) {
                if (is_string($loc)) {
                    $cleanLoc = trim($loc);
                    if (preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]+)?$/i', $cleanLoc) && strlen($cleanLoc) <= 10) {
                        $validLocales[] = strtolower($cleanLoc);
                        if (count($validLocales) >= 20) {
                            break;
                        }
                    }
                }
            }
            if ($validLocales !== []) {
                $project->locales = array_values(array_unique($validLocales));
            }
        }

        if (isset($data['public_api'])) {
            $project->publicApi = (bool) $data['public_api'];
        }
    }

    public function previewConflicts(Project $project, string $extractedDir): array
    {
        return []; // Settings are always merged, no conflicts
    }
}
