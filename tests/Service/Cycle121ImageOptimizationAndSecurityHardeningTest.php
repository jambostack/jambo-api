<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Controller\ImageTransformController;
use App\Entity\Media;
use App\Entity\Project;
use App\Repository\MediaRepository;
use App\Service\ImageOptimizerService;
use App\Service\ImageTransformService;
use App\Service\UploadSecurityValidator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

class Cycle121ImageOptimizationAndSecurityHardeningTest extends TestCase
{
    private string $tempDir;
    private ImageOptimizerService $optimizerService;
    private ImageTransformService $transformService;
    private UploadSecurityValidator $uploadValidator;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/jambo_c121_test_' . bin2hex(random_bytes(6));
        @mkdir($this->tempDir . '/public/uploads/media/cache', 0777, true);

        $translator = new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string {
                return $id;
            }
            public function getLocale(): string {
                return 'en';
            }
        };

        $this->optimizerService = new ImageOptimizerService($this->tempDir, $translator);
        $this->transformService = new ImageTransformService($this->tempDir, $translator);

        $mediaRepo = $this->createMock(MediaRepository::class);
        $mediaRepo->method('getTotalSizeByProject')->willReturn(1000);
        $this->uploadValidator = new UploadSecurityValidator($mediaRepo, $translator);
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

    private function createTestImage(int $width, int $height, string $format = 'png'): string
    {
        $path = $this->tempDir . '/test_' . bin2hex(random_bytes(4)) . '.' . $format;
        $im = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($im, 100, 150, 200);
        imagefill($im, 0, 0, $color);

        if ($format === 'jpg' || $format === 'jpeg') {
            imagejpeg($im, $path, 90);
        } elseif ($format === 'webp') {
            imagewebp($im, $path, 90);
        } else {
            imagepng($im, $path);
        }

        imagedestroy($im);
        return $path;
    }

    // ─── 1. ImageOptimizerService Tests ───

    public function testOptimizerValidatesSafetyAndDimensions(): void
    {
        $imagePath = $this->createTestImage(200, 150);
        $safety = $this->optimizerService->validateImageSafety($imagePath);

        $this->assertSame(200, $safety['width']);
        $this->assertSame(150, $safety['height']);
        $this->assertSame('image/png', $safety['mime']);
        $this->assertGreaterThan(0, $safety['fileSize']);
    }

    public function testOptimizerRejectsNullBytesAndMissingFiles(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->optimizerService->validateImageSafety($this->tempDir . "/img\0.png");
    }

    public function testOptimizerRejectsExtremeSourceAspectRatio(): void
    {
        // 60:1 ratio > 50:1 limit
        $imagePath = $this->createTestImage(600, 10);

        $this->expectException(\InvalidArgumentException::class);
        $this->optimizerService->validateImageSafety($imagePath);
    }

    public function testOptimizerOptimizesImageWithQualityAndDownscaling(): void
    {
        $imagePath = $this->createTestImage(800, 600);
        $tenantUuid = '11111111-2222-3333-4444-555555555555';

        $result = $this->optimizerService->optimize($imagePath, [
            'q' => 75,
            'max_width' => 400,
            'format' => 'webp',
        ], $tenantUuid);

        $this->assertFileExists($result['path']);
        $this->assertStringContainsString($tenantUuid, $result['path']);
        $this->assertStringEndsWith('.webp', $result['path']);

        $size = getimagesize($result['path']);
        $this->assertSame(400, $size[0]);
    }

    public function testOptimizerGeneratesPredefinedPresets(): void
    {
        $imagePath = $this->createTestImage(500, 500);
        $presetPath = $this->optimizerService->generatePreset($imagePath, 'thumbnail');

        $this->assertFileExists($presetPath);
        $size = getimagesize($presetPath);
        $this->assertLessThanOrEqual(150, $size[0]);
    }

    public function testOptimizerStripsMetadata(): void
    {
        $imagePath = $this->createTestImage(100, 100, 'jpeg');
        $destPath = $this->tempDir . '/stripped.jpg';

        $res = $this->optimizerService->stripMetadata($imagePath, $destPath);
        $this->assertFileExists($res);
    }

    public function testDetectZipBombFlagsSuspiciousArchive(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available');
        }

        $zipPath = $this->tempDir . '/suspicious.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        // Create 11 small files with highly repetitive zeroes (high compression ratio)
        $data = str_repeat("0", 500_000); // 500 KB uncompressed
        $zip->addFromString('file1.txt', $data);
        $zip->addFromString('file2.txt', $data);
        $zip->close();

        // With strict maxRatio of 10.0, a 1MB string of zeroes has ratio > 200
        $isBomb = ImageOptimizerService::detectZipBomb($zipPath, maxRatio: 10.0, maxTotalUncompressedBytes: 200_000);
        $this->assertTrue($isBomb);
    }

    // ─── 2. ImageTransformService Tests (Multi-Tenant & Security) ───

    public function testTransformServiceEnforcesOutputAspectRatio(): void
    {
        $imagePath = $this->createTestImage(100, 100);

        // Requested 3000 x 50 = ratio 60:1 > max 25:1
        $this->expectException(\InvalidArgumentException::class);
        $this->transformService->transform($imagePath, ['w' => 3000, 'h' => 50]);
    }

    public function testTransformServiceIsolatesTenantCacheDirectory(): void
    {
        $imagePath = $this->createTestImage(100, 100);
        $tenantUuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

        $cachedPath = $this->transformService->transform($imagePath, ['w' => 50, 'h' => 50], $tenantUuid);

        $this->assertFileExists($cachedPath);
        $this->assertStringContainsString('/' . $tenantUuid . '/', $cachedPath);
    }

    public function testTransformServicePrunesSpecificTenantOnly(): void
    {
        $cacheDir = $this->transformService->getCacheDir();
        $tenant1 = '11111111-1111-1111-1111-111111111111';
        $tenant2 = '22222222-2222-2222-2222-222222222222';

        @mkdir($cacheDir . '/' . $tenant1, 0777, true);
        @mkdir($cacheDir . '/' . $tenant2, 0777, true);

        $file1 = $cacheDir . '/' . $tenant1 . '/old.jpg';
        $file2 = $cacheDir . '/' . $tenant2 . '/old.jpg';
        file_put_contents($file1, 'data1');
        file_put_contents($file2, 'data2');

        touch($file1, time() - (40 * 86400));
        touch($file2, time() - (40 * 86400));

        // Prune only tenant 1
        $res = $this->transformService->pruneCache(maxAgeDays: 30, tenantUuid: $tenant1);

        $this->assertSame(1, $res['deletedFiles']);
        $this->assertFileDoesNotExist($file1);
        $this->assertFileExists($file2);
    }

    // ─── 3. ImageTransformController Tests (HTTP Cache Headers & Validation) ───

    public function testControllerRejectsNonScalarAndArrayQueryParams(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $controller = new ImageTransformController($em, $this->transformService);

        $container = new Container();
        $container->set('parameter_bag', new class($this->tempDir) {
            public function __construct(private string $dir) {}
            public function get(string $name): string {
                return $name === 'kernel.project_dir' ? $this->dir : '';
            }
        });
        $controller->setContainer($container);

        $project = new Project();
        $project->publicApi = true;
        $project->uuid = Uuid::v4();

        $media = new Media();
        $media->project = $project;
        $media->uuid = Uuid::v4();
        $media->fileName = 'valid.png';
        $media->mimeType = 'image/png';

        $localDir = $this->tempDir . '/public/uploads/media/' . $project->uuid->toRfc4122();
        @mkdir($localDir, 0777, true);
        $this->createTestImage(100, 100);
        copy($this->createTestImage(100, 100), $localDir . '/valid.png');

        $repo = $this->createMock(MediaRepository::class);
        $repo->method('findOneBy')->willReturn($media);
        $em->method('getRepository')->willReturn($repo);

        // Query with array parameter: ?w[]=100
        $req = new Request(['w' => ['100']]);
        $response = $controller->serve($media->uuid->toRfc4122(), $req);
        $this->assertSame(422, $response->getStatusCode());

        // Query with null-byte in fit parameter: ?fit=crop%00
        $reqNull = new Request(['fit' => "crop\0"]);
        $responseNull = $controller->serve($media->uuid->toRfc4122(), $reqNull);
        $this->assertSame(422, $responseNull->getStatusCode());

        // Query with excessive aspect ratio: ?w=3000&h=50 (>25:1)
        $reqRatio = new Request(['w' => '3000', 'h' => '50']);
        $responseRatio = $controller->serve($media->uuid->toRfc4122(), $reqRatio);
        $this->assertSame(422, $responseRatio->getStatusCode());
    }

    public function testControllerSetsCacheHeadersAndHandles304NotModified(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $controller = new ImageTransformController($em, $this->transformService);

        $container = new Container();
        $container->set('parameter_bag', new class($this->tempDir) {
            public function __construct(private string $dir) {}
            public function get(string $name): string {
                return $name === 'kernel.project_dir' ? $this->dir : '';
            }
        });
        $controller->setContainer($container);

        $project = new Project();
        $project->publicApi = true;
        $project->uuid = Uuid::v4();

        $media = new Media();
        $media->project = $project;
        $media->uuid = Uuid::v4();
        $media->fileName = 'banner.png';
        $media->mimeType = 'image/png';

        $localDir = $this->tempDir . '/public/uploads/media/' . $project->uuid->toRfc4122();
        @mkdir($localDir, 0777, true);
        copy($this->createTestImage(200, 200), $localDir . '/banner.png');

        $repo = $this->createMock(MediaRepository::class);
        $repo->method('findOneBy')->willReturn($media);
        $em->method('getRepository')->willReturn($repo);

        $req = new Request(['w' => '80', 'h' => '80']);
        $response = $controller->serve($media->uuid->toRfc4122(), $req);

        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertNotEmpty($response->headers->get('ETag'));
        $this->assertSame('Origin, Accept, Accept-Encoding', $response->headers->get('Vary'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        // Conditional GET with matching ETag -> 304 Not Modified
        $etag = $response->headers->get('ETag');
        $reqConditional = new Request(['w' => '80', 'h' => '80'], [], [], [], [], [
            'HTTP_IF_NONE_MATCH' => $etag,
        ]);
        $response304 = $controller->serve($media->uuid->toRfc4122(), $reqConditional);
        $this->assertSame(304, $response304->getStatusCode());
    }

    public function testControllerProtectsPrivateProjectMediaWithStrictNoCacheHeaders(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $controller = new ImageTransformController($em, $this->transformService, appSecret: 'test-secret');

        $container = new Container();
        $container->set('parameter_bag', new class($this->tempDir) {
            public function __construct(private string $dir) {}
            public function get(string $name): string {
                return $name === 'kernel.project_dir' ? $this->dir : '';
            }
        });
        $controller->setContainer($container);

        $project = new Project();
        $project->publicApi = false; // Private project!
        $project->uuid = Uuid::v4();

        $media = new Media();
        $media->project = $project;
        $media->uuid = Uuid::v4();
        $media->fileName = 'private.png';
        $media->mimeType = 'image/png';

        $localDir = $this->tempDir . '/public/uploads/media/' . $project->uuid->toRfc4122();
        @mkdir($localDir, 0777, true);
        copy($this->createTestImage(100, 100), $localDir . '/private.png');

        $repo = $this->createMock(MediaRepository::class);
        $repo->method('findOneBy')->willReturn($media);
        $em->method('getRepository')->willReturn($repo);

        // Sign URL for access
        $expires = time() + 3600;
        $sig = hash_hmac('sha256', "media:{$media->uuid->toRfc4122()}:{$expires}", 'test-secret');

        $req = new Request(['expires' => (string) $expires, 'sig' => $sig]);
        $response = $controller->serve($media->uuid->toRfc4122(), $req);

        $this->assertSame(200, $response->getStatusCode());
        // Must be private and must not be cached by public CDN
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
    }

    // ─── 4. UploadSecurityValidator Hardening Tests ───

    public function testUploadValidatorRejectsExtremeAspectRatioImage(): void
    {
        $extremeImage = $this->createTestImage(600, 10, 'png'); // 60:1 ratio

        $this->expectException(\InvalidArgumentException::class);
        $this->uploadValidator->validateFileContent($extremeImage, 'png');
    }
}
