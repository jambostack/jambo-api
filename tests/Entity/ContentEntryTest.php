<?php

namespace App\Tests\Entity;

use App\Entity\ContentEntry;
use PHPUnit\Framework\TestCase;

class ContentEntryTest extends TestCase
{
    public function testConstructInitializesDefaults(): void
    {
        $entry = new ContentEntry();

        $this->assertSame('draft', $entry->status);
        $this->assertSame('en', $entry->locale);
        $this->assertNotNull($entry->createdAt);
        $this->assertNotNull($entry->updatedAt);
        $this->assertNull($entry->deletedAt);
        $this->assertNull($entry->publishedAt);
        $this->assertNull($entry->scheduledAt);
        $this->assertNull($entry->uuid);
        $this->assertNull($entry->id);
        $this->assertNull($entry->project);
        $this->assertNull($entry->collection);
    }

    public function testSetUuidOnPrePersist(): void
    {
        $entry = new ContentEntry();

        $this->assertNull($entry->uuid);

        $entry->setUuidValue();
        $this->assertNotNull($entry->uuid);
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $entry->uuid->toRfc4122());

        $firstUuid = $entry->uuid;
        $entry->setUuidValue();
        $this->assertSame($firstUuid, $entry->uuid);
    }

    public function testStatusChangeToPublishedSetsPublishedAt(): void
    {
        $entry = new ContentEntry();

        $this->assertNull($entry->publishedAt);

        $entry->status = 'published';
        $this->assertNotNull($entry->publishedAt);

        $publishedAt = $entry->publishedAt;

        $entry->status = 'draft';
        $this->assertSame($publishedAt, $entry->publishedAt);
    }

    public function testIsDeleted(): void
    {
        $entry = new ContentEntry();

        $this->assertFalse($entry->isDeleted());

        $entry->deletedAt = new \DateTimeImmutable();
        $this->assertTrue($entry->isDeleted());
    }
}
