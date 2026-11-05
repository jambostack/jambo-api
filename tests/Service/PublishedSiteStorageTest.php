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

    public function testReadFileWithZeroContent(): void
    {
        // Regression: "0" is falsy in PHP, must not be treated as null.
        $this->storage->publish('proj-uuid', ['zero.js' => '0']);
        $this->assertSame('0', $this->storage->readFile('proj-uuid', 'zero.js'));
    }

    public function testReadFileForUnpublishedProject(): void
    {
        // Répertoire projet inexistant — realpath() échoue, doit retourner null.
        $this->assertNull($this->storage->readFile('never-published', 'index.html'));
    }

    public function testNestedTraversalPath(): void
    {
        // Traversal via sous-répertoire.
        $this->storage->publish('proj-uuid', ['index.html' => 'ok']);
        $this->assertNull($this->storage->readFile('proj-uuid', 'subdir/../../../etc/passwd'));
    }

    public function testPublishRejectsTraversalPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Path traversal attempt detected');
        $this->storage->publish('proj-uuid', ['../../public/shell.php' => '<?php echo "pwned";']);
    }

    public function testPublishRejectsSubdirTraversalPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Path traversal attempt detected');
        $this->storage->publish('proj-uuid', ['assets/../../evil.js' => 'alert(1);']);
    }

    public function testPublishRejectsInvalidProjectUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid project identifier');
        $this->storage->publish('../evil-proj', ['index.html' => 'test']);
    }

    public function testPublishRejectsPhpScript(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Forbidden executable file type detected');
        $this->storage->publish('proj-uuid', ['script.php' => '<?php echo 1;']);
    }

    public function testPublishRejectsPhtmlScript(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Forbidden executable file type detected');
        $this->storage->publish('proj-uuid', ['page.phtml' => '<?php echo 1;']);
    }

    public function testPublishRejectsHtaccess(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Forbidden sensitive file detected');
        $this->storage->publish('proj-uuid', ['.htaccess' => 'RewriteEngine On']);
    }

    public function testPublishRejectsDotEnv(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Forbidden sensitive file detected');
        $this->storage->publish('proj-uuid', ['.env' => 'APP_SECRET=123']);
    }

    public function testPublishRejectsNullByteInFilename(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Null byte detected in relative path');
        $this->storage->publish('proj-uuid', ["index.html\0.txt" => 'hello']);
    }

    public function testPublishRejectsAbsolutePath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Absolute path not allowed');
        $this->storage->publish('proj-uuid', ['/etc/index.html' => 'hello']);
    }

    public function testPublishRejectsTooManyFiles(): void
    {
        $files = [];
        for ($i = 0; $i <= PublishedSiteStorage::MAX_FILES_PER_PUBLICATION; $i++) {
            $files["file{$i}.txt"] = 'content';
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Publication exceeds maximum allowed file count');
        $this->storage->publish('proj-uuid', $files);
    }

    public function testDeleteProjectSafelyCleansUp(): void
    {
        $this->storage->publish('proj-uuid', ['index.html' => 'hello']);
        $this->assertSame('hello', $this->storage->readFile('proj-uuid', 'index.html'));

        $deleted = $this->storage->deleteProject('proj-uuid');
        $this->assertTrue($deleted);
        $this->assertNull($this->storage->readFile('proj-uuid', 'index.html'));
    }

    public function testReadFileWithInvalidProjectUuidReturnsNull(): void
    {
        $this->assertNull($this->storage->readFile('../../../etc', 'passwd'));
    }

    public function testListFilesWithInvalidProjectUuidReturnsEmpty(): void
    {
        $this->assertSame([], $this->storage->listFiles('../../../etc'));
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
