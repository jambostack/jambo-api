<?php

namespace App\Mcp;

class McpTool
{
    public readonly string $name;
    public readonly string $description;
    private array $schema;
    private \Closure $handler;
    private ?\Closure $availabilityCheck;

    public function __construct(
        string $name,
        string $description,
        array $inputSchema,
        callable $handler,
        ?callable $availabilityCheck = null,
    ) {
        $this->name = $name;
        $this->description = $description;
        $this->schema = $inputSchema;
        $this->handler = \Closure::fromCallable($handler);
        $this->availabilityCheck = $availabilityCheck ? \Closure::fromCallable($availabilityCheck) : null;
    }

    public function getInputSchema(): array
    {
        return $this->schema;
    }

    public function execute(array $arguments, array $context): mixed
    {
        return ($this->handler)($arguments, $context);
    }

    public function isAvailable(array $context): bool
    {
        if ($this->availabilityCheck === null) {
            return true;
        }

        return ($this->availabilityCheck)($context);
    }

    /**
     * Helper to create a standard JSON Schema object.
     */
    public static function schema(array $properties, array $required = []): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];
    }

    public static function stringProp(string $description, bool $required = false): array
    {
        return ['type' => 'string', 'description' => $description];
    }

    public static function intProp(string $description, bool $required = false): array
    {
        return ['type' => 'integer', 'description' => $description];
    }

    public static function boolProp(string $description, bool $required = false): array
    {
        return ['type' => 'boolean', 'description' => $description];
    }

    public static function enumProp(array $values, string $description, bool $required = false): array
    {
        return ['type' => 'string', 'enum' => $values, 'description' => $description];
    }
}
