<?php

namespace App\Tests\Service;

use App\Command\PurgeAuditLogsCommand;
use App\Entity\Project;
use App\Repository\AuditLogRepository;
use App\Repository\AutomationRunRepository;
use App\Repository\EmailLogRepository;
use App\Repository\NotificationRepository;
use App\Repository\ProjectRepository;
use App\Repository\WebhookLogRepository;
use App\Service\AuditRetentionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

class Cycle82OperationalLogRetentionAndTenantPurgeTest extends TestCase
{
    private function createDummyProject(string $name = 'Tenant-Alpha', ?int $id = 42): Project
    {
        $project = new Project();
        $project->name = $name;
        $project->uuid = Uuid::fromString('00000000-0000-4000-8000-000000000042');
        if ($id !== null) {
            $ref = new \ReflectionProperty(Project::class, 'id');
            $ref->setValue($project, $id);
        }
        return $project;
    }

    public function testTenantScopedPurgePassesProjectToAllRepositories(): void
    {
        $project = $this->createDummyProject();

        $auditRepo = $this->createMock(AuditLogRepository::class);
        $webhookRepo = $this->createMock(WebhookLogRepository::class);
        $emailRepo = $this->createMock(EmailLogRepository::class);
        $automationRepo = $this->createMock(AutomationRunRepository::class);
        $notificationRepo = $this->createMock(NotificationRepository::class);
        $logger = $this->createMock(LoggerInterface::class);

        $auditRepo->expects($this->once())
            ->method('deleteOlderThan')
            ->with($this->isInstanceOf(\DateTimeImmutable::class), $this->identicalTo($project))
            ->willReturn(12);

        $webhookRepo->expects($this->once())
            ->method('deleteOlderThan')
            ->with($this->isInstanceOf(\DateTimeImmutable::class), $this->identicalTo($project))
            ->willReturn(7);

        $emailRepo->expects($this->once())
            ->method('deleteOlderThan')
            ->with($this->isInstanceOf(\DateTimeImmutable::class), $this->identicalTo($project))
            ->willReturn(3);

        $automationRepo->expects($this->once())
            ->method('deleteOlderThan')
            ->with($this->isInstanceOf(\DateTimeImmutable::class), $this->identicalTo($project))
            ->willReturn(5);

        $notificationRepo->expects($this->once())
            ->method('deleteReadOlderThan')
            ->with($this->isInstanceOf(\DateTimeImmutable::class), $this->identicalTo($project))
            ->willReturn(9);

        $logger->expects($this->once())
            ->method('info')
            ->with(
                'Audit, operational log and notification retention purge executed',
                $this->callback(function (array $ctx) use ($project) {
                    return $ctx['audit_purged'] === 12
                        && $ctx['webhook_purged'] === 7
                        && $ctx['email_purged'] === 3
                        && $ctx['automation_purged'] === 5
                        && $ctx['notification_purged'] === 9
                        && $ctx['project_id'] === 42
                        && $ctx['project_uuid'] === $project->uuid->toRfc4122();
                })
            );

        $service = new AuditRetentionService($auditRepo, $webhookRepo, $emailRepo, $automationRepo, $notificationRepo, $logger);

        $res = $service->purgeLogs(
            auditDays: 60,
            webhookDays: 15,
            emailDays: 20,
            automationDays: 10,
            dryRun: false,
            notificationDays: 30,
            project: $project,
        );

        $this->assertSame(12, $res['audit']);
        $this->assertSame(7, $res['webhook']);
        $this->assertSame(3, $res['email']);
        $this->assertSame(5, $res['automation']);
        $this->assertSame(9, $res['notification']);
        $this->assertSame(36, $res['total']);
        $this->assertFalse($res['dry_run']);
        $this->assertSame(42, $res['project_id']);
        $this->assertSame($project->uuid->toRfc4122(), $res['project_uuid']);
    }

