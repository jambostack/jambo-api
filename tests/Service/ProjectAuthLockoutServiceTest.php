<?php

namespace App\Tests\Service;

use App\Service\ProjectAuthLockoutService;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class ProjectAuthLockoutServiceTest extends TestCase
{
    public function testNotLockedOutByDefault(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn(['attempts' => 0, 'locked_until' => 0]);

        $service = new ProjectAuthLockoutService($cache);
        $this->assertFalse($service->isLockedOut('192.168.1.1'));
        $this->assertEquals(0, $service->getRemainingLockoutSeconds('192.168.1.1'));
    }

    public function testLockedOutWhenLockedUntilInFuture(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn(['attempts' => 5, 'locked_until' => time() + 300]);

        $service = new ProjectAuthLockoutService($cache);
        $this->assertTrue($service->isLockedOut('192.168.1.1'));
        $this->assertGreaterThan(0, $service->getRemainingLockoutSeconds('192.168.1.1'));
    }

    public function testClearAttemptsDeletesKey(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())->method('delete');

        $service = new ProjectAuthLockoutService($cache);
        $service->clearAttempts('192.168.1.1');
    }
}
