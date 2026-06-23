<?php

namespace App\Tests\Enum;

use App\Enum\InsightsRange;
use PHPUnit\Framework\TestCase;

class InsightsRangeTest extends TestCase
{
    public function testFromQueryDefaultsTo30d(): void
    {
        self::assertSame(InsightsRange::D30, InsightsRange::fromQuery(null));
        self::assertSame(InsightsRange::D30, InsightsRange::fromQuery('30d'));
    }

    public function testFromQueryParsesValid(): void
    {
        self::assertSame(InsightsRange::D7, InsightsRange::fromQuery('7d'));
        self::assertSame(InsightsRange::D90, InsightsRange::fromQuery('90d'));
    }

    public function testFromQueryRejectsInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        InsightsRange::fromQuery('42d');
    }

    public function testDaysAndSince(): void
    {
        self::assertSame(7, InsightsRange::D7->days());
        $since = InsightsRange::D7->since();
        $diff = (new \DateTimeImmutable())->diff($since)->days;
        self::assertSame(7, $diff);
    }
}
