<?php

namespace App\Exception;

class TusConflictException extends TusException
{
    public function __construct(string $message = 'Upload resource is currently conflicting')
    {
        parent::__construct($message, 409);
    }
}
