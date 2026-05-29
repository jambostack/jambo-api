<?php

namespace App\Tests\EventSubscriber;

use App\Entity\SiteDomain;
use App\Entity\WorkbenchProject;
use App\EventSubscriber\SiteHostResolver;
use App\Repository\SiteDomainRepository;
use App\Service\PublishedSiteStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Uid\Uuid;

class SiteHostResolverTest extends TestCase
{
    private function makeEvent(string $host, string $path = '/'): RequestEvent
    {
        $kernel  = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('https://' . $host . $path);
        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function makeResolver(
        ?SiteDomain $domain,
        array $storageMap = [],
    ): SiteHostResolver {
        $repo = $this->createMock(SiteDomainRepository::class);
        $repo->method('findByDomain')->willReturn($domain);

        $storage = $this->createMock(PublishedSiteStorage::class);
        $storage->method('readFile')->willReturnCallback(
            fn ($uuid, $path) => $storageMap[$uuid . ':' . $path] ?? null
        );

        return new SiteHostResolver($repo, $storage);
    }

    private function makeDomain(string $domain, string $uuid = 'test-uuid'): SiteDomain
    {
        $w = new WorkbenchProject();
        $w->uuid      = Uuid::fromString('00000000-0000-4000-8000-000000000001');
        $w->name      = 'test';
        $w->framework = 'nextjs';

        $d = new SiteDomain();
        $d->domain           = $domain;
        $d->workbenchProject = $w;

        return $d;
    }

    public function testUnknownHostDoesNothing(): void
    {
        $resolver = $this->makeResolver(null);
        $event    = $this->makeEvent('unknown.example.com');
        $resolver->onRequest($event);
        $this->assertFalse($event->hasResponse());
    }

    public function testKnownHostServesFile(): void
    {
        $uuid     = '00000000-0000-4000-8000-000000000001';
        $domain   = $this->makeDomain('monsite.com');
        $resolver = $this->makeResolver($domain, [$uuid . ':' . 'index.html' => '<h1>Hi</h1>']);

        $event = $this->makeEvent('monsite.com', '/');
        $resolver->onRequest($event);

        $this->assertTrue($event->hasResponse());
        $this->assertSame(200, $event->getResponse()->getStatusCode());
        $this->assertStringContainsString('<h1>Hi</h1>', $event->getResponse()->getContent());
    }

    public function testMissingFileServesSpaFallback(): void
    {
        $uuid     = '00000000-0000-4000-8000-000000000001';
        $domain   = $this->makeDomain('monsite.com');
        $resolver = $this->makeResolver($domain, [$uuid . ':index.html' => '<html>SPA</html>']);

        $event = $this->makeEvent('monsite.com', '/some/deep/route');
        $resolver->onRequest($event);

        $this->assertTrue($event->hasResponse());
        $this->assertSame(200, $event->getResponse()->getStatusCode());
        $this->assertStringContainsString('SPA', $event->getResponse()->getContent());
    }

    public function testMissingFileAndNoFallbackReturns404(): void
    {
        $domain   = $this->makeDomain('monsite.com');
        $resolver = $this->makeResolver($domain, []);

        $event = $this->makeEvent('monsite.com', '/nope.html');
        $resolver->onRequest($event);

        $this->assertTrue($event->hasResponse());
        $this->assertSame(404, $event->getResponse()->getStatusCode());
    }
}
