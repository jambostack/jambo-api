<?php

declare(strict_types=1);

namespace App\Tests\Service\Redirect;

use App\Entity\Redirect;
use App\Service\Redirect\RedirectChainDetector;
use PHPUnit\Framework\TestCase;

class RedirectChainDetectorTest extends TestCase
{
    private RedirectChainDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new RedirectChainDetector();
    }

    // ─── detectChains ───────────────────────────────────────────────

    public function testDetectChainsEmptyList(): void
    {
        $chains = $this->detector->detectChains([]);
        $this->assertSame([], $chains);
    }

    public function testDetectChainsSingleRedirectNoChain(): void
    {
        $r = new Redirect();
        $r->fromPath = '/a';
        $r->toPath = '/b';

        $chains = $this->detector->detectChains([$r]);
        $this->assertSame([], $chains);
    }

    public function testDetectChainsTwoHopChain(): void
    {
        $r1 = new Redirect();
        $r1->fromPath = '/a';
        $r1->toPath = '/b';

        $r2 = new Redirect();
        $r2->fromPath = '/b';
        $r2->toPath = '/c';

        $chains = $this->detector->detectChains([$r1, $r2]);

        $this->assertCount(1, $chains);
        $this->assertCount(2, $chains[0]['links']);
        $this->assertSame('/a -> /c', $chains[0]['shortcut']);
    }

    public function testDetectChainsThreeHopChain(): void
    {
        $r1 = new Redirect();
        $r1->fromPath = '/a';
        $r1->toPath = '/b';

        $r2 = new Redirect();
        $r2->fromPath = '/b';
        $r2->toPath = '/c';

        $r3 = new Redirect();
        $r3->fromPath = '/c';
        $r3->toPath = '/d';

        $chains = $this->detector->detectChains([$r1, $r2, $r3]);

        $this->assertCount(1, $chains);
        $this->assertCount(3, $chains[0]['links']);
        $this->assertSame('/a -> /d', $chains[0]['shortcut']);
    }

    public function testDetectChainsMultipleIndependentChains(): void
    {
        $r1 = new Redirect();
        $r1->fromPath = '/a';
        $r1->toPath = '/b';

        $r2 = new Redirect();
        $r2->fromPath = '/b';
        $r2->toPath = '/c';

        $r3 = new Redirect();
        $r3->fromPath = '/x';
        $r3->toPath = '/y';

        $r4 = new Redirect();
        $r4->fromPath = '/y';
        $r4->toPath = '/z';

        $chains = $this->detector->detectChains([$r1, $r2, $r3, $r4]);

        $this->assertCount(2, $chains);
    }

    public function testDetectChainsLoopStops(): void
    {
        $r1 = new Redirect();
        $r1->fromPath = '/a';
        $r1->toPath = '/b';

        $r2 = new Redirect();
        $r2->fromPath = '/b';
        $r2->toPath = '/a';

        $chains = $this->detector->detectChains([$r1, $r2]);

        // Should detect the two-hop chain but stop at the loop
        $this->assertCount(1, $chains);
        $this->assertCount(2, $chains[0]['links']);
    }

    // ─── detectLoop ─────────────────────────────────────────────────

    public function testDetectLoopDirectSelfLoop(): void
    {
        $r = new Redirect();
        $r->fromPath = '/a';
        $r->toPath = '/a';

        $this->assertTrue($this->detector->detectLoop($r));
    }

    public function testDetectLoopNoSelfLoop(): void
    {
        $r = new Redirect();
        $r->fromPath = '/a';
        $r->toPath = '/b';

        $this->assertFalse($this->detector->detectLoop($r));
    }

    // ─── detectLoopWithContext ──────────────────────────────────────

    public function testDetectLoopWithContextIndirectLoop(): void
    {
        $r1 = new Redirect();
        $r1->fromPath = '/a';
        $r1->toPath = '/b';

        $r2 = new Redirect();
        $r2->fromPath = '/b';
        $r2->toPath = '/c';

        $new = new Redirect();
        $new->fromPath = '/c';
        $new->toPath = '/a'; // creates loop: c -> a -> b -> c

        $this->assertTrue($this->detector->detectLoopWithContext($new, [$r1, $r2]));
    }

    public function testDetectLoopWithContextNoLoop(): void
    {
        $r1 = new Redirect();
        $r1->fromPath = '/a';
        $r1->toPath = '/b';

        $new = new Redirect();
        $new->fromPath = '/c';
        $new->toPath = '/d';

        $this->assertFalse($this->detector->detectLoopWithContext($new, [$r1]));
    }

    public function testDetectLoopWithContextDirectSelfLoop(): void
    {
        $new = new Redirect();
        $new->fromPath = '/a';
        $new->toPath = '/a';

        $this->assertTrue($this->detector->detectLoopWithContext($new, []));
    }
}
