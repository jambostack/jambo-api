<?php

namespace App\Command;

use App\Service\AuditRetentionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:purge-audit-logs',
    description: 'Purge old audit logs, webhook logs, email logs, and flow automation runs according to retention policies'
)]
class PurgeAuditLogsCommand extends Command
{
    public function __construct(
        private readonly AuditRetentionService $retentionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'audit-days',
                null,
                InputOption::VALUE_REQUIRED,
                'Retention window for general audit logs in days',
                (string) AuditRetentionService::DEFAULT_AUDIT_DAYS
            )
            ->addOption(
                'webhook-days',
                null,
                InputOption::VALUE_REQUIRED,
                'Retention window for webhook logs in days',
                (string) AuditRetentionService::DEFAULT_WEBHOOK_DAYS
            )
            ->addOption(
                'email-days',
                null,
                InputOption::VALUE_REQUIRED,
                'Retention window for email logs in days',
                (string) AuditRetentionService::DEFAULT_EMAIL_DAYS
            )
            ->addOption(
                'automation-days',
                null,
                InputOption::VALUE_REQUIRED,
                'Retention window for automation runs in days',
                (string) AuditRetentionService::DEFAULT_AUTOMATION_DAYS
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Simulate the purge and report how many records would be deleted without actually deleting them'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $auditDays      = max(1, (int) $input->getOption('audit-days'));
        $webhookDays    = max(1, (int) $input->getOption('webhook-days'));
        $emailDays      = max(1, (int) $input->getOption('email-days'));
        $automationDays = max(1, (int) $input->getOption('automation-days'));
        $dryRun         = (bool) $input->getOption('dry-run');

        $result = $this->retentionService->purgeLogs(
            auditDays: $auditDays,
            webhookDays: $webhookDays,
            emailDays: $emailDays,
            automationDays: $automationDays,
            dryRun: $dryRun
        );

        $prefix = $dryRun ? '[DRY RUN] Records eligible for purge' : 'Audit and log retention cleanup completed';

        $io->success([
            $prefix . ':',
            sprintf('- Audit logs older than %d days: %d', $auditDays, $result['audit']),
            sprintf('- Webhook logs older than %d days: %d', $webhookDays, $result['webhook']),
            sprintf('- Email logs older than %d days: %d', $emailDays, $result['email']),
            sprintf('- Automation runs older than %d days: %d', $automationDays, $result['automation']),
            sprintf('- Total: %d records %s', $result['total'], $dryRun ? 'identified' : 'purged'),
        ]);

        return Command::SUCCESS;
    }
}
