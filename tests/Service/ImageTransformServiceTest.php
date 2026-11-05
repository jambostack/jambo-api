<?php

namespace App\Tests\Service;

use App\Service\ImageTransformService;
use PHPUnit\Framework\TestCase;

class ImageTransformServiceTest extends TestCase
{
    private string $tempDir;
    private ImageTransformService $service;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/jambo_img_test_' . bin2hex(random_bytes(6));
        @mkdir($this->tempDir . '/public/uploads/media/cache', 0777, true);
        $this->service = new ImageTransformService($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    public function testRejectsNullByteInSourcePath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->transform("path/to/image.png\0.php", ['w' => 100]);
    }

    public function testRejectsNonExistentSourceFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->transform($this->tempDir . '/does_not_exist.png', ['w' => 100]);
    }

    public function testRejectsInvalidNonImageFile(): void
    {
        $fakeFile = $this->tempDir . '/test.txt';
        file_put_contents($fakeFile, 'Not an image');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->transform($fakeFile, ['w' => 100]);
    }

    public function testRejectsPixelLimitExceededInParams(): void
    {
        // Crée une petite image PNG valide 10x10
        $validPng = $this->tempDir . '/valid.png';
        $im = imagecreatetruecolor(10, 10);
        imagepng($im, $validPng);
        imagedestroy($im);

        // 4000 x 4000 = 16 MP > 12 MP
        $this->expectException(\InvalidArgumentException::class);
        $this->service->transform($validPng, ['w' => 4000, 'h' => 4000]);
    }

    public function testNormalizesParamsAndCachesValidTransform(): void
    {
        $validPng = $this->tempDir . '/valid.png';
        $im = imagecreatetruecolor(50, 50);
        imagecolorallocate($im, 255, 0, 0);
        imagepng($im, $validPng);
        imagedestroy($im);

        $cached = $this->service->transform($validPng, [
            'w' => 20,
            'h' => 20,
            'fit' => 'cover',
            'fmt' => 'png',
            'q' => 90,
        ]);

        $this->assertFileExists($cached);
        $size = getimagesize($cached);
        $this->assertSame(20, $size[0]);
        $this->assertSame(20, $size[1]);

        // Second call should return checked cache path directly
        $cachedAgain = $this->service->transform($validPng, [
            'w' => 20,
            'h' => 20,
            'fit' => 'cover',
            'fmt' => 'png',
            'q' => 90,
        ]);
        $this->assertSame($cached, $cachedAgain);
    }

    public function testPruneCacheDeletesOldFiles(): void
    {
        $cacheDir = $this->service->getCacheDir();
        $oldFile = $cacheDir . '/old.jpg';
        $newFile = $cacheDir . '/new.jpg';

        file_put_contents($oldFile, 'old data');
        file_put_contents($newFile, 'new data');

        touch($oldFile, time() - (40 * 86400)); // 40 jours
        touch($newFile, time() - 3600);          // 1 heure

        $result = $this->service->pruneCache(maxAgeDays: 30);

        $this->assertSame(1, $result['deletedFiles']);
        $this->assertFileDoesNotExist($oldFile);
        $this->assertFileExists($newFile);
    }

    public function testRejectsSvgFile(): void
    {
        $svgFile = $this->tempDir . '/vector.svg';
        file_put_contents($svgFile, '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><circle cx="50" cy="50" r="40" /></svg>');

        $this->expectException(\InvalidArgumentException::class);
        $this->service->transform($svgFile, ['w' => 50]);
    }

    public function testFitContainAndScaleDown(): void
    {
        $validPng = $this->tempDir . '/test_contain.png';
        $im = imagecreatetruecolor(100, 50);
        imagepng($im, $validPng);
        imagedestroy($im);

        $contain = $this->service->transform($validPng, [
            'w' => 40,
            'h' => 40,
            'fit' => 'contain',
        ]);
        $this->assertFileExists($contain);

        $scaled = $this->service->transform($validPng, [
            'w' => 30,
            'fit' => 'scale-down',
        ]);
        $this->assertFileExists($scaled);
    }

    public function testPruneCacheEnforcesMaxSizeBytes(): void
    {
        $cacheDir = $this->service->getCacheDir();
        $file1 = $cacheDir . '/file1.jpg';
        $file2 = $cacheDir . '/file2.jpg';

        file_put_contents($file1, str_repeat('A', 5000));
        file_put_contents($file2, str_repeat('B', 5000));

        touch($file1, time() - 200);
        touch($file2, time() - 100);

        // Max 6000 bytes -> older file1 (5000) should be deleted
        $res = $this->service->pruneCache(maxAgeDays: 30, maxSizeBytes: 6000);
        $this->assertGreaterThanOrEqual(1, $res['deletedFiles']);
        $this->assertFileDoesNotExist($file1);
        $this->assertFileExists($file2);
    }
}
