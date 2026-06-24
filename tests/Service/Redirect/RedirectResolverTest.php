<?php

declare(strict_types=1);

namespace App\Tests\Service\Redirect;

use App\Entity\Project;
use App\Entity\Redirect;
use App\Repository\RedirectRepository;
use App\Service\Redirect\RedirectResolver;
use PHPUnit\Framework\TestCase;

class RedirectResolverTest extends TestCase
{
    public function testResolveNoActiveRedirects(): void
    {
        $repo = $this->createMock(RedirectRepository::class);
        $repo->method('findActiveByProject')->willReturn([]);

        $resolver = new RedirectResolver($repo);
        $result = $resolver->resolve('/any/path', new Project());

        $this->assertNull($result);
    }

    public function testResolveExactMatch(): void
    {
        $redirect = new Redirect();
        $redirect->fromPath = '/blog/ancien';
        $redirect->toPath = '/blog/nouveau';
        $redirect->httpCode = 301;
        $redirect->isEnabled = true;

        $repo = $this->createMock(RedirectRepository::class);
        $repo->method('findActiveByProject')->willReturn([$redirect]);

        $resolver = new RedirectResolver($repo);
        $result = $resolver->resolve('/blog/ancien', new Project());

        $this->assertNotNull($result);
        $this->assertSame('/blog/nouveau', $result->toPath);
    }

    public function testResolveExactMatchNoMatch(): void
    {
        $redirect = new Redirect();
        $redirect->fromPath = '/blog/ancien';
        $redirect->toPath = '/blog/nouveau';
        $redirect->isEnabled = true;

        $repo = $this->createMock(RedirectRepository::class);
        $repo->method('findActiveByProject')->willReturn([$redirect]);

        $resolver = new RedirectResolver($repo);
        $result = $resolver->resolve('/blog/autre', new Project());

        $this->assertNull($result);
    }

    public function testResolvePatternMatch(): void
    {
        $redirect = new Redirect();
        $redirect->fromPath = '/blog/(.*)';
        $redirect->toPath = '/articles/$1';
        $redirect->httpCode = 301;
        $redirect->isPattern = true;
        $redirect->isEnabled = true;

        $repo = $this->createMock(RedirectRepository::class);
        $repo->method('findActiveByProject')->willReturn([$redirect]);

        $resolver = new RedirectResolver($repo);
        $result = $resolver->resolve('/blog/mon-article', new Project());

        $this->assertNotNull($result);
        // Entity toPath is preserved (not mutated to the resolved value)
        $this->assertSame('/articles/$1', $result->toPath);
    }

    public function testExactMatchTakesPriorityOverPattern(): void
    {
        $pattern = new Redirect();
        $pattern->fromPath = '/blog/(.*)';
        $pattern->toPath = '/articles/$1';
        $pattern->isPattern = true;
        $pattern->isEnabled = true;

        $exact = new Redirect();
        $exact->fromPath = '/blog/mon-article';
        $exact->toPath = '/news/mon-article';
        $exact->isEnabled = true;

        $repo = $this->createMock(RedirectRepository::class);
        $repo->method('findActiveByProject')->willReturn([$pattern, $exact]);

        $resolver = new RedirectResolver($repo);
        $result = $resolver->resolve('/blog/mon-article', new Project());

        $this->assertNotNull($result);
        $this->assertSame('/news/mon-article', $result->toPath);
    }

    public function testResolveChainDetection(): void
    {
        $r1 = new Redirect();
        $r1->fromPath = '/a';
        $r1->toPath = '/b';
        $r1->isEnabled = true;

        $r2 = new Redirect();
        $r2->fromPath = '/b';
        $r2->toPath = '/c';
        $r2->isEnabled = true;

        $repo = $this->createMock(RedirectRepository::class);
        $repo->method('findActiveByProject')->willReturn([$r1, $r2]);

        $resolver = new RedirectResolver($repo);
        $result = $resolver->resolve('/a', new Project());

        $this->assertNotNull($result);
        $this->assertSame('/c', $result->toPath); // final target after chain
    }

    public function testResolveChainWithPattern(): void
    {
        $r1 = new Redirect();
        $r1->fromPath = '/old-(.*)';
        $r1->toPath = '/new-$1';
        $r1->isPattern = true;
        $r1->isEnabled = true;

        $r2 = new Redirect();
        $r2->fromPath = '/new-(.*)';
        $r2->toPath = '/latest-$1';
        $r2->isPattern = true;
        $r2->isEnabled = true;

        $repo = $this->createMock(RedirectRepository::class);
        $repo->method('findActiveByProject')->willReturn([$r1, $r2]);

        $resolver = new RedirectResolver($repo);
        $result = $resolver->resolve('/old-post', new Project());

        $this->assertNotNull($result);
        // Entity toPath is preserved (not mutated to the resolved value)
        $this->assertSame('/latest-$1', $result->toPath);
    }

    public function testResolveLoopDetection(): void
    {
        $r1 = new Redirect();
        $r1->fromPath = '/a';
        $r1->toPath = '/b';
        $r1->isEnabled = true;

        $r2 = new Redirect();
        $r2->fromPath = '/b';
        $r2->toPath = '/a';
        $r2->isEnabled = true;

        $repo = $this->createMock(RedirectRepository::class);
        $repo->method('findActiveByProject')->willReturn([$r1, $r2]);

        $resolver = new RedirectResolver($repo);
        $result = $resolver->resolve('/a', new Project());

        // Loop detected: A->B->A. The resolver returns the last matching redirect
        // before the loop (r2: /b -> /a), with resolved toPath = /a.
        $this->assertNotNull($result);
        $this->assertSame('/a', $result->toPath);
    }

    public function testResolveMaxHopsLimit(): void
    {
        $redirects = [];
        for ($i = 0; $i < 12; ++$i) {
            $r = new Redirect();
            $r->fromPath = "/a{$i}";
            $r->toPath = "/a" . ($i + 1);
            $r->isEnabled = true;
            $redirects[] = $r;
        }

        $repo = $this->createMock(RedirectRepository::class);
        $repo->method('findActiveByProject')->willReturn($redirects);

        $resolver = new RedirectResolver($repo);
        $result = $resolver->resolve('/a0', new Project());

        // Should resolve to the 10th hop (max hops = 10)
        $this->assertNotNull($result);
        $this->assertSame('/a10', $result->toPath);
    }

    public function testResolveIncrementsHits(): void
    {
        $redirect = new Redirect();
        $redirect->fromPath = '/ancien';
        $redirect->toPath = '/nouveau';
        $redirect->isEnabled = true;

        $this->assertSame(0, $redirect->hits);
        $this->assertNull($redirect->lastHitAt);

        $repo = $this->createMock(RedirectRepository::class);
        $repo->method('findActiveByProject')->willReturn([$redirect]);

        $resolver = new RedirectResolver($repo);
        $resolver->resolve('/ancien', new Project());

        $this->assertSame(1, $redirect->hits);
        $this->assertNotNull($redirect->lastHitAt);
    }
}
