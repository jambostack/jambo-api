<?php

namespace App\Tests\Service\ExportImport\Export;

use App\Entity\AssetMetadata;
use App\Entity\Media;
use App\Entity\Project;
use App\Repository\MediaRepository;
use App\Service\ExportImport\Export\MediaExportHandler;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class MediaExportHandlerTest extends TestCase
{
    public function testGetOptionKey(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $handler = new MediaExportHandler($em, sys_get_temp_dir());
        $this->assertSame('media', MediaExportHandler::getOptionKey());
    }

    public function testExportWritesMediaMetadataAndCopiesFiles(): void
    {
        $projectDir = sys_get_temp_dir();
        $project = new Project();
        $project->uuid = Uuid::v4();

        // Create a dummy file in the project's upload directory
        $uploadDir = $projectDir . '/public/uploads/media/' . $project->uuid->toString();
        mkdir($uploadDir, 0777, true);
        file_put_contents($uploadDir . '/abc123.jpg', 'fake-image-content');

        $media = new Media();
        $media->uuid = Uuid::v4();
        $media->fileName = 'abc123.jpg';
        $media->originalName = 'photo.jpg';
        $media->mimeType = 'image/jpeg';
        $media->fileSize = 12345;
        $media->alt = 'A photo';
        $media->caption = 'Caption text';
        $media->project = $project;

        $metadata = new AssetMetadata();
        $metadata->width = 800;
        $metadata->height = 600;
        $metadata->media = $media;
        $media->metadata = $metadata;

        // Mock the repository to return our Media entity
        $repository = $this->createMock(MediaRepository::class);
        $repository->method('findBy')
            ->with(['project' => $project])
            ->willReturn([$media]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')
            ->with(Media::class)
            ->willReturn($repository);

        $handler = new MediaExportHandler($em, $projectDir);

        $tempDir = sys_get_temp_dir() . '/export-test-' . uniqid();
        mkdir($tempDir);

        try {
            $result = $handler->export($project, $tempDir);

            // Assert media.json was written
            $this->assertFileExists($tempDir . '/media.json');
            $data = json_decode(file_get_contents($tempDir . '/media.json'), true);

            $this->assertCount(1, $data['media']);
            $this->assertSame('photo.jpg', $data['media'][0]['original_name']);
            $this->assertSame('abc123.jpg', $data['media'][0]['file_name']);
            $this->assertSame('image/jpeg', $data['media'][0]['mime_type']);
            $this->assertSame(12345, $data['media'][0]['file_size']);
            $this->assertSame('A photo', $data['media'][0]['alt']);
            $this->assertSame('Caption text', $data['media'][0]['caption']);
            $this->assertSame(800, $data['media'][0]['width']);
            $this->assertSame(600, $data['media'][0]['height']);
            $this->assertSame($media->uuid->toString(), $data['media'][0]['uuid']);

            // Assert manifest structure
            $this->assertSame('media.json', $result['manifest']['file']);
            $this->assertSame(1, $result['manifest']['entityCount']);

            // Assert file was copied to tempDir/media/
            $this->assertFileExists($tempDir . '/media/abc123.jpg');
            $this->assertSame('fake-image-content', file_get_contents($tempDir . '/media/abc123.jpg'));

            // Assert files list includes both json and copied binary
            $this->assertContains('media.json', $result['files']);
            $this->assertContains('media/abc123.jpg', $result['files']);
        } finally {
            // Cleanup temp export directory
            if (file_exists($tempDir . '/media/abc123.jpg')) {
                unlink($tempDir . '/media/abc123.jpg');
            }
            if (is_dir($tempDir . '/media')) {
                rmdir($tempDir . '/media');
            }
            if (file_exists($tempDir . '/media.json')) {
                unlink($tempDir . '/media.json');
            }
            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }

            // Cleanup dummy upload file
            if (file_exists($uploadDir . '/abc123.jpg')) {
                unlink($uploadDir . '/abc123.jpg');
            }
            if (is_dir($uploadDir)) {
                rmdir($uploadDir);
            }
        }
    }

    public function testExportSkipsDeletedMedia(): void
    {
        $projectDir = sys_get_temp_dir();
        $project = new Project();
        $project->uuid = Uuid::v4();

        // Create an active media
        $uploadDir = $projectDir . '/public/uploads/media/' . $project->uuid->toString();
        mkdir($uploadDir, 0777, true);
        file_put_contents($uploadDir . '/active.jpg', 'active-content');

        $active = new Media();
        $active->uuid = Uuid::v4();
        $active->fileName = 'active.jpg';
        $active->originalName = 'active.jpg';
        $active->project = $project;

        // Create a soft-deleted media
        $deleted = new Media();
        $deleted->uuid = Uuid::v4();
        $deleted->deletedAt = new \DateTimeImmutable();
        $deleted->project = $project;

        $repository = $this->createMock(MediaRepository::class);
        $repository->method('findBy')
            ->with(['project' => $project])
            ->willReturn([$active, $deleted]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')
            ->with(Media::class)
            ->willReturn($repository);

        $handler = new MediaExportHandler($em, $projectDir);

        $tempDir = sys_get_temp_dir() . '/export-test-' . uniqid();
        mkdir($tempDir);

        try {
            $result = $handler->export($project, $tempDir);

            $data = json_decode(file_get_contents($tempDir . '/media.json'), true);
            $this->assertCount(1, $data['media']);
            $this->assertSame('active.jpg', $data['media'][0]['original_name']);
            $this->assertSame(1, $result['manifest']['entityCount']);
        } finally {
            if (file_exists($tempDir . '/media/active.jpg')) {
                unlink($tempDir . '/media/active.jpg');
            }
            if (is_dir($tempDir . '/media')) {
                rmdir($tempDir . '/media');
            }
            if (file_exists($tempDir . '/media.json')) {
                unlink($tempDir . '/media.json');
            }
            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
            if (file_exists($uploadDir . '/active.jpg')) {
                unlink($uploadDir . '/active.jpg');
            }
            if (is_dir($uploadDir)) {
                rmdir($uploadDir);
            }
        }
    }

    public function testExportReturnsCorrectManifestForEmptyMedia(): void
    {
        $projectDir = sys_get_temp_dir();
        $project = new Project();
        $project->uuid = Uuid::v4();

        $repository = $this->createMock(MediaRepository::class);
        $repository->method('findBy')
            ->with(['project' => $project])
            ->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')
            ->with(Media::class)
            ->willReturn($repository);

        $handler = new MediaExportHandler($em, $projectDir);

        $tempDir = sys_get_temp_dir() . '/export-test-' . uniqid();
        mkdir($tempDir);

        try {
            $result = $handler->export($project, $tempDir);

            $this->assertFileExists($tempDir . '/media.json');
            $data = json_decode(file_get_contents($tempDir . '/media.json'), true);
            $this->assertCount(0, $data['media']);

            $this->assertSame('media.json', $result['manifest']['file']);
            $this->assertSame(0, $result['manifest']['entityCount']);
            $this->assertContains('media.json', $result['files']);
        } finally {
            if (file_exists($tempDir . '/media.json')) {
                unlink($tempDir . '/media.json');
            }
            if (is_dir($tempDir)) {
                rmdir($tempDir);
            }
        }
    }
}
