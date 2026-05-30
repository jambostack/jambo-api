<?php

namespace App\Message;

class SendProjectEmailMessage
{
    public function __construct(
        public readonly string $host,
        public readonly int    $port,
        public readonly string $username,
        /** Chiffré (sodium secretbox) — le handler le déchiffre avec APP_SECRET. */
        public readonly string $encryptedPassword,
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
