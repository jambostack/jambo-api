<?php

namespace App\Tests\Service\ExportImport\Import;

use App\Dto\ImportOptions;
use App\Entity\Project;
use App\Service\ExportImport\Import\SettingsImportHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class SettingsImportHandlerTest extends TestCase
{
    public function testGetOptionKey(): void
    {
        $this->assertSame('settings', SettingsImportHandler::getOptionKey());
    }

    public function testImportRestoresProjectSettings(): void
    {
        $handler = new SettingsImportHandler();

        $project = new Project();
        $project->uuid = Uuid::v4();
        $project->defaultLocale = 'en';
        $project->locales = ['en'];

        $tempDir = sys_get_temp_dir() . '/import-test-' . uniqid();
        mkdir($tempDir);

        $settingsData = [
            'default_locale' => 'fr',
            'locales'        => ['fr', 'en'],
            'public_api'     => true,
            'members'        => [],
            'api_tokens'     => [],
            'webhooks'       => [],
        ];
        file_put_contents($tempDir . '/settings.json', json_encode($settingsData));

        try {
            $uuidMap = [];
            $options = new ImportOptions();
            $handler->import($project, $tempDir, $options, $uuidMap);

            $this->assertSame('fr', $project->defaultLocale);
            $this->assertSame(['fr', 'en'], $project->locales);
            $this->assertTrue($project->publicApi);
        } finally {
            unlink($tempDir . '/settings.json');
            rmdir($tempDir);
        }
    }

    public function testPreviewConflictsAlwaysReturnsEmpty(): void
    {
        $handler = new SettingsImportHandler();
        $project = new Project();
        $project->uuid = Uuid::v4();
        $project->name = 'Test';

        $tempDir = sys_get_temp_dir() . '/import-test-' . uniqid();
        mkdir($tempDir);
        file_put_contents($tempDir . '/settings.json', json_encode(['locales' => ['en']]));

        try {
            $conflicts = $handler->previewConflicts($project, $tempDir);
            $this->assertCount(0, $conflicts);
        } finally {
            unlink($tempDir . '/settings.json');
            rmdir($tempDir);
        }
    }
}
