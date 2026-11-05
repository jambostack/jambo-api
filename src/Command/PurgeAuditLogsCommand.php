<?php

namespace App\Command;

use App\Repository\ProjectRepository;
use App\Service\AuditRetentionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(
    name: 'app:purge-audit-logs',
    description: 'Purge old audit logs, webhook logs, email logs, and flow automation runs according to retention policies'
)]
class PurgeAuditLogsCommand extends Command
{
    use LockableTrait;
    use CliSecurityTrait;

    public function __construct(
        private readonly AuditRetentionService $retentionService,
        private readonly ?ProjectRepository $projectRepo = null,
        ?TranslatorInterface $translator = null,
    ) {
        parent::__construct();
        $this->setTranslator($translator);
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
                'notification-days',
                null,
                InputOption::VALUE_REQUIRED,
                'Retention window for read notifications in days',
                (string) AuditRetentionService::DEFAULT_NOTIFICATION_DAYS
            )
            ->addOption(
                'project',
                'p',
                InputOption::VALUE_REQUIRED,
                'Scope retention purge to a specific project UUID, numeric ID, or exact name'
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

        if (!$this->lock()) {
            $io->comment($this->trans('cli.command_already_running', [], null, null, 'Command is already running in another process.'));
            return Command::SUCCESS;
        }

        try {
            $projectOption = $input->getOption('project');
            $project = null;

            if ($projectOption !== null && $projectOption !== '') {
                if ($this->projectRepo === null) {
                    $io->error($this->trans('cli.purge_logs.project_not_found', ['%identifier%' => (string) $projectOption], null, null, 'Project repository not available.'));
                    return Command::FAILURE;
                }

                $project = $this->projectRepo->findByIdentifier((string) $projectOption);
                if ($project === null) {
                    $io->error($this->trans('cli.purge_logs.project_not_found', ['%identifier%' => (string) $projectOption], null, null, sprintf("Project '%s' was not found.", $projectOption)));
                    return Command::FAILURE;
                }
            }

            $auditDays        = max(1, (int) $input->getOption('audit-days'));
            $webhookDays      = max(1, (int) $input->getOption('webhook-days'));
            $emailDays        = max(1, (int) $input->getOption('email-days'));
            $automationDays   = max(1, (int) $input->getOption('automation-days'));
            $notificationDays = max(1, (int) $input->getOption('notification-days'));
            $dryRun           = (bool) $input->getOption('dry-run');

            $result = $this->retentionService->purgeLogs(
                auditDays: $auditDays,
                webhookDays: $webhookDays,
                emailDays: $emailDays,
                automationDays: $automationDays,
                dryRun: $dryRun,
                notificationDays: $notificationDays,
                project: $project,
            );

            $prefix = $dryRun
                ? $this->trans('cli.purge_logs.prefix_dry_run', [], null, null, '[DRY RUN] Records eligible for purge')
                : $this->trans('cli.purge_logs.prefix_completed', [], null, null, 'Audit and operational log retention cleanup completed');

            $action = $dryRun
                ? $this->trans('cli.purge_logs.identified', [], null, null, 'identified')
                : $this->trans('cli.purge_logs.purged', [], null, null, 'purged');

            $messages = [$prefix . ':'];

            if ($project !== null) {
                $messages[] = $this->trans('cli.purge_logs.scoped_to_project', [
                    '%name%' => $project->name,
                    '%uuid%' => $project->uuid?->toRfc4122() ?? '',
                ], null, null, sprintf('Scoped to project: %s', $project->name));
            }

            $messages[] = $this->trans('cli.purge_logs.audit_summary', [
                '%days%' => $auditDays,
                '%count%' => $result['audit'],
            ], null, null, sprintf('- Audit logs older than %d days: %d', $auditDays, $result['audit']));

            $messages[] = $this->trans('cli.purge_logs.webhook_summary', [
                '%days%' => $webhookDays,
                '%count%' => $result['webhook'],
            ], null, null, sprintf('- Webhook logs older than %d days: %d', $webhookDays, $result['webhook']));

            $messages[] = $this->trans('cli.purge_logs.email_summary', [
                '%days%' => $emailDays,
                '%count%' => $result['email'],
            ], null, null, sprintf('- Email logs older than %d days: %d', $emailDays, $result['email']));

            $messages[] = $this->trans('cli.purge_logs.automation_summary', [
                '%days%' => $automationDays,
                '%count%' => $result['automation'],
            ], null, null, sprintf('- Automation runs older than %d days: %d', $automationDays, $result['automation']));

            $messages[] = $this->trans('cli.purge_logs.notification_summary', [
                '%days%' => $notificationDays,
                '%count%' => $result['notification'],
            ], null, null, sprintf('- Read notifications older than %d days: %d', $notificationDays, $result['notification']));

            $messages[] = $this->trans('cli.purge_logs.total_summary', [
                '%total%' => $result['total'],
                '%action%' => $action,
            ], null, null, sprintf('- Total: %d records %s', $result['total'], $action));

            $io->success($messages);

            return Command::SUCCESS;
        } finally {
            $this->release();
        }
    }
}
