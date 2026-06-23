<?php

namespace App\Tests\Enum;

use App\Enum\ShareDuration;
use PHPUnit\Framework\TestCase;

class ShareDurationTest extends TestCase
{
    public function testFromQueryDefaultsTo7d(): void
    {
        self::assertSame(ShareDuration::D7, ShareDuration::fromQuery(null));
        self::assertSame(ShareDuration::D7, ShareDuration::fromQuery('7d'));
    }

    public function testFromQueryParsesValid(): void
    {
        self::assertSame(ShareDuration::H1, ShareDuration::fromQuery('1h'));
        self::assertSame(ShareDuration::NEVER, ShareDuration::fromQuery('never'));
    }

    public function testFromQueryRejectsInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ShareDuration::fromQuery('3y');
    }

    public function testExpiresAtFrom(): void
    {
        $now = new \DateTimeImmutable('2026-06-23 12:00:00');
        self::assertEquals($now->modify('+1 hour'), ShareDuration::H1->expiresAtFrom($now));
        self::assertEquals($now->modify('+7 days'), ShareDuration::D7->expiresAtFrom($now));
        self::assertNull(ShareDuration::NEVER->expiresAtFrom($now));
    }
}
