<?php

namespace App\Message;

/**
 * Message Messenger pour exécution asynchrone d'automatisation.
 *
 * Transporte l'identité de l'automatisation, le payload de déclenchement
 * et le délai d'expiration (TTL) pour prévenir l'exécution de tâches obsolètes.
 */
class ExecuteAutomationMessage
{
    public readonly int $dispatchedAt;

    public function __construct(
        public readonly int $automationId,
        public readonly int $runId,
        public readonly array $triggerPayload,
        public readonly string $projectUuid,
        public readonly bool $debugMode,
        ?int $dispatchedAt = null,
        public readonly int $ttlSeconds = 86400,
    ) {
        if ($automationId <= 0) {
            throw new \InvalidArgumentException('Invalid automation ID.');
        }
        if ($runId <= 0) {
            throw new \InvalidArgumentException('Invalid automation run ID.');
        }
        $this->dispatchedAt = ($dispatchedAt !== null && $dispatchedAt > 0) ? $dispatchedAt : time();
    }
}

