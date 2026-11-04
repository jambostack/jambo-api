<?php

namespace App\Exception;

class TusChunkOutOfBoundsException extends TusException
{
    public function __construct(string $message = 'Chunk size exceeds declared upload length')
    {
        parent::__construct($message, 413);
    }
}
