<?php

namespace App\Exception;

class TusOffsetMismatchException extends TusException
{
    public function __construct(
        private readonly int $currentOffset,
        private readonly int $requestedOffset,
        string $message = ''
    ) {
        if ($message === '') {
            $message = sprintf('Upload-Offset mismatch: expected %d, got %d', $currentOffset, $requestedOffset);
        }
        parent::__construct($message, 409);
    }

    public function getCurrentOffset(): int
    {
        return $this->currentOffset;
    }

    public function getRequestedOffset(): int
    {
        return $this->requestedOffset;
    }
}
