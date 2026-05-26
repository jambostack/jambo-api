<?php

namespace App\Tests\Service\ExportImport\Export;

use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Enum\ProjectMemberStatus;
use App\Service\ExportImport\Export\SettingsExportHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class SettingsExportHandlerTest extends TestCase
{
    public function testGetOptionKey(): void
    {
        $this->assertSame('settings', SettingsExportHandler::getOptionKey());
    }

    public function testExportWritesSettingsJson(): void
    {
        $handler = new SettingsExportHandler();

        $project = new Project();
        $project->uuid = Uuid::v4();
        $project->defaultLocale = 'fr';
        $project->locales = ['fr', 'en', 'de'];
        $project->publicApi = true;

        $member = new ProjectMember();
        $member->email = 'user@test.com';
        $member->status = ProjectMemberStatus::Active;
        $member->role = null;
        $member->project = $project;

        $project->projectMembers->add($member);

        $tempDir = sys_get_temp_dir() . '/export-test-' . uniqid();
        mkdir($tempDir);

        try {
            $result = $handler->export($project, $tempDir);

            $this->assertFileExists($tempDir . '/settings.json');
            $data = json_decode(file_get_contents($tempDir . '/settings.json'), true);

            $this->assertSame('fr', $data['default_locale']);
            $this->assertSame(['fr', 'en', 'de'], $data['locales']);
            $this->assertTrue($data['public_api']);

            $this->assertCount(1, $data['members']);
            $this->assertSame('user@test.com', $data['members'][0]['email']);
            $this->assertSame('active', $data['members'][0]['status']);

            $this->assertSame('settings.json', $result['manifest']['file']);
        } finally {
            if (file_exists($tempDir . '/settings.json')) {
                unlink($tempDir . '/settings.json');
            }
            rmdir($tempDir);
        }
    }
}
