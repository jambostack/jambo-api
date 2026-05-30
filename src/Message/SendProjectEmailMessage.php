<?php

namespace App\Message;

class SendProjectEmailMessage
{
    public function __construct(
        public readonly string $host,
        public readonly int    $port,
        public readonly string $username,
        public readonly string $password,
        public readonly string $encryption,
        public readonly string $fromEmail,
        public readonly string $fromName,
        public readonly string $to,
        public readonly string $subject,
        public readonly string $body,
        public readonly ?string $replyTo,
        public readonly int    $projectId,
    ) {}
}
