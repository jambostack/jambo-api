<?php

namespace App\Mcp;

class McpException extends \RuntimeException
{
    public function __construct(string $message, int $code = -32000)
    {
        parent::__construct($message, $code);
    }
}
