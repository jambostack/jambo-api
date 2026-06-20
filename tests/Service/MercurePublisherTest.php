<?php

namespace App\Tests\Service;

use App\Service\MercurePublisher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

class MercurePublisherTest extends TestCase
{
    private string $projectDir;
    private HubInterface&\PHPUnit\Framework\MockObject\MockObject $hub;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/mercure_test_' . bin2hex(random_bytes(4));
        if (!is_dir($this->projectDir . '/var/realtime')) {
            mkdir($this->projectDir . '/var/realtime', 0700, true);
        }
        $this->hub = $this->createMock(HubInterface::class);
    }

    protected function tearDown(): void
    {
        // Nettoyer les fichiers de test
        $realtimeDir = $this->projectDir . '/var/realtime';
        if (is_dir($realtimeDir)) {
            array_map('unlink', glob($realtimeDir . '/*.jsonl'));
            rmdir($realtimeDir);
        }
    }

    // ─── Hub OK → pas d'écriture JSONL ────────────────────────────

    public function testContentChangedPublishesToHubAndDoesNotWriteJsonl(): void
    {
        $publisher = new MercurePublisher($this->projectDir, $this->hub);
        $projectUuid = 'bafb2021-079e-44b0-8fe9-35af563dc2b2';
        $entry = ['uuid' => 'e-1', 'name' => 'Test'];

        $this->hub->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update) use ($projectUuid) {
                return in_array("projects/{$projectUuid}/content", $update->getTopics(), true)
                    && str_contains($update->getData(), 'entry.created');
            }));

        $publisher->contentChanged($projectUuid, 'created', $entry, 'Test');

        $jsonlPath = $this->projectDir . '/var/realtime/' . $projectUuid . '.jsonl';
        $this->assertFileDoesNotExist($jsonlPath);
    }

    // ─── Hub null → fallback JSONL ─────────────────────────────────

    public function testContentChangedFallsBackToJsonlWhenHubIsNull(): void
    {
        $publisher = new MercurePublisher($this->projectDir, null);
        $projectUuid = 'bafb2021-079e-44b0-8fe9-35af563dc2b2';
        $entry = ['uuid' => 'e-2', 'name' => 'Fallback'];

        $publisher->contentChanged($projectUuid, 'created', $entry, 'Fallback');

        $jsonlPath = $this->projectDir . '/var/realtime/' . $projectUuid . '.jsonl';
        $this->assertFileExists($jsonlPath);
        $line = file_get_contents($jsonlPath);
        $this->assertStringContainsString('"entry.created"', $line);
    }

    // ─── Hub lance une exception → fallback JSONL ──────────────────

    public function testContentChangedFallsBackToJsonlWhenHubThrows(): void
    {
        $this->hub->method('publish')->willThrowException(new \RuntimeException('Connection refused'));

        $publisher = new MercurePublisher($this->projectDir, $this->hub);
        $projectUuid = 'bafb2021-079e-44b0-8fe9-35af563dc2b2';
        $entry = ['uuid' => 'e-3', 'name' => 'Error'];

        $publisher->contentChanged($projectUuid, 'updated', $entry, 'Error');

        $jsonlPath = $this->projectDir . '/var/realtime/' . $projectUuid . '.jsonl';
        $this->assertFileExists($jsonlPath);
    }

    // ─── Helpers métier ────────────────────────────────────────────

    public function testMediaUploadedWritesCorrectEvent(): void
    {
        $publisher = new MercurePublisher($this->projectDir, null);
        $projectUuid = 'bafb2021-079e-44b0-8fe9-35af563dc2b2';
        $media = ['original_filename' => 'photo.jpg', 'uuid' => 'm-1'];

        $publisher->mediaUploaded($projectUuid, $media);

        $jsonlPath = $this->projectDir . '/var/realtime/' . $projectUuid . '.jsonl';
        $line = file_get_contents($jsonlPath);
        $this->assertStringContainsString('"media.uploaded"', $line);
        $this->assertStringContainsString('"photo.jpg"', $line);
    }

    public function testMediaDeletedWritesCorrectEvent(): void
    {
        $publisher = new MercurePublisher($this->projectDir, null);
        $projectUuid = 'bafb2021-079e-44b0-8fe9-35af563dc2b2';

        $publisher->mediaDeleted($projectUuid, 'deleted.jpg');

        $jsonlPath = $this->projectDir . '/var/realtime/' . $projectUuid . '.jsonl';
        $line = file_get_contents($jsonlPath);
        $this->assertStringContainsString('"media.deleted"', $line);
        $this->assertStringContainsString('"deleted.jpg"', $line);
    }

    public function testStatusChangedWritesCorrectEvent(): void
    {
        $publisher = new MercurePublisher($this->projectDir, null);
        $projectUuid = 'bafb2021-079e-44b0-8fe9-35af563dc2b2';

        $publisher->statusChanged($projectUuid, 'Mon Article', 'draft', 'published');

        $jsonlPath = $this->projectDir . '/var/realtime/' . $projectUuid . '.jsonl';
        $line = file_get_contents($jsonlPath);
        $this->assertStringContainsString('"status.changed"', $line);
        $this->assertStringContainsString('"Mon Article → published"', $line);
    }

    public function testCreatesRealtimeDirectoryIfNotExists(): void
    {
        $realtimeDir = $this->projectDir . '/var/realtime';
        if (is_dir($realtimeDir)) {
            array_map('unlink', glob($realtimeDir . '/*.jsonl'));
            rmdir($realtimeDir);
        }

        $publisher = new MercurePublisher($this->projectDir, null);
        $publisher->contentChanged('bafb2021-079e-44b0-8fe9-35af563dc2b2', 'created', ['uuid' => 'e-dir'], 'Dir');

        $this->assertDirectoryExists($realtimeDir);
    }
}
