<?php

namespace App\Message;

final class SendWebhookMessage
{
    public readonly int $dispatchedAt;
    public readonly ?string $projectUuid;

    public function __construct(
        public readonly int $webhookId,
        public readonly string $event,
        public readonly array $payload,
        ?int $dispatchedAt = null,
        public readonly int $ttlSeconds = 86400,
        ?string $projectUuid = null,
    ) {
        if ($webhookId <= 0) {
            throw new \InvalidArgumentException('Invalid webhook ID.');
        }
        if (trim($event) === '' || preg_match('/[\r\n\x00-\x1F\x7F]/', $event)) {
            throw new \InvalidArgumentException('Invalid webhook event name.');
        }
        $this->dispatchedAt = ($dispatchedAt !== null && $dispatchedAt > 0) ? $dispatchedAt : time();
        $this->projectUuid = ($projectUuid !== null && trim($projectUuid) !== '') ? trim($projectUuid) : null;
    }
}

