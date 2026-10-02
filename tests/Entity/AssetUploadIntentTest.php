<?php

namespace App\Tests\Entity;

use App\Entity\AssetUploadIntent;
use App\Entity\Project;
use PHPUnit\Framework\TestCase;

class AssetUploadIntentTest extends TestCase
{
    public function testInstantiationDefaults(): void
    {
        $intent = new AssetUploadIntent();
        $this->assertNotNull($intent->uuid);
        $this->assertEquals(AssetUploadIntent::STATUS_PENDING, $intent->status);
        $this->assertEquals(AssetUploadIntent::MODE_SINGLE_PUT, $intent->mode);
        $this->assertFalse($intent->isExpired());
    }

    public function testExpirationLogic(): void
    {
        $intent = new AssetUploadIntent();
        $intent->expiresAt = (new \DateTimeImmutable())->modify('-1 hour');
        $this->assertTrue($intent->isExpired());
    }

    public function testToArray(): void
    {
        $project = new Project();
        $intent = new AssetUploadIntent();
        $intent->project = $project;
        $intent->storageKey = 'test/file.mp4';
        $intent->originalFilename = 'file.mp4';
        $intent->clientMimeType = 'video/mp4';
        $intent->maxBytes = 5000000;

        $array = $intent->toArray();
        $this->assertEquals('test/file.mp4', $array['storage_key']);
        $this->assertEquals('file.mp4', $array['original_filename']);
        $this->assertEquals('video/mp4', $array['client_mime_type']);
        $this->assertEquals(5000000, $array['max_bytes']);
    }
}
