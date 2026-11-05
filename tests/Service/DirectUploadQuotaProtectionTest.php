<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AssetUploadIntent;
use App\Entity\Project;
use App\Entity\ProjectStorageProfile;
use App\Repository\ProjectStorageProfileRepository;
use App\Service\DirectUploadService;
use App\Service\StorageDriverFactory;
use App\Service\UploadSecurityValidator;
use Aws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class DirectUploadQuotaProtectionTest extends TestCase
{
    private EntityManagerInterface $em;
    private StorageDriverFactory $storageDriverFactory;
    private ProjectStorageProfileRepository $storageProfileRepository;
    private UploadSecurityValidator $uploadValidator;
    private DirectUploadService $service;

    protected function setUp(): void
    {
        $this->em = $this->createStub(EntityManagerInterface::class);
        $this->storageDriverFactory = $this->createStub(StorageDriverFactory::class);
        $this->storageProfileRepository = $this->createStub(ProjectStorageProfileRepository::class);
        $this->uploadValidator = $this->createStub(UploadSecurityValidator::class);

        $this->service = new DirectUploadService(
            $this->em,
            $this->storageDriverFactory,
            $this->storageProfileRepository,
            $this->uploadValidator
        );
    }

    public function testCompleteUploadDetectsOversizedS3ObjectAndThrows(): void
    {
        $project = new Project();
        $profile = new ProjectStorageProfile();
        $profile->driver = 's3';
        $profile->s3Bucket = 'test-bucket';

        $intent = new AssetUploadIntent();
        $intent->project = $project;
        $intent->storageProfile = $profile;
        $intent->storageKey = 'test/video.mp4';
        $intent->originalFilename = 'video.mp4';
        $intent->clientMimeType = 'video/mp4';
        $intent->maxBytes = 10 * 1024 * 1024; // 10 MB limit declared
        $intent->status = AssetUploadIntent::STATUS_PENDING;

        // Test double for S3Client returning 50 MB (exceeds maxBytes)
        $s3Client = new class extends S3Client {
            public function __construct() {}
            public function headObject(array $args = []): array
            {
                return ['ContentLength' => 50 * 1024 * 1024];
            }
        };
        $this->storageDriverFactory->method('getS3Client')->willReturn($s3Client);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Uploaded object on storage exceeds declared maximum size limit.');

        // Client falsely claims 100 bytes
        $this->service->completeUpload($intent, [], 100);
    }

    public function testCreateIntentWithFileSizeValidatesQuota(): void
    {
        $project = new Project();
        $profile = new ProjectStorageProfile();
        $profile->driver = 's3';

        $validatorMock = $this->createMock(UploadSecurityValidator::class);
        $validatorMock->method('sanitizeAndValidateFilename')->willReturn('archive.zip');
        $validatorMock->method('validateMimeType')->willReturn('application/zip');
        $validatorMock->expects($this->once())
            ->method('validateSizeAndQuota')
            ->with($project, 25 * 1024 * 1024);

        $service = new DirectUploadService(
            $this->em,
            $this->storageDriverFactory,
            $this->storageProfileRepository,
            $validatorMock
        );

        $this->storageProfileRepository->method('findBy')->willReturn([$profile]);

        $service->createIntent($project, [
            'filename'  => 'archive.zip',
            'file_size' => 25 * 1024 * 1024,
        ]);
    }
}