    public function testTenantScopedDryRunCountsWithoutDeleting(): void
    {
        $project = $this->createDummyProject();

        $auditRepo = $this->createMock(AuditLogRepository::class);
        $webhookRepo = $this->createMock(WebhookLogRepository::class);
        $emailRepo = $this->createMock(EmailLogRepository::class);
        $automationRepo = $this->createMock(AutomationRunRepository::class);
        $notificationRepo = $this->createMock(NotificationRepository::class);

        $auditRepo->expects($this->once())
            ->method('countOlderThan')
            ->with($this->isInstanceOf(\DateTimeImmutable::class), $this->identicalTo($project))
            ->willReturn(2);

        $webhookRepo->expects($this->once())
            ->method('countOlderThan')
            ->with($this->isInstanceOf(\DateTimeImmutable::class), $this->identicalTo($project))
            ->willReturn(4);

        $emailRepo->expects($this->once())
            ->method('countOlderThan')
            ->with($this->isInstanceOf(\DateTimeImmutable::class), $this->identicalTo($project))
            ->willReturn(1);

        $automationRepo->expects($this->once())
            ->method('countOlderThan')
            ->with($this->isInstanceOf(\DateTimeImmutable::class), $this->identicalTo($project))
            ->willReturn(0);

        $notificationRepo->expects($this->once())
            ->method('countReadOlderThan')
            ->with($this->isInstanceOf(\DateTimeImmutable::class), $this->identicalTo($project))
            ->willReturn(6);

        $auditRepo->expects($this->never())->method('deleteOlderThan');
        $webhookRepo->expects($this->never())->method('deleteOlderThan');
        $emailRepo->expects($this->never())->method('deleteOlderThan');
        $automationRepo->expects($this->never())->method('deleteOlderThan');
        $notificationRepo->expects($this->never())->method('deleteReadOlderThan');

        $service = new AuditRetentionService($auditRepo, $webhookRepo, $emailRepo, $automationRepo, $notificationRepo);

        $res = $service->purgeLogs(dryRun: true, project: $project);

        $this->assertTrue($res['dry_run']);
        $this->assertSame(2, $res['audit']);
        $this->assertSame(4, $res['webhook']);
        $this->assertSame(1, $res['email']);
        $this->assertSame(0, $res['automation']);
        $this->assertSame(6, $res['notification']);
        $this->assertSame(13, $res['total']);
        $this->assertSame(42, $res['project_id']);
    }

