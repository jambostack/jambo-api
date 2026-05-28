<?php

namespace App\Mcp;

class McpResource
{
    public readonly string $uri;
    public readonly string $name;
    public readonly string $description;
    public readonly string $mimeType;
    private \Closure $reader;
    private ?\Closure $availabilityCheck;

    public function __construct(
        string $uri,
        string $name,
        string $description,
        string $mimeType,
        callable $reader,
        ?callable $availabilityCheck = null,
    ) {
        $this->uri = $uri;
        $this->name = $name;
        $this->description = $description;
        $this->mimeType = $mimeType;
        $this->reader = \Closure::fromCallable($reader);
        $this->availabilityCheck = $availabilityCheck ? \Closure::fromCallable($availabilityCheck) : null;
    }

    public function read(array $context): mixed
    {
        return ($this->reader)($context);
    }

    public function isAvailable(array $context): bool
    {
        if ($this->availabilityCheck === null) {
            return true;
        }

        return ($this->availabilityCheck)($context);
    }
}
