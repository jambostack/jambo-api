<?php

namespace App\Tests\Service;

use App\Service\TusServer;
use PHPUnit\Framework\TestCase;

class TusServerTest extends TestCase
{
    private string $projectDir;
    private TusServer $server;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/tus_test_' . bin2hex(random_bytes(4));
        $this->server = new TusServer($this->projectDir);
    }

    protected function tearDown(): void
    {
        $tusDir = $this->projectDir . '/var/tus';
        if (is_dir($tusDir)) {
            $this->rmDirRecursive($tusDir);
        }
    }

    private function rmDirRecursive(string $dir): void
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->rmDirRecursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    // ─── Création d'upload ─────────────────────────────────────────

    public function testCreateUploadReturnsUuid(): void
    {
        $metadata = ['filename' => 'photo.jpg', 'filetype' => 'image/jpeg'];
        $uploadId = $this->server->create('bafb2021-079e-44b0-8fe9-35af563dc2b2', 1024, $metadata);

        $this->assertNotEmpty($uploadId);
        $this->assertTrue($this->server->exists('bafb2021-079e-44b0-8fe9-35af563dc2b2', $uploadId));
    }

    public function testCreateUploadStoresMetadata(): void
    {
        $metadata = ['filename' => 'doc.pdf', 'filetype' => 'application/pdf', 'folder_id' => '3'];
        $uploadId = $this->server->create('bafb2021-079e-44b0-8fe9-35af563dc2b2', 2048, $metadata);

        $info = $this->server->getInfo('bafb2021-079e-44b0-8fe9-35af563dc2b2', $uploadId);
        $this->assertIsArray($info);
        $this->assertSame('doc.pdf', $info['metadata']['filename']);
        $this->assertSame(2048, $info['size']);
        $this->assertSame(0, $info['offset']);
        $this->assertFalse($info['finalized']);
    }

    // ─── Patch (écriture de chunks) ────────────────────────────────

    public function testPatchIncrementsOffset(): void
    {
        $metadata = ['filename' => 'chunked.txt'];
        $uploadId = $this->server->create('bafb2021-079e-44b0-8fe9-35af563dc2b2', 12, $metadata);

        $chunk1 = fopen('data:text/plain;base64,' . base64_encode('Hello '), 'rb');
        $offset = $this->server->patch('bafb2021-079e-44b0-8fe9-35af563dc2b2', $uploadId, 0, $chunk1);
        $this->assertSame(6, $offset);

        $chunk2 = fopen('data:text/plain;base64,' . base64_encode('World!'), 'rb');
        $offset = $this->server->patch('bafb2021-079e-44b0-8fe9-35af563dc2b2', $uploadId, 6, $chunk2);
        $this->assertSame(12, $offset);

        $this->assertTrue($this->server->isComplete('bafb2021-079e-44b0-8fe9-35af563dc2b2', $uploadId));
    }

    public function testPatchThrowsForUnknownUpload(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Upload not found');

        $stream = fopen('data:text/plain;base64,' . base64_encode('x'), 'rb');
        $this->server->patch('bafb2021-079e-44b0-8fe9-35af563dc2b2', '00000000-0000-0000-0000-000000000000', 0, $stream);
    }

    // ─── Cancel (annulation) ───────────────────────────────────────

    public function testCancelRemovesUpload(): void
    {
        $uploadId = $this->server->create('bafb2021-079e-44b0-8fe9-35af563dc2b2', 100, ['filename' => 'temp.txt']);
        $this->assertTrue($this->server->exists('bafb2021-079e-44b0-8fe9-35af563dc2b2', $uploadId));

        $this->server->cancel('bafb2021-079e-44b0-8fe9-35af563dc2b2', $uploadId);
        $this->assertFalse($this->server->exists('bafb2021-079e-44b0-8fe9-35af563dc2b2', $uploadId));
    }

    // ─── Sécurité : UUID invalide ──────────────────────────────────

    public function testInvalidProjectUuidThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid project UUID');

        $this->server->create('../etc/passwd', 100, ['filename' => 'evil.txt']);
    }

    // ─── Garbage collect ───────────────────────────────────────────

    public function testGarbageCollectCleansAbandonedUploads(): void
    {
        $uploadId = $this->server->create('bafb2021-079e-44b0-8fe9-35af563dc2b2', 50, ['filename' => 'abandoned.txt']);

        // Simuler un fichier vieux de > 24h (forcer le mtime)
        $infoPath = $this->projectDir . '/var/tus/bafb2021-079e-44b0-8fe9-35af563dc2b2/' . $uploadId . '.info';
        touch($infoPath, time() - 90000);

        $count = $this->server->garbageCollect();
        $this->assertSame(1, $count);
        $this->assertFalse($this->server->exists('bafb2021-079e-44b0-8fe9-35af563dc2b2', $uploadId));
    }
}
