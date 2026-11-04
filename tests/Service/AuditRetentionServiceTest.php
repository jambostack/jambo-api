<?php

namespace App\Tests\Service;

use App\Repository\AuditLogRepository;
use App\Repository\AutomationRunRepository;
use App\Repository\EmailLogRepository;
use App\Repository\WebhookLogRepository;
use App\Service\AuditRetentionService;
use PHPUnit\Framework\TestCase;

class AuditRetentionServiceTest extends TestCase
{
    public function testPurgeLogsCallsRepositoriesWithComputedThresholds(): void
    {
        $auditRepo = $this->createMock(AuditLogRepository::class);
        $webhookRepo = $this->createMock(WebhookLogRepository::class);
        $emailRepo = $this->createMock(EmailLogRepository::class);
        $automationRepo = $this->createMock(AutomationRunRepository::class);

        $auditRepo->expects($this->once())
            ->method('deleteOlderThan')
            ->with($this->callback(function (\DateTimeImmutable $date) {
                $expected = (new \DateTimeImmutable())->modify('-90 days');
                return abs($date->getTimestamp() - $expected->getTimestamp()) < 5;
            }))
            ->willReturn(15);

        $webhookRepo->expects($this->once())
            ->method('deleteOlderThan')
            ->with($this->callback(function (\DateTimeImmutable $date) {
                $expected = (new \DateTimeImmutable())->modify('-30 days');
                return abs($date->getTimestamp() - $expected->getTimestamp()) < 5;
            }))
            ->willReturn(8);

        $emailRepo->expects($this->once())
            ->method('deleteOlderThan')
            ->with($this->callback(function (\DateTimeImmutable $date) {
                $expected = (new \DateTimeImmutable())->modify('-30 days');
                return abs($date->getTimestamp() - $expected->getTimestamp()) < 5;
            }))
            ->willReturn(3);

        $automationRepo->expects($this->once())
            ->method('deleteOlderThan')
            ->with($this->callback(function (\DateTimeImmutable $date) {
                $expected = (new \DateTimeImmutable())->modify('-30 days');
                return abs($date->getTimestamp() - $expected->getTimestamp()) < 5;
            }))
            ->willReturn(4);

        $service = new AuditRetentionService($auditRepo, $webhookRepo, $emailRepo, $automationRepo);
        $res = $service->purgeLogs();

        $this->assertSame(15, $res['audit']);
        $this->assertSame(8, $res['webhook']);
        $this->assertSame(3, $res['email']);
        $this->assertSame(4, $res['automation']);
        $this->assertSame(30, $res['total']);
        $this->assertFalse($res['dry_run']);
    }

    public function testPurgeLogsDryRunCountsWithoutDeleting(): void
    {
        $auditRepo = $this->createMock(AuditLogRepository::class);
        $webhookRepo = $this->createMock(WebhookLogRepository::class);
        $emailRepo = $this->createMock(EmailLogRepository::class);
        $automationRepo = $this->createMock(AutomationRunRepository::class);

        $auditRepo->expects($this->once())->method('countOlderThan')->willReturn(20);
        $webhookRepo->expects($this->once())->method('countOlderThan')->willReturn(10);
        $emailRepo->expects($this->once())->method('countOlderThan')->willReturn(5);
        $automationRepo->expects($this->once())->method('countOlderThan')->willReturn(7);

        $auditRepo->expects($this->never())->method('deleteOlderThan');
        $webhookRepo->expects($this->never())->method('deleteOlderThan');
        $emailRepo->expects($this->never())->method('deleteOlderThan');
        $automationRepo->expects($this->never())->method('deleteOlderThan');

        $service = new AuditRetentionService($auditRepo, $webhookRepo, $emailRepo, $automationRepo);
        $res = $service->purgeLogs(dryRun: true);

        $this->assertSame(20, $res['audit']);
        $this->assertSame(10, $res['webhook']);
        $this->assertSame(5, $res['email']);
        $this->assertSame(7, $res['automation']);
        $this->assertSame(42, $res['total']);
        $this->assertTrue($res['dry_run']);
    }
}
