<?php

namespace App\Tests\Service;

use App\Entity\Project;
use App\Entity\ProjectStorageProfile;
use App\Service\StorageDriverFactory;
use PHPUnit\Framework\TestCase;

class StorageDriverFactoryTest extends TestCase
{
    private StorageDriverFactory $factory;
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/jambo_test_storage_' . bin2hex(random_bytes(4));
        @mkdir($this->projectDir . '/public/uploads/media', 0777, true);
        $this->factory = new StorageDriverFactory('dummy-secret-key-32-chars-long!!', $this->projectDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
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

    public function testLocalDriverRejectsPathTraversalOutsideAllowedUploads(): void
    {
        $project = new Project();
        $project->uuid = \Symfony\Component\Uid\Uuid::v4();

        $profile = new ProjectStorageProfile();
        $profile->project = $project;
        $profile->driver = 'local';
        $profile->rootPath = $this->projectDir . '/../etc/passwd';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Local storage rootPath must be within the allowed uploads directory.');

        $this->factory->create($profile);
    }

    public function testS3DriverRejectsLocalhostEndpoint(): void
    {
        $project = new Project();
        $profile = new ProjectStorageProfile();
        $profile->project = $project;
        $profile->name = 'TestS3';
        $profile->driver = 's3';
        $profile->s3Endpoint = 'http://localhost:9000';
        $profile->s3Region = 'us-east-1';
        $profile->s3Bucket = 'my-bucket';
        $profile->s3Key = 'key';
        $profile->s3Secret = 'secret';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('targets a forbidden or reserved host');

        $this->factory->getS3Client($profile);
    }

    public function testS3DriverRejectsCloudMetadataEndpoint(): void
    {
        $project = new Project();
        $profile = new ProjectStorageProfile();
        $profile->project = $project;
        $profile->name = 'TestS3';
        $profile->driver = 's3';
        $profile->s3Endpoint = 'http://169.254.169.254/latest/meta-data/';
        $profile->s3Region = 'us-east-1';
        $profile->s3Bucket = 'my-bucket';
        $profile->s3Key = 'key';
        $profile->s3Secret = 'secret';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('targets a forbidden or reserved host');

        $this->factory->getS3Client($profile);
    }

    public function testS3DriverRejectsNonHttpScheme(): void
    {
        $project = new Project();
        $profile = new ProjectStorageProfile();
        $profile->project = $project;
        $profile->name = 'TestS3';
        $profile->driver = 's3';
        $profile->s3Endpoint = 'gopher://127.0.0.1:9000';
        $profile->s3Region = 'us-east-1';
        $profile->s3Bucket = 'my-bucket';
        $profile->s3Key = 'key';
        $profile->s3Secret = 'secret';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('only http and https are allowed');

        $this->factory->getS3Client($profile);
    }
}
