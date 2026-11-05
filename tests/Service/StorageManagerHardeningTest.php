<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Project;
use App\Entity\ProjectStorageProfile;
use App\Repository\ProjectStorageProfileRepository;
use App\Repository\StorageRuleRepository;
use App\Service\StorageDriverFactory;
use App\Service\StorageManager;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class StorageManagerHardeningTest extends TestCase
{
    private Project $project;
    private ProjectStorageProfileRepository $profileRepo;
    private StorageRuleRepository $ruleRepo;
    private StorageDriverFactory $factory;
    private StorageManager $manager;

    protected function setUp(): void
    {
        $this->project = new Project();
        $this->project->storageStrategy = 'default_only';

        $this->profileRepo = $this->createStub(ProjectStorageProfileRepository::class);
        $this->ruleRepo = $this->createStub(StorageRuleRepository::class);
        $this->factory = $this->createStub(StorageDriverFactory::class);

        $this->manager = new StorageManager(
            $this->project,
            $this->profileRepo,
            $this->ruleRepo,
            $this->factory
        );
    }

    public function testWriteRejectsPathTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Path traversal attempt detected in storage path');

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, 'test');
        rewind($stream);

        $this->manager->write('../../etc/passwd', $stream);
    }

    public function testWriteRejectsNullBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Null byte detected in storage path');

        $stream = fopen('php://memory', 'r+');
        $this->manager->write("test\0file.png", $stream);
    }

    public function testWriteRejectsOversizedPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Storage path exceeds maximum allowed length');

        $stream = fopen('php://memory', 'r+');
        $longPath = str_repeat('a', 1025);
        $this->manager->write($longPath, $stream);
    }

    public function testGetUrlSanitizesCrlfInjection(): void
    {
        $profile = new ProjectStorageProfile();
        $profileUuid = Uuid::v4();
        $profile->uuid = $profileUuid;
        $profile->driver = 's3';
        $profile->baseUrl = "https://cdn.example.com\r\nX-Injected: attack";

        $this->profileRepo->method('findOneBy')->willReturn($profile);

        $url = $this->manager->getUrl(
            [$profileUuid->toRfc4122() => "uploads/image.png\r\nSet-Cookie: evil=1"],
            $profileUuid->toRfc4122()
        );

        $this->assertNotNull($url);
        $this->assertStringNotContainsString("\r", $url);
        $this->assertStringNotContainsString("\n", $url);
        $this->assertStringContainsString('https://cdn.example.comX-Injected: attack/uploads/image.pngSet-Cookie: evil=1', $url);
    }

    public function testReadRejectsPathTraversalGracefully(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('File not found on any storage');

        $this->manager->read(['profile-1' => '../../secret.txt']);
    }
}
