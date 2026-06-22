<?php

namespace App\Exception;

class SchemaException extends \RuntimeException
{
    public function __construct(string $message, int $statusCode = 422)
    {
        parent::__construct($message, $statusCode);
    }

    public function statusCode(): int
    {
        return $this->getCode();
    }
}
