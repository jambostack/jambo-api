<?php

declare(strict_types=1);

namespace App\Service\Automation;

use App\Entity\Project;
use App\Repository\AutomationRunRepository;

/**
 * Service de contrôle des quotas et limitation de débit d'exécution de flux (AutomationRun).
 *
 * Gère les quotas multi-tenant par tier (free, starter, pro, enterprise) avec support
 * de surcharges spécifiques configurables par projet dans `project.settings`.
 */
class AutomationQuotaService
{
    public const TIER_FREE = 'free';
    public const TIER_STARTER = 'starter';
    public const TIER_PRO = 'pro';
    public const TIER_ENTERPRISE = 'enterprise';

    public const DEFAULT_TIER = self::TIER_FREE;

    /** Quotas journaliers par défaut selon le tier de projet */
    public const TIER_DAILY_LIMITS = [
        self::TIER_FREE       => 100,
        self::TIER_STARTER    => 500,
        self::TIER_PRO        => 2500,
        self::TIER_ENTERPRISE => 50000,
    ];

    public function __construct(
        private readonly AutomationRunRepository $runRepository,
    ) {}

    /**
     * Résout le tier attribué au projet.
     */
    public function getProjectTier(Project $project): string
    {
        $settings = $project->settings ?? [];
        $tier = strtolower((string) ($settings['tier'] ?? $settings['plan'] ?? self::DEFAULT_TIER));

        return array_key_exists($tier, self::TIER_DAILY_LIMITS) ? $tier : self::DEFAULT_TIER;
    }

    /**
     * Détermine la limite journalière d'exécutions d'automatisation pour le projet.
     */
    public function getDailyLimit(Project $project): int
    {
        $settings = $project->settings ?? [];

        // 1. Surcharge explicite dans les paramètres du projet
        if (isset($settings['automation_runs_limit_daily']) && is_numeric($settings['automation_runs_limit_daily'])) {
            $customLimit = (int) $settings['automation_runs_limit_daily'];
            if ($customLimit > 0) {
                return $customLimit;
            }
        }

        if (isset($settings['automation_daily_quota']) && is_numeric($settings['automation_daily_quota'])) {
            $customLimit = (int) $settings['automation_daily_quota'];
            if ($customLimit > 0) {
                return $customLimit;
            }
        }

        // 2. Limite selon le tier
        $tier = $this->getProjectTier($project);
        return self::TIER_DAILY_LIMITS[$tier] ?? self::TIER_DAILY_LIMITS[self::DEFAULT_TIER];
    }

    /**
     * Calcule le nombre d'exécutions d'automatisation déjà consommées aujourd'hui (UTC).
     */
    public function getDailyUsage(Project $project): int
    {
        if ($project->id === null) {
            return 0;
        }

        $startOfDay = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        return $this->runRepository->countRunsForProjectSince($project, $startOfDay);
    }

    /**
     * Vérifie si le projet dispose encore de quota pour exécuter une automatisation.
     *
     * @return array{allowed: bool, limit: int, used: int, tier: string, resetInSeconds: int}
     */
    public function checkQuota(Project $project): array
    {
        $limit = $this->getDailyLimit($project);
        $used = $this->getDailyUsage($project);
        $tier = $this->getProjectTier($project);

        $endOfDay = (new \DateTimeImmutable('tomorrow', new \DateTimeZone('UTC')))->getTimestamp();
        $resetInSeconds = max(1, $endOfDay - time());

        return [
            'allowed'        => $used < $limit,
            'limit'          => $limit,
            'used'           => $used,
            'tier'           => $tier,
            'resetInSeconds' => $resetInSeconds,
        ];
    }

    /**
     * Vérifie rapidement si le quota est dépassé.
     */
    public function isQuotaExceeded(Project $project): bool
    {
        return !$this->checkQuota($project)['allowed'];
    }
}
