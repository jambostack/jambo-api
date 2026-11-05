<?php

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class ProjectAuthLockoutService
{
    public const MAX_ATTEMPTS = 5;
    public const LOCKOUT_DURATION = 900; // 15 minutes en secondes

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
