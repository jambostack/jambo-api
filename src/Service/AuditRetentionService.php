<?php

namespace App\Service;

use App\Repository\AuditLogRepository;
use App\Repository\AutomationRunRepository;
use App\Repository\EmailLogRepository;
use App\Repository\NotificationRepository;
use App\Repository\WebhookLogRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service gérant les politiques de rétention et la purge automatique
 * des journaux d'audit, logs de webhooks, logs d'emails, exécutions de flows et notifications lues.
 */
class AuditRetentionService
{
    public const DEFAULT_AUDIT_DAYS = 90;
    public const DEFAULT_WEBHOOK_DAYS = 30;
    public const DEFAULT_EMAIL_DAYS = 30;
    public const DEFAULT_AUTOMATION_DAYS = 30;
    public const DEFAULT_NOTIFICATION_DAYS = 60;

    public function __construct(
        private readonly AuditLogRepository $auditRepo,
        private readonly WebhookLogRepository $webhookRepo,
        private readonly EmailLogRepository $emailRepo,
        private readonly AutomationRunRepository $automationRepo,
        private readonly ?NotificationRepository $notificationRepo = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Purge ou compte (si $dryRun = true) les logs selon les durées de rétention spécifiées.
     *
     * @return array{audit: int, webhook: int, email: int, automation: int, notification: int, total: int, dry_run: bool}
     */
    public function purgeLogs(
        int $auditDays = self::DEFAULT_AUDIT_DAYS,
        int $webhookDays = self::DEFAULT_WEBHOOK_DAYS,
        int $emailDays = self::DEFAULT_EMAIL_DAYS,
        int $automationDays = self::DEFAULT_AUTOMATION_DAYS,
        bool $dryRun = false,
        int $notificationDays = self::DEFAULT_NOTIFICATION_DAYS,
    ): array {
        $now = new \DateTimeImmutable();
        $auditThreshold        = $now->modify(sprintf('-%d days', max(1, $auditDays)));
        $webhookThreshold      = $now->modify(sprintf('-%d days', max(1, $webhookDays)));
        $emailThreshold        = $now->modify(sprintf('-%d days', max(1, $emailDays)));
        $automationThreshold   = $now->modify(sprintf('-%d days', max(1, $automationDays)));
        $notificationThreshold = $now->modify(sprintf('-%d days', max(1, $notificationDays)));

        if ($dryRun) {
            $auditCount        = $this->auditRepo->countOlderThan($auditThreshold);
            $webhookCount      = $this->webhookRepo->countOlderThan($webhookThreshold);
            $emailCount        = $this->emailRepo->countOlderThan($emailThreshold);
            $automationCount   = $this->automationRepo->countOlderThan($automationThreshold);
            $notificationCount = $this->notificationRepo !== null ? $this->notificationRepo->countReadOlderThan($notificationThreshold) : 0;
        } else {
            $auditCount        = $this->auditRepo->deleteOlderThan($auditThreshold);
            $webhookCount      = $this->webhookRepo->deleteOlderThan($webhookThreshold);
            $emailCount        = $this->emailRepo->deleteOlderThan($emailThreshold);
            $automationCount   = $this->automationRepo->deleteOlderThan($automationThreshold);
            $notificationCount = $this->notificationRepo !== null ? $this->notificationRepo->deleteReadOlderThan($notificationThreshold) : 0;

            $this->logger->info('Audit, operational log and notification retention purge executed', [
                'audit_purged'        => $auditCount,
                'webhook_purged'      => $webhookCount,
                'email_purged'        => $emailCount,
                'automation_purged'   => $automationCount,
                'notification_purged' => $notificationCount,
            ]);
        }

        $total = $auditCount + $webhookCount + $emailCount + $automationCount + $notificationCount;

        return [
            'audit'        => $auditCount,
            'webhook'      => $webhookCount,
            'email'        => $emailCount,
            'automation'   => $automationCount,
            'notification' => $notificationCount,
            'total'        => $total,
            'dry_run'      => $dryRun,
        ];
    }
}

