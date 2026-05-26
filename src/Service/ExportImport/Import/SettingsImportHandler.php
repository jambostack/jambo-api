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
        if (!$data) {
            return;
        }

        if (isset($data['default_locale'])) {
            $project->defaultLocale = $data['default_locale'];
        }
        if (isset($data['locales'])) {
            $project->locales = $data['locales'];
        }
        if (isset($data['public_api'])) {
            $project->publicApi = $data['public_api'];
        }

        // API tokens: re-create with new tokens (hashes can't be exported)
        foreach ($data['api_tokens'] ?? [] as $tokenData) {
            $token = new ApiToken();
            $token->project = $project;
            $token->name = $tokenData['name'];
            $token->abilities = $tokenData['abilities'] ?? [];
        }

        // Webhooks: re-create (secrets can't be exported)
        foreach ($data['webhooks'] ?? [] as $webhookData) {
            $webhook = new Webhook();
            $webhook->project = $project;
            $webhook->name = $webhookData['name'];
            $webhook->url = $webhookData['url'];
            $webhook->events = $webhookData['events'] ?? [];
            $webhook->isActive = $webhookData['is_active'] ?? false;
        }
    }

    public function previewConflicts(Project $project, string $extractedDir): array
    {
        return []; // Settings are always merged, no conflicts
    }
}
