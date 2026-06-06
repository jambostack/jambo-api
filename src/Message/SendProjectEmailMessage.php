<?php

namespace App\Message;

class SendProjectEmailMessage
{
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
    ) {}
}

class Attachment
{
    public function __construct(
        public readonly string $content,   // contenu binaire brut
        public readonly string $filename,  // nom affiché dans l'email
        public readonly string $mimeType,  // ex: application/pdf
    ) {}
}
