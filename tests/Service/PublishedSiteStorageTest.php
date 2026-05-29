<?php

namespace App\Tests\Service;

use App\Service\PublishedSiteStorage;
use PHPUnit\Framework\TestCase;

class PublishedSiteStorageTest extends TestCase
{
    private string $tmpDir;
    private PublishedSiteStorage $storage;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/jambo_sites_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->storage = new PublishedSiteStorage($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function testWriteAndReadFile(): void
    {
        $this->storage->publish('proj-uuid', ['index.html' => '<h1>Hello</h1>']);
        $this->assertSame('<h1>Hello</h1>', $this->storage->readFile('proj-uuid', 'index.html'));
    }

    public function testPublishReplacesExistingFiles(): void
    {
        $this->storage->publish('proj-uuid', ['old.html' => 'old']);
        $this->storage->publish('proj-uuid', ['new.html' => 'new']);
        $this->assertNull($this->storage->readFile('proj-uuid', 'old.html'));
        $this->assertSame('new', $this->storage->readFile('proj-uuid', 'new.html'));
    }

    public function testReadFileReturnsNullForMissingFile(): void
    {
        $this->assertNull($this->storage->readFile('proj-uuid', 'nope.html'));
    }

    public function testRejectsTraversalPath(): void
    {
        $this->storage->publish('proj-uuid', ['index.html' => 'ok']);
        $this->assertNull($this->storage->readFile('proj-uuid', '../../../etc/passwd'));
    }

    public function testListFilesReturnsRelativePaths(): void
    {
        $this->storage->publish('proj-uuid', [
            'index.html' => 'root',
            'js/app.js'  => 'js',
        ]);
        $files = $this->storage->listFiles('proj-uuid');
        sort($files);
        $this->assertSame(['index.html', 'js/app.js'], $files);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->removeDir($p) : unlink($p);
        }
        rmdir($dir);
    }
}
