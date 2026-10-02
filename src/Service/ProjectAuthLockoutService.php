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

    public function recordFailedAttempt(string $identifier): int
    {
        $key = $this->buildKey($identifier);
        $this->cache->delete($key);

        $attempts = 0;
        $this->cache->get($key, function (ItemInterface $item) use (&$attempts) {
            $item->expiresAfter(self::LOCKOUT_DURATION);
            $attempts = 1;
            return [
                'attempts'     => $attempts,
                'locked_until' => $attempts >= self::MAX_ATTEMPTS ? time() + self::LOCKOUT_DURATION : 0,
            ];
        });

        return $attempts;
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
