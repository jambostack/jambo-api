<?php

namespace App\Tests\Service\ExportImport\Import;

use App\Dto\ImportOptions;
use App\Entity\Project;
use App\Service\ExportImport\Import\MediaImportHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class MediaImportHandlerTest extends TestCase
{
    public function testGetOptionKey(): void
    {
        $handler = new MediaImportHandler(sys_get_temp_dir());
        $this->assertSame('media', MediaImportHandler::getOptionKey());
    }

    public function testImportCreatesMediaRecordsAndCopiesFile(): void
    {
        $projectDir = sys_get_temp_dir();
        $handler = new MediaImportHandler($projectDir);

        $project = new Project();
        $project->uuid = Uuid::v4();
        $project->name = 'Test';

        $tempDir = sys_get_temp_dir() . '/import-test-' . uniqid();
        mkdir($tempDir);
        mkdir($tempDir . '/media');

        $mediaUuid = Uuid::v4()->toString();
        file_put_contents($tempDir . '/media/test.jpg', 'fake-image-content');

        $mediaData = [
            'media' => [
                [
                    'uuid'          => $mediaUuid,
                    'file_name'     => 'test.jpg',
                    'original_name' => 'photo.jpg',
                    'mime_type'     => 'image/jpeg',
                    'file_size'     => 12345,
                    'alt'           => 'Alt text',
                    'caption'       => null,
                    'width'         => 800,
                    'height'        => 600,
                ],
            ],
        ];
        file_put_contents($tempDir . '/media.json', json_encode($mediaData));

        $targetDir = $projectDir . '/public/uploads/media/' . $project->uuid->toString();

        try {
            $uuidMap = [];
            $options = new ImportOptions();
            $handler->import($project, $tempDir, $options, $uuidMap);

            $this->assertArrayHasKey($mediaUuid, $uuidMap);
            $this->assertFileExists($targetDir . '/test.jpg');
            $this->assertSame('fake-image-content', file_get_contents($targetDir . '/test.jpg'));

            $importedMedia = $handler->getImportedMedia();
            $this->assertCount(1, $importedMedia);
            $media = $importedMedia[0];
            $this->assertSame('test.jpg', $media->fileName);
            $this->assertSame('photo.jpg', $media->originalName);
            $this->assertSame('image/jpeg', $media->mimeType);
            $this->assertSame(12345, $media->fileSize);
            $this->assertSame('Alt text', $media->alt);
            $this->assertNull($media->caption);
            $this->assertNotNull($media->metadata);
            $this->assertSame(800, $media->metadata->width);
            $this->assertSame(600, $media->metadata->height);
        } finally {
            @unlink($tempDir . '/media/test.jpg');
            @unlink($tempDir . '/media.json');
            @rmdir($tempDir . '/media');
            @rmdir($tempDir);
            @unlink($targetDir . '/test.jpg');
            @rmdir($targetDir);
        }
    }

    public function testPreviewConflictsReturnsEmptyWhenNoExistingMedia(): void
    {
        $projectDir = sys_get_temp_dir();
        $handler = new MediaImportHandler($projectDir);

        $project = new Project();
        $project->uuid = Uuid::v4();

        $tempDir = sys_get_temp_dir() . '/import-test-' . uniqid();
        mkdir($tempDir);

        $mediaUuid = Uuid::v4()->toString();
        $mediaData = [
            'media' => [
                ['uuid' => $mediaUuid, 'file_name' => 'test.jpg', 'original_name' => 'photo.jpg'],
                ['uuid' => Uuid::v4()->toString(), 'file_name' => 'other.jpg', 'original_name' => 'other.jpg'],
            ],
        ];
        file_put_contents($tempDir . '/media.json', json_encode($mediaData));

        try {
            $conflicts = $handler->previewConflicts($project, $tempDir);
            $this->assertCount(0, $conflicts);
        } finally {
            unlink($tempDir . '/media.json');
            rmdir($tempDir);
        }
    }

    public function testImportWithSkipStrategy(): void
    {
        $projectDir = sys_get_temp_dir();
        $handler = new MediaImportHandler($projectDir);

        $project = new Project();
        $project->uuid = Uuid::v4();

        $tempDir = sys_get_temp_dir() . '/import-test-' . uniqid();
        mkdir($tempDir);
        mkdir($tempDir . '/media');
        file_put_contents($tempDir . '/media/test.jpg', 'content');

        $mediaUuid = Uuid::v4()->toString();
        $mediaData = [
            'media' => [
                ['uuid' => $mediaUuid, 'file_name' => 'test.jpg', 'original_name' => 'photo.jpg'],
            ],
        ];
        file_put_contents($tempDir . '/media.json', json_encode($mediaData));

        $targetDir = $projectDir . '/public/uploads/media/' . $project->uuid->toString();

        try {
            $uuidMap = [];
            $options = new ImportOptions();
            $options->strategy = 'skip';
            $handler->import($project, $tempDir, $options, $uuidMap);

            $this->assertArrayHasKey($mediaUuid, $uuidMap);
            $this->assertFileExists($targetDir . '/test.jpg');
        } finally {
            @unlink($tempDir . '/media/test.jpg');
            @unlink($tempDir . '/media.json');
            @rmdir($tempDir . '/media');
            @rmdir($tempDir);
            @unlink($targetDir . '/test.jpg');
            @rmdir($targetDir);
        }
    }

    public function testImportWithNewUuidsStrategyGeneratesNewUuids(): void
    {
        $projectDir = sys_get_temp_dir();
        $handler = new MediaImportHandler($projectDir);

        $project = new Project();
        $project->uuid = Uuid::v4();

        $tempDir = sys_get_temp_dir() . '/import-test-' . uniqid();
        mkdir($tempDir);
        mkdir($tempDir . '/media');
        file_put_contents($tempDir . '/media/test.jpg', 'content');

        $oldUuid = Uuid::v4()->toString();
        $mediaData = [
            'media' => [
                ['uuid' => $oldUuid, 'file_name' => 'test.jpg', 'original_name' => 'photo.jpg'],
            ],
        ];
        file_put_contents($tempDir . '/media.json', json_encode($mediaData));

        $targetDir = $projectDir . '/public/uploads/media/' . $project->uuid->toString();

        try {
            $uuidMap = [];
            $options = new ImportOptions();
            $options->strategy = 'new_uuids';
            $handler->import($project, $tempDir, $options, $uuidMap);

            $this->assertArrayHasKey($oldUuid, $uuidMap);
            $newUuid = $uuidMap[$oldUuid];
            $this->assertNotSame($oldUuid, $newUuid);
            $this->assertTrue(Uuid::isValid($newUuid));
            $this->assertFileExists($targetDir . '/test.jpg');
        } finally {
            @unlink($tempDir . '/media/test.jpg');
            @unlink($tempDir . '/media.json');
            @rmdir($tempDir . '/media');
            @rmdir($tempDir);
            @unlink($targetDir . '/test.jpg');
            @rmdir($targetDir);
        }
    }

    public function testImportSkipsWhenMediaJsonMissing(): void
    {
        $handler = new MediaImportHandler(sys_get_temp_dir());

        $project = new Project();
        $project->uuid = Uuid::v4();

        $tempDir = sys_get_temp_dir() . '/import-test-' . uniqid();
        mkdir($tempDir);

        try {
            $uuidMap = [];
            $options = new ImportOptions();
            $handler->import($project, $tempDir, $options, $uuidMap);

            $this->assertEmpty($uuidMap);
            $this->assertEmpty($handler->getImportedMedia());
        } finally {
            rmdir($tempDir);
        }
    }

    public function testImportWithoutMetadataDoesNotCreateAssetMetadata(): void
    {
        $projectDir = sys_get_temp_dir();
        $handler = new MediaImportHandler($projectDir);

        $project = new Project();
        $project->uuid = Uuid::v4();

        $tempDir = sys_get_temp_dir() . '/import-test-' . uniqid();
        mkdir($tempDir);
        mkdir($tempDir . '/media');
        file_put_contents($tempDir . '/media/simple.txt', 'hello');

        $mediaUuid = Uuid::v4()->toString();
        $mediaData = [
            'media' => [
                [
                    'uuid'          => $mediaUuid,
                    'file_name'     => 'simple.txt',
                    'original_name' => 'simple.txt',
                    'mime_type'     => 'text/plain',
                    'file_size'     => 5,
                    'alt'           => null,
                    'caption'       => null,
                ],
            ],
        ];
        file_put_contents($tempDir . '/media.json', json_encode($mediaData));

        $targetDir = $projectDir . '/public/uploads/media/' . $project->uuid->toString();

        try {
            $uuidMap = [];
            $options = new ImportOptions();
            $handler->import($project, $tempDir, $options, $uuidMap);

            $importedMedia = $handler->getImportedMedia();
            $this->assertCount(1, $importedMedia);
            $this->assertNull($importedMedia[0]->metadata);
        } finally {
            @unlink($tempDir . '/media/simple.txt');
            @unlink($tempDir . '/media.json');
            @rmdir($tempDir . '/media');
            @rmdir($tempDir);
            @unlink($targetDir . '/simple.txt');
            @rmdir($targetDir);
        }
    }
}
