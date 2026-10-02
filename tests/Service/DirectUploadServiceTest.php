<?php

namespace App\Tests\Service;

use App\Entity\AssetUploadIntent;
use App\Entity\Media;
use App\Entity\Project;
use App\Entity\ProjectStorageProfile;
use App\Repository\ProjectStorageProfileRepository;
use App\Service\DirectUploadService;
use App\Service\StorageDriverFactory;
use Aws\CommandInterface;
use Aws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
use Symfony\Component\Uid\Uuid;

class DirectUploadServiceTest extends TestCase
{
    private EntityManagerInterface $em;
    private StorageDriverFactory $storageDriverFactory;
    private ProjectStorageProfileRepository $storageProfileRepository;
    private DirectUploadService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->storageDriverFactory = $this->createMock(StorageDriverFactory::class);
        $this->storageProfileRepository = $this->createMock(ProjectStorageProfileRepository::class);

        $this->service = new DirectUploadService(
            $this->em,
            $this->storageDriverFactory,
            $this->storageProfileRepository
        );
    }

    public function testCreateIntentThrowsOnEmptyFilename(): void
    {
        $project = new Project();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Filename is required.');

        $this->service->createIntent($project, ['filename' => '']);
    }

    public function testCreateIntentThrowsWhenNoS3Profile(): void
    {
        $project = new Project();
        $this->storageProfileRepository->method('findBy')->willReturn([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No active S3 storage profile configured for this project.');

        $this->service->createIntent($project, ['filename' => 'video.mp4']);
    }

    public function testCreateIntentSinglePutSuccess(): void
    {
        $project = new Project();
        $project->uuid = Uuid::v4();

        $profile = new ProjectStorageProfile();
        $profile->driver = 's3';
        $profile->s3Bucket = 'my-bucket';

        $this->storageProfileRepository->method('findBy')->willReturn([$profile]);
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $intent = $this->service->createIntent($project, [
            'filename'  => 'document.pdf',
            'mime_type' => 'application/pdf',
            'max_bytes' => 1048576,
            'mode'      => 'single_put',
        ]);

        $this->assertEquals(AssetUploadIntent::MODE_SINGLE_PUT, $intent->mode);
        $this->assertEquals('document.pdf', $intent->originalFilename);
        $this->assertEquals('application/pdf', $intent->clientMimeType);
        $this->assertStringEndsWith('.pdf', $intent->storageKey);
    }

    public function testGetPresignedPutUrl(): void
    {
        $profile = new ProjectStorageProfile();
        $profile->driver = 's3';
        $profile->s3Bucket = 'bucket';

        $intent = new AssetUploadIntent();
        $intent->storageProfile = $profile;
        $intent->storageKey = 'files/test.pdf';
        $intent->clientMimeType = 'application/pdf';

        $s3Client = $this->createMock(S3Client::class);
        $cmd = $this->createMock(CommandInterface::class);
        $req = $this->createMock(RequestInterface::class);
        $uri = $this->createMock(UriInterface::class);

        $uri->method('__toString')->willReturn('https://s3.amazonaws.com/bucket/files/test.pdf?signature=123');
        $req->method('getUri')->willReturn($uri);
        $s3Client->method('getCommand')->willReturn($cmd);
        $s3Client->method('createPresignedRequest')->willReturn($req);

        $this->storageDriverFactory->method('getS3Client')->willReturn($s3Client);

        $url = $this->service->getPresignedPutUrl($intent);
        $this->assertEquals('https://s3.amazonaws.com/bucket/files/test.pdf?signature=123', $url);
    }

    public function testGetPresignedPartUrlThrowsForNonMultipart(): void
    {
        $intent = new AssetUploadIntent();
        $intent->mode = AssetUploadIntent::MODE_SINGLE_PUT;

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot sign a part for a non-multipart upload intent.');

        $this->service->getPresignedPartUrl($intent, 1);
    }
}
