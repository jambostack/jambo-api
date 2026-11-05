<?php

declare(strict_types=1);

namespace App\Message;

class Attachment implements \JsonSerializable
{
    public const MAX_FILE_SIZE = 10485760; // 10 Mo

    public readonly string $content;
    public readonly string $filename;
    public readonly string $mimeType;

    public function __construct(
        string $content,
        string $filename,
        string $mimeType = 'application/octet-stream',
    ) {
        if (strlen($content) > self::MAX_FILE_SIZE) {
            throw new \LengthException(sprintf('Attachment size (%d bytes) exceeds maximum limit of %d bytes.', strlen($content), self::MAX_FILE_SIZE));
        }

        $cleanFilename = basename(preg_replace('/[\r\n\x00-\x1F\x7F]/', '', $filename));
        $this->filename = ($cleanFilename !== '' && $cleanFilename !== '.') ? $cleanFilename : 'attachment';

        $cleanMime = trim($mimeType);
        if (preg_match('#^[a-zA-Z0-9_\-\.\+]+/[a-zA-Z0-9_\-\.\+]+$#', $cleanMime)) {
            $this->mimeType = $cleanMime;
        } else {
            $this->mimeType = 'application/octet-stream';
        }

        $this->content = $content;
    }

    public function jsonSerialize(): array
    {
        return [
            'content'  => base64_encode($this->content),
            'filename' => $this->filename,
            'mimeType' => $this->mimeType,
        ];
    }

    public static function fromArray(array $data): self
    {
        $raw = isset($data['content']) ? (string) $data['content'] : '';
        // If content is base64 encoded
        $decoded = base64_decode($raw, true);
        $content = ($decoded !== false) ? $decoded : $raw;

        return new self(
            $content,
            (string) ($data['filename'] ?? 'attachment'),
            (string) ($data['mimeType'] ?? 'application/octet-stream'),
        );
    }
}
