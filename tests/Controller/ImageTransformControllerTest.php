<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ImageTransformController;
use App\Entity\Media;
use App\Entity\Project;
use App\Entity\ProjectStorageProfile;
use App\Repository\MediaRepository;
use App\Repository\ProjectStorageProfileRepository;
use App\Service\ImageTransformService;
use App\Service\StorageDriverFactory;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

class ImageTransformControllerTest extends TestCase
{
    private EntityManagerInterface $em;
    private ImageTransformService $imageTransform;
    private StorageDriverFactory $storageDriverFactory;
    private ProjectStorageProfileRepository $storageProfileRepo;
    private MediaRepository $mediaRepo;
    private ImageTransformController $controller;
    private string $tempProjectDir;

    protected function setUp(): void
    {
        $this->tempProjectDir = sys_get_temp_dir() . '/jambo_test_' . bin2hex(random_bytes(4));
        @mkdir($this->tempProjectDir . '/public/uploads/media', 0755, true);
        @mkdir($this->tempProjectDir . '/var/media_cache', 0755, true);

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->imageTransform = $this->createMock(ImageTransformService::class);
        $this->storageDriverFactory = $this->createMock(StorageDriverFactory::class);
        $this->storageProfileRepo = $this->createMock(ProjectStorageProfileRepository::class);
        $this->mediaRepo = $this->createMock(MediaRepository::class);

        $this->em->method('getRepository')->with(Media::class)->willReturn($this->mediaRepo);

        $this->controller = new ImageTransformController(
            $this->em,
            $this->imageTransform,
            $this->storageDriverFactory,
            $this->storageProfileRepo
        );

        $container = new Container();
        $container->set('parameter_bag', new class($this->tempProjectDir) {
            public function __construct(private string $dir) {}
            public function get(string $name): string {
                return $name === 'kernel.project_dir' ? $this->dir : '';
            }
        });
        $tokenStorage = $this->createMock(\Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);
        $container->set('security.token_storage', $tokenStorage);

        $this->controller->setContainer($container);
    }

    protected function tearDown(): void
    {
        // Cleanup temp files
        $this->removeDirectory($this->tempProjectDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!file_exists($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    public function testServeNotFoundWhenMediaDoesNotExist(): void
    {
        $this->mediaRepo->method('findOneBy')->willReturn(null);

        $response = $this->controller->serve('missing-uuid', new Request());
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testServeForbiddenWhenProjectPrivateAndUnauthenticated(): void
    {
        $project = new Project();
        $project->publicApi = false;

        $media = new Media();
        $media->project = $project;
        $media->uuid = Uuid::v4();

        $this->mediaRepo->method('findOneBy')->willReturn($media);

        $response = $this->controller->serve($media->uuid->toRfc4122(), new Request());
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testServeFetchesRemoteS3MediaWhenLocalIsMissing(): void
    {
        $project = new Project();
        $project->publicApi = true;
        $project->uuid = Uuid::v4();

        $profile = new ProjectStorageProfile();
        $profile->uuid = Uuid::v4();
        $profile->driver = 's3';

        $media = new Media();
        $media->project = $project;
        $media->uuid = Uuid::v4();
        $media->fileName = 'remote-banner.png';
        $media->mimeType = 'image/png';
        $media->storageProfile = $profile;
        $media->storagePaths = [
            $profile->uuid->toRfc4122() => 'projects/' . $project->uuid . '/remote-banner.png',
        ];

        $this->mediaRepo->method('findOneBy')->willReturn($media);
        $this->storageProfileRepo->method('findOneBy')->willReturn($profile);

        // Fake image content in memory
        $fakePng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $fakePng);
        rewind($stream);

        $mockFs = $this->createMock(FilesystemOperator::class);
        $mockFs->method('fileExists')->willReturn(true);
        $mockFs->method('readStream')->willReturn($stream);

        $this->storageDriverFactory->expects($this->once())
            ->method('create')
            ->with($profile)
            ->willReturn($mockFs);

        $response = $this->controller->serve($media->uuid->toRfc4122(), new Request());

        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/png', $response->headers->get('Content-Type'));

        // Verify local cached file was written in var/media_cache/
        $cachedPath = $this->tempProjectDir . '/var/media_cache/' . $project->uuid . '/remote-banner.png';
        $this->assertFileExists($cachedPath);
        $this->assertSame($fakePng, file_get_contents($cachedPath));
    }
}
