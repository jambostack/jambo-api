<?php

namespace App\Message;

final class SendWebhookMessage
{
    public function __construct(
        public readonly int $webhookId,
        public readonly string $event,
        public readonly array $payload,
    ) {}
}
