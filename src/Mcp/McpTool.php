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
        $resolvedRequired = $required;
        $cleanProperties = [];

        foreach ($properties as $key => $prop) {
            if (is_array($prop)) {
                if (!empty($prop['_required']) && !in_array($key, $resolvedRequired, true)) {
                    $resolvedRequired[] = $key;
                }
                unset($prop['_required']);
                if (isset($prop['required']) && is_bool($prop['required'])) {
                    if ($prop['required'] && !in_array($key, $resolvedRequired, true)) {
                        $resolvedRequired[] = $key;
                    }
                    unset($prop['required']);
                }
            }
            $cleanProperties[$key] = $prop;
        }

        return [
            'type' => 'object',
            'properties' => $cleanProperties,
            'required' => array_values(array_unique($resolvedRequired)),
        ];
    }

    public static function stringProp(string $description, bool $required = false): array
    {
        $p = ['type' => 'string', 'description' => $description];
        if ($required) {
            $p['_required'] = true;
        }
        return $p;
    }

    public static function intProp(string $description, bool $required = false): array
    {
        $p = ['type' => 'integer', 'description' => $description];
        if ($required) {
            $p['_required'] = true;
        }
        return $p;
    }

    public static function boolProp(string $description, bool $required = false): array
    {
        $p = ['type' => 'boolean', 'description' => $description];
        if ($required) {
            $p['_required'] = true;
        }
        return $p;
    }

    public static function enumProp(array $values, string $description, bool $required = false): array
    {
        $p = ['type' => 'string', 'enum' => $values, 'description' => $description];
        if ($required) {
            $p['_required'] = true;
        }
        return $p;
    }
}
