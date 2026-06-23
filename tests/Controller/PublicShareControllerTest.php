<?php

namespace App\Tests\Controller;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Project;
use App\Enum\ShareDuration;
use App\Service\Share\ShareService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PublicShareControllerTest extends WebTestCase
{
    private function seedShare(KernelBrowser $client, ShareDuration $duration): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $project = new Project();
        $project->name = 'P ' . bin2hex(random_bytes(4));
        $em->persist($project);
        $collection = new Collection();
        $collection->name = 'Articles';
        $collection->slug = 'articles';
        $collection->project = $project;
        $em->persist($collection);
        $entry = new ContentEntry();
        $entry->project = $project;
        $entry->collection = $collection;
        $entry->status = 'draft';
        $em->persist($entry);
        $em->flush();

        $service = self::getContainer()->get(ShareService::class);
        $res = $service->create($entry, $duration, null);
        return [$res['share'], $res['plainToken'], $entry];
    }

    public function testHtmlView(): void
    {
        $client = static::createClient();
        [, $token] = $this->seedShare($client, ShareDuration::D7);
        $client->request('GET', "/share/$token");
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('text/html', (string) $client->getResponse()->headers->get('Content-Type'));
    }

    public function testJsonView(): void
    {
        $client = static::createClient();
        [, $token] = $this->seedShare($client, ShareDuration::D7);
        $client->request('GET', "/share/$token.json");
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('articles', $body['collection']);
    }

    public function testExpiredReturns410(): void
    {
        $client = static::createClient();
        [$share, $token] = $this->seedShare($client, ShareDuration::D7);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $share->expiresAt = new \DateTimeImmutable('-1 hour');
        $em->flush();
        $client->request('GET', "/share/$token");
        self::assertSame(410, $client->getResponse()->getStatusCode());
    }

    public function testRevokedReturns404(): void
    {
        $client = static::createClient();
        [$share, $token] = $this->seedShare($client, ShareDuration::D7);
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $share->revokedAt = new \DateTimeImmutable();
        $em->flush();
        $client->request('GET', "/share/$token");
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testUnknownReturns404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/share/jbo_share_unknown');
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testViewCountIncremented(): void
    {
        $client = static::createClient();
        [$share, $token] = $this->seedShare($client, ShareDuration::D7);
        $client->request('GET', "/share/$token");
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->refresh($share);
        self::assertSame(1, $share->viewCount);
    }
}
