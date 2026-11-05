<?php

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class ProjectAuthLockoutService
{
    public const MAX_ATTEMPTS = 5;
    public const LOCKOUT_DURATION = 900; // 15 minutes en secondes

    // Multi-tier lockout thresholds and durations (OWASP ASVS / NIST SP 800-63B)
    public const TIER_1_THRESHOLD = 5;
    public const TIER_1_DURATION = 300;   // 5 minutes
    public const TIER_2_THRESHOLD = 10;
    public const TIER_2_DURATION = 900;   // 15 minutes
    public const TIER_3_THRESHOLD = 15;
    public const TIER_3_DURATION = 3600;  // 1 hour

    public function __construct(
        private readonly CacheInterface $cache,
    ) {}

    public function isLockedOut(string $identifier): bool
    {
        $key = $this->buildKey($identifier);
        $data = $this->cache->get($key, fn() => ['attempts' => 0, 'locked_until' => 0]);

        if (($data['locked_until'] ?? 0) > time()) {
            return true;
        }

        return false;
    }

    public function getRemainingLockoutSeconds(string $identifier): int
    {
        $key = $this->buildKey($identifier);
        $data = $this->cache->get($key, fn() => ['attempts' => 0, 'locked_until' => 0]);

        $remaining = ($data['locked_until'] ?? 0) - time();
        return max(0, $remaining);
    }

    public function recordFailedAttempt(string $identifier, int $maxAttempts = self::MAX_ATTEMPTS, int $lockoutDuration = self::LOCKOUT_DURATION): int
    {
        $key = $this->buildKey($identifier);
        $current = $this->cache->get($key, fn() => ['attempts' => 0, 'locked_until' => 0]);
        $attempts = ((int) ($current['attempts'] ?? 0)) + 1;
        $lockedUntil = $attempts >= $maxAttempts ? time() + $lockoutDuration : (int) ($current['locked_until'] ?? 0);

        $this->cache->delete($key);
        $this->cache->get($key, function (ItemInterface $item) use ($attempts, $lockedUntil, $lockoutDuration) {
            $item->expiresAfter($lockoutDuration);
            return [
                'attempts'     => $attempts,
                'locked_until' => $lockedUntil,
            ];
        });

        return $attempts;
    }

    /**
     * Enregistre un échec avec seuils et durées multi-paliers (OWASP ASVS / NIST SP 800-63B).
     * @param array<int, int> $tiers Format [seuil_tentatives => duree_secondes], trié par seuil croissant.
     */
    public function recordTieredFailedAttempt(string $identifier, array $tiers = []): int
    {
        if (empty($tiers)) {
            $tiers = [
                self::TIER_1_THRESHOLD => self::TIER_1_DURATION,
                self::TIER_2_THRESHOLD => self::TIER_2_DURATION,
                self::TIER_3_THRESHOLD => self::TIER_3_DURATION,
            ];
        }

        $key = $this->buildKey($identifier);
        $current = $this->cache->get($key, fn() => ['attempts' => 0, 'locked_until' => 0]);
        $attempts = ((int) ($current['attempts'] ?? 0)) + 1;

        $duration = 0;
        foreach ($tiers as $threshold => $dur) {
            if ($attempts >= $threshold) {
                $duration = $dur;
            }
        }

        $lockedUntil = $duration > 0 ? time() + $duration : (int) ($current['locked_until'] ?? 0);
        $cacheTtl = max($duration > 0 ? $duration : 3600, self::TIER_3_DURATION);

        $this->cache->delete($key);
        $this->cache->get($key, function (ItemInterface $item) use ($attempts, $lockedUntil, $cacheTtl) {
            $item->expiresAfter($cacheTtl);
            return [
                'attempts'     => $attempts,
                'locked_until' => $lockedUntil,
            ];
        });

        return $attempts;
    }

    /**
     * Retourne le palier de verrouillage actuel (0, 1, 2, 3...)
     */
    public function getLockoutTier(string $identifier, array $tiers = []): int
    {
        if (empty($tiers)) {
            $tiers = [
                self::TIER_1_THRESHOLD => 1,
                self::TIER_2_THRESHOLD => 2,
                self::TIER_3_THRESHOLD => 3,
            ];
        }

        $key = $this->buildKey($identifier);
        $data = $this->cache->get($key, fn() => ['attempts' => 0, 'locked_until' => 0]);
        $attempts = (int) ($data['attempts'] ?? 0);

        $tier = 0;
        foreach ($tiers as $threshold => $tierLevel) {
            if ($attempts >= $threshold) {
                $tier = $tierLevel;
            }
        }

        return $tier;
    }

    /**
     * Cooldown progressif pour réinitialisation de mot de passe ou 2FA (ex: 60s -> 300s -> 900s).
     */
    public function recordProgressiveCooldown(string $identifier): int
    {
        $countKey = 'project_auth_cooldown_cnt_' . md5($identifier);
        $count = (int) $this->cache->get($countKey, fn() => 0) + 1;

        $duration = match (true) {
            $count >= 3 => 900,
            $count === 2 => 300,
            default => 60,
        };

        $this->cache->delete($countKey);
        $this->cache->get($countKey, function (ItemInterface $item) use ($count) {
            $item->expiresAfter(3600);
            return $count;
        });

        $this->setCooldown($identifier, $duration);

        return $duration;
    }

    public function setCooldown(string $identifier, int $durationSeconds = 60): void
    {
        $key = 'project_auth_cooldown_' . md5($identifier);
        $this->cache->delete($key);
        $this->cache->get($key, function (ItemInterface $item) use ($durationSeconds) {
            $item->expiresAfter($durationSeconds);
            return time() + $durationSeconds;
        });
    }

    public function hasCooldown(string $identifier): bool
    {
        $key = 'project_auth_cooldown_' . md5($identifier);
        $expires = $this->cache->get($key, fn() => 0);
        return $expires > time();
    }

    public function clearAttempts(string $identifier): void
    {
        $this->cache->delete($this->buildKey($identifier));
    }

    private function buildKey(string $identifier): string
    {
        return 'project_auth_lockout_' . md5($identifier);
    }
}
