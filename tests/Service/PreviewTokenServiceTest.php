<?php

namespace App\Tests\Service;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Project;
use App\Service\PreviewTokenService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

class PreviewTokenServiceTest extends TestCase
{
    private string $appSecret = 'test-secret-key-that-is-at-least-256-bits-long-ok';
    private MockClock $clock;
    private PreviewTokenService $service;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-06-20 12:00:00 UTC');
        $this->service = new PreviewTokenService($this->appSecret, $this->clock);
    }

    private function makeEntry(string $status = 'draft'): ContentEntry
    {
        $project = new Project();
        $project->uuid = Uuid::v5(Uuid::fromString('00000000-0000-0000-0000-000000000000'), 'preview-proj');

        $collection = new Collection();
        $collection->project = $project;
        $collection->slug = 'articles';

        $entry = new ContentEntry();
        $entry->uuid = Uuid::v5(Uuid::fromString('00000000-0000-0000-0000-000000000000'), 'preview-entry');
        $entry->project = $project;
        $entry->collection = $collection;
        $entry->status = $status;

        return $entry;
    }

    // --- Génération de token ---

    public function testCreateTokenGeneratesValidJwt(): void
    {
        $entry = $this->makeEntry();
        $token = $this->service->createToken($entry);

        $this->assertIsString($token);
        // Un JWT a 3 parties séparées par des points
        $this->assertCount(3, explode('.', $token));
    }

    public function testCreateTokenThrowsWithoutEntryUuid(): void
    {
        $entry = $this->makeEntry();
        $entry->uuid = null;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ContentEntry has no UUID');

        $this->service->createToken($entry);
    }

    public function testCreateTokenThrowsWithoutProjectUuid(): void
    {
        $entry = $this->makeEntry();
        $entry->project->uuid = null;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Project has no UUID');

        $this->service->createToken($entry);
    }

    public function testCreateTokenThrowsWithoutCollectionSlug(): void
    {
        $entry = $this->makeEntry();
        $entry->collection = null;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Collection has no slug');

        $this->service->createToken($entry);
    }

    // --- Validation de token ---

    public function testValidateTokenReturnsClaimsForValidToken(): void
    {
        $entry = $this->makeEntry();
        $token = $this->service->createToken($entry);

        $claims = $this->service->validateToken($token);

        $this->assertIsArray($claims);
        $this->assertSame('preview', $claims['sub']);
        $this->assertSame($entry->project->uuid->toRfc4122(), $claims['pid']);
        $this->assertSame($entry->uuid->toRfc4122(), $claims['eid']);
        $this->assertSame('articles', $claims['col']);
        $this->assertSame('draft', $claims['status']);
    }

    public function testValidateTokenReturnsNullForExpiredToken(): void
    {
        $entry = $this->makeEntry();
        $token = $this->service->createToken($entry);

        // Avancer l'horloge de 2 heures pour dépasser le TTL de 1h
        $this->clock->modify('+2 hours');

        $claims = $this->service->validateToken($token);
        $this->assertNull($claims);
    }

    public function testValidateTokenReturnsNullForInvalidToken(): void
    {
        $claims = $this->service->validateToken('not.a.valid.token');
        $this->assertNull($claims);
    }

    public function testValidateTokenReturnsNullForWrongSecret(): void
    {
        $entry = $this->makeEntry();
        $token = $this->service->createToken($entry);

        // Valider avec un service ayant un secret différent
        $otherService = new PreviewTokenService('different_secret_key_32_bytes!!', $this->clock);
        $claims = $otherService->validateToken($token);

        $this->assertNull($claims);
    }
}
