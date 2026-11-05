<?php

namespace App\Tests\Command;

use App\Command\PurgeAuditLogsCommand;
use App\Service\AuditRetentionService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class PurgeAuditLogsCommandTest extends TestCase
{
    public function testExecutePurgesAuditLogsAndOperationalRuns(): void
    {
        $service = $this->createMock(AuditRetentionService::class);
        $service->expects($this->once())
            ->method('purgeLogs')
            ->with(
                auditDays: 60,
                webhookDays: 14,
                emailDays: 14,
                automationDays: 14,
                dryRun: false,
                notificationDays: 60,
                project: null
            )
            ->willReturn([
                'audit'        => 10,
                'webhook'      => 5,
                'email'        => 3,
                'automation'   => 8,
                'notification' => 0,
                'total'        => 26,
                'dry_run'      => false,
            ]);

        $command = new PurgeAuditLogsCommand($service);
        $tester = new CommandTester($command);

        $status = $tester->execute([
            '--audit-days'      => '60',
            '--webhook-days'    => '14',
            '--email-days'      => '14',
            '--automation-days' => '14',
        ]);

        $this->assertSame(0, $status);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Audit and operational log retention cleanup completed', $output);
        $this->assertStringContainsString('Audit logs older than 60 days: 10', $output);
        $this->assertStringContainsString('Webhook logs older than 14 days: 5', $output);
        $this->assertStringContainsString('Email logs older than 14 days: 3', $output);
        $this->assertStringContainsString('Automation runs older than 14 days: 8', $output);
        $this->assertStringContainsString('Total: 26 records purged', $output);
    }

    public function testExecuteDryRunDoesNotPurge(): void
    {
        $service = $this->createMock(AuditRetentionService::class);
        $service->expects($this->once())
            ->method('purgeLogs')
            ->with(
                auditDays: 90,
                webhookDays: 30,
                emailDays: 30,
                automationDays: 30,
                dryRun: true,
                notificationDays: 60,
                project: null
            )
            ->willReturn([
                'audit'        => 42,
                'webhook'      => 12,
                'email'        => 7,
                'automation'   => 15,
                'notification' => 0,
                'total'        => 76,
                'dry_run'      => true,
            ]);

        $command = new PurgeAuditLogsCommand($service);
        $tester = new CommandTester($command);

        $status = $tester->execute(['--dry-run' => true]);

        $this->assertSame(0, $status);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('[DRY RUN] Records eligible for purge', $output);
        $this->assertStringContainsString('Total: 76 records identified', $output);
    }
}
