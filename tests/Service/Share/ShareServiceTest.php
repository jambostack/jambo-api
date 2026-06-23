<?php

namespace App\Tests\Service\Share;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Project;
use App\Enum\ShareDuration;
use App\Service\Share\ShareService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ShareServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ShareService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        // Construction directe : à ce stade ShareService n'est injecté dans aucun
        // contrôleur, le container le prunerait. On l'instancie à la main pour un
        // test robuste (les contrôleurs des tâches 4-5 le câbleront ensuite).
        $secret = self::getContainer()->getParameter('kernel.secret');
        $repo = $this->em->getRepository(\App\Entity\Share::class);
        $this->service = new ShareService($this->em, $repo, $secret);
    }

    private function makeEntry(): ContentEntry
    {
        $project = new Project();
        $project->name = 'Sh ' . bin2hex(random_bytes(4));
        $this->em->persist($project);

        $collection = new Collection();
        $collection->name = 'Articles';
        $collection->slug = 'articles';
        $collection->project = $project;
        $this->em->persist($collection);

        $entry = new ContentEntry();
        $entry->project = $project;
        $entry->collection = $collection;
        $entry->status = 'draft';
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    public function testCreateHashesTokenAndSetsExpiry(): void
    {
        $entry = $this->makeEntry();
        ['share' => $share, 'plainToken' => $plain] = $this->service->create($entry, ShareDuration::D7, null);

        self::assertStringStartsWith('jbo_share_', $plain);
        self::assertNotSame($plain, $share->tokenHash);
        self::assertSame(64, strlen($share->tokenHash));
        self::assertNotNull($share->expiresAt);
        self::assertSame($entry->id, $share->entry->id);
        self::assertSame($entry->project->id, $share->project->id);
    }

    public function testCreateNeverHasNoExpiry(): void
    {
        $entry = $this->makeEntry();
        ['share' => $share] = $this->service->create($entry, ShareDuration::NEVER, null);
        self::assertNull($share->expiresAt);
    }

    public function testResolveValidAndUnknown(): void
    {
        $entry = $this->makeEntry();
        ['plainToken' => $plain] = $this->service->create($entry, ShareDuration::D7, null);

        $resolved = $this->service->resolve($plain);
        self::assertNotNull($resolved);
        self::assertTrue($resolved->isValid());
        self::assertNull($this->service->resolve('jbo_share_does_not_exist'));
    }

    public function testRevokeAndExpiredState(): void
    {
        $entry = $this->makeEntry();
        ['share' => $share, 'plainToken' => $plain] = $this->service->create($entry, ShareDuration::D7, null);

        $this->service->revoke($share);
        $resolved = $this->service->resolve($plain);
        self::assertTrue($resolved->isRevoked());
        self::assertFalse($resolved->isValid());

        // Force expiry
        $share->revokedAt = null;
        $share->expiresAt = new \DateTimeImmutable('-1 hour');
        $this->em->flush();
        self::assertTrue($this->service->resolve($plain)->isExpired());
    }

    public function testRecordAccessIncrementsViewCount(): void
    {
        $entry = $this->makeEntry();
        ['share' => $share, 'plainToken' => $plain] = $this->service->create($entry, ShareDuration::D7, null);
        $this->service->recordAccess($share);

        $resolved = $this->service->resolve($plain);
        self::assertSame(1, $resolved->viewCount);
        self::assertNotNull($resolved->lastAccessedAt);
    }
}
