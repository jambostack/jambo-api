<?php

namespace App\Message;

class SendProjectEmailMessage
{
    public const MAX_ATTACHMENT_SIZE = 10485760; // 10 Mo
    public const MAX_TOTAL_ATTACHMENTS_SIZE = 26214400; // 25 Mo

    public readonly int $dispatchedAt;

    /**
     * @param string[]      $cc          Recipients en copie
     * @param string[]      $bcc         Recipients en copie cachée
     * @param Attachment[]  $attachments Pièces jointes
     */
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
        public readonly ?string $htmlBody = null,
        public readonly ?string $replyTo = null,
        public readonly array $cc = [],
        public readonly array $bcc = [],
        public readonly array $attachments = [],
        public readonly int    $projectId = 0,
        ?int $dispatchedAt = null,
        public readonly int $ttlSeconds = 86400,
    ) {
        $totalBytes = 0;
        foreach ($attachments as $att) {
            if ($att instanceof Attachment) {
                $totalBytes += strlen($att->content);
            }
        }
        if ($totalBytes > self::MAX_TOTAL_ATTACHMENTS_SIZE) {
            throw new \LengthException(sprintf('Total attachments size (%d bytes) exceeds maximum limit of %d bytes.', $totalBytes, self::MAX_TOTAL_ATTACHMENTS_SIZE));
        }

        $this->dispatchedAt = ($dispatchedAt !== null && $dispatchedAt > 0) ? $dispatchedAt : time();
    }
}