    public function testPurgeAuditLogsCommandTenantScopedExecution(): void
    {
        $project = $this->createDummyProject('Workspace-Pro', 101);

        $retentionService = $this->createMock(AuditRetentionService::class);
        $projectRepo = $this->createMock(ProjectRepository::class);
        $translator = $this->createMock(TranslatorInterface::class);

        $translator->method('trans')->willReturnCallback(function (string $id, array $params = []) {
            if ($id === 'cli.purge_logs.scoped_to_project') {
                return "Scoped to project: {$params['%name%']} ({$params['%uuid%']})";
            }
            if ($id === 'cli.purge_logs.prefix_completed') {
                return 'Audit and operational log retention cleanup completed';
            }
            if ($id === 'cli.purge_logs.purged') {
                return 'purged';
            }
            if ($id === 'cli.purge_logs.total_summary') {
                return "- Total: {$params['%total%']} records {$params['%action%']}";
            }
            return $id;
        });

        $projectRepo->expects($this->once())
            ->method('findByIdentifier')
            ->with('Workspace-Pro')
            ->willReturn($project);

        $retentionService->expects($this->once())
            ->method('purgeLogs')
            ->with(
                90,
                30,
                30,
                30,
                false,
                60,
                $this->identicalTo($project)
            )
            ->willReturn([
                'audit' => 10,
                'webhook' => 5,
                'email' => 2,
                'automation' => 1,
                'notification' => 4,
                'total' => 22,
                'dry_run' => false,
                'project_id' => 101,
                'project_uuid' => $project->uuid->toRfc4122(),
            ]);

        $command = new PurgeAuditLogsCommand($retentionService, $projectRepo, $translator);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--project' => 'Workspace-Pro',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Scoped to project: Workspace-Pro', $output);
        $this->assertStringContainsString('Total: 22 records purged', $output);
    }

    public function testPurgeAuditLogsCommandRejectsUnknownProject(): void
    {
        $retentionService = $this->createMock(AuditRetentionService::class);
        $projectRepo = $this->createMock(ProjectRepository::class);
        $translator = $this->createMock(TranslatorInterface::class);

        $translator->method('trans')->willReturnCallback(function (string $id, array $params = []) {
            if ($id === 'cli.purge_logs.project_not_found') {
                return "Project '{$params['%identifier%']}' was not found.";
            }
            return $id;
        });

        $projectRepo->expects($this->once())
            ->method('findByIdentifier')
            ->with('Ghost-Tenant')
            ->willReturn(null);

        $retentionService->expects($this->never())->method('purgeLogs');

        $command = new PurgeAuditLogsCommand($retentionService, $projectRepo, $translator);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--project' => 'Ghost-Tenant',
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString("Project 'Ghost-Tenant' was not found.", $tester->getDisplay());
    }

    public function testPurgeAuditLogsCommandGlobalDryRun(): void
    {
        $retentionService = $this->createMock(AuditRetentionService::class);
        $projectRepo = $this->createMock(ProjectRepository::class);
        $translator = $this->createMock(TranslatorInterface::class);

        $translator->method('trans')->willReturnCallback(function (string $id, array $params = []) {
            if ($id === 'cli.purge_logs.prefix_dry_run') {
                return '[DRY RUN] Records eligible for purge';
            }
            if ($id === 'cli.purge_logs.identified') {
                return 'identified';
            }
            if ($id === 'cli.purge_logs.total_summary') {
                return "- Total: {$params['%total%']} records {$params['%action%']}";
            }
            return $id;
        });

        $projectRepo->expects($this->never())->method('findByIdentifier');

        $retentionService->expects($this->once())
            ->method('purgeLogs')
            ->with(
                45,
                15,
                15,
                15,
                true,
                20,
                null
            )
            ->willReturn([
                'audit' => 100,
                'webhook' => 50,
                'email' => 20,
                'automation' => 10,
                'notification' => 30,
                'total' => 210,
                'dry_run' => true,
                'project_id' => null,
                'project_uuid' => null,
            ]);

        $command = new PurgeAuditLogsCommand($retentionService, $projectRepo, $translator);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--audit-days' => '45',
            '--webhook-days' => '15',
            '--email-days' => '15',
            '--automation-days' => '15',
            '--notification-days' => '20',
            '--dry-run' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('[DRY RUN] Records eligible for purge', $output);
        $this->assertStringContainsString('Total: 210 records identified', $output);
    }

    public function testProjectRepositoryFindByIdentifierRouting(): void
    {
        $registry = $this->createMock(\Doctrine\Persistence\ManagerRegistry::class);
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $classMetadata = new \Doctrine\ORM\Mapping\ClassMetadata(Project::class);

        $registry->method('getManagerForClass')->willReturn($em);
        $em->method('getClassMetadata')->willReturn($classMetadata);

        $repo = $this->getMockBuilder(ProjectRepository::class)
            ->setConstructorArgs([$registry])
            ->onlyMethods(['findOneBy', 'find'])
            ->getMock();

        $project = $this->createDummyProject('Workspace-Alpha', 99);

        // 1. Empty string returns null immediately without DB query
        $this->assertNull($repo->findByIdentifier('   '));

        // 2. UUID string calls findOneBy with UUID object
        $uuidStr = '00000000-0000-4000-8000-000000000042';
        $repo->expects($this->once())
            ->method('findOneBy')
            ->with($this->callback(function (array $criteria) use ($uuidStr) {
                return isset($criteria['uuid'])
                    && $criteria['uuid'] instanceof Uuid
                    && $criteria['uuid']->toRfc4122() === $uuidStr;
            }))
            ->willReturn($project);

        $this->assertSame($project, $repo->findByIdentifier($uuidStr));

        // 3. Numeric ID calls find
        $repo2 = $this->getMockBuilder(ProjectRepository::class)
            ->setConstructorArgs([$registry])
            ->onlyMethods(['findOneBy', 'find'])
            ->getMock();

        $repo2->expects($this->once())
            ->method('find')
            ->with(99)
            ->willReturn($project);

        $this->assertSame($project, $repo2->findByIdentifier('99'));

        // 4. Exact name calls findOneBy with name
        $repo3 = $this->getMockBuilder(ProjectRepository::class)
            ->setConstructorArgs([$registry])
            ->onlyMethods(['findOneBy', 'find'])
            ->getMock();

        $repo3->expects($this->once())
            ->method('findOneBy')
            ->with(['name' => 'Workspace-Alpha'])
            ->willReturn($project);

        $this->assertSame($project, $repo3->findByIdentifier('Workspace-Alpha'));
    }

    public function testStrictI18nCompletenessAcrossAllLanguages(): void
    {
        $en = require __DIR__ . '/../../translations/messages.en.php';
        $fr = require __DIR__ . '/../../translations/messages.fr.php';
        $es = require __DIR__ . '/../../translations/messages.es.php';
        $ar = require __DIR__ . '/../../translations/messages.ar.php';

        $keys = [
            'cli.purge_logs.description',
            'cli.purge_logs.project_not_found',
            'cli.purge_logs.prefix_dry_run',
            'cli.purge_logs.prefix_completed',
            'cli.purge_logs.scoped_to_project',
            'cli.purge_logs.audit_summary',
            'cli.purge_logs.webhook_summary',
            'cli.purge_logs.email_summary',
            'cli.purge_logs.automation_summary',
            'cli.purge_logs.notification_summary',
            'cli.purge_logs.total_summary',
            'cli.purge_logs.identified',
            'cli.purge_logs.purged',
        ];

        foreach ($keys as $k) {
            $this->assertArrayHasKey($k, $en, "Missing English key {$k}");
            $this->assertArrayHasKey($k, $fr, "Missing French key {$k}");
            $this->assertArrayHasKey($k, $es, "Missing Spanish key {$k}");
            $this->assertArrayHasKey($k, $ar, "Missing Arabic key {$k}");

            $this->assertNotEmpty($en[$k], "Empty English value for {$k}");
            $this->assertNotEmpty($fr[$k], "Empty French value for {$k}");
            $this->assertNotEmpty($es[$k], "Empty Spanish value for {$k}");
            $this->assertNotEmpty($ar[$k], "Empty Arabic value for {$k}");
        }
    }
}
