<?php

namespace App\Tests\Service;

use App\Entity\Project;
use App\Entity\ProjectMailerSettings;
use App\Repository\ProjectMailerSettingsRepository;
use App\Service\ProjectMailerService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

class ProjectMailerServiceTest extends TestCase
{
    private function makeProject(): Project
    {
        $project = new Project();
        $project->id = 1;
        $project->name = 'Test';
        $project->uuid = Uuid::v4();
        return $project;
    }

    private function makeSettings(Project $project): ProjectMailerSettings
    {
        $s = new ProjectMailerSettings();
        $s->project = $project;
        $s->host = 'smtp.example.com';
        $s->port = 587;
        $s->username = 'user';
        $s->encryptedPassword = '';
        $s->encryption = 'tls';
        $s->fromEmail = 'noreply@example.com';
        $s->fromName = 'Test';
        $s->enabled = true;
        return $s;
    }

    public function testEncryptAndDecryptRoundTrip(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $bus = $this->createMock(MessageBusInterface::class);
        $repo = $this->createMock(ProjectMailerSettingsRepository::class);

        $service = new ProjectMailerService($em, $bus, $repo, 'my-32-byte-secret-key!!');

        $plaintext = 'my-smtp-password-123';
        $encrypted = $service->encryptPassword($plaintext);
        $decrypted = $service->decryptPassword($encrypted);

        $this->assertNotSame($plaintext, $encrypted);
        $this->assertSame($plaintext, $decrypted);
    }
}
