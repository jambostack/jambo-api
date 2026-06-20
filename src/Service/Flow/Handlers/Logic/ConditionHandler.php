<?php

namespace App\Service\Flow\Handlers\Logic;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class ConditionHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $inputData = array_values($input)[0] ?? [];
        $config = $this->resolveConfig($ctx);
        $field = $config['field'] ?? '';
        $operator = $config['operator'] ?? 'eq';
        $expected = $config['value'] ?? null;

        $actual = $this->getNestedValue($inputData, $field);
        $result = $this->evaluate($actual, $operator, $expected);

        return new NodeOutput(
            data: array_merge($inputData, ['condition_result' => $result]),
            branch: $result ? 'true' : 'false',
        );
    }

    private function evaluate(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            'eq' => $actual == $expected,
            'neq' => $actual != $expected,
            'in' => is_array($expected) && in_array($actual, $expected, true),
            'contains' => is_string($actual) && is_string($expected) && str_contains($actual, $expected),
            'gt' => is_numeric($actual) && is_numeric($expected) && $actual > $expected,
            'gte' => is_numeric($actual) && is_numeric($expected) && $actual >= $expected,
            'lt' => is_numeric($actual) && is_numeric($expected) && $actual < $expected,
            'lte' => is_numeric($actual) && is_numeric($expected) && $actual <= $expected,
            'empty' => $actual === null || $actual === '' || $actual === [] || $actual === false,
            'notEmpty' => !($actual === null || $actual === '' || $actual === [] || $actual === false),
            default => false,
        };
    }

    private function getNestedValue(array $data, string $path): mixed
    {
        $keys = explode('.', $path);
        $current = $data;
        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }
        return $current;
    }

    private function resolveConfig(FlowContext $ctx): array
    {
        return $ctx->variables['_node_config'] ?? [];
    }

    public static function getCategory(): string { return 'logic'; }
    public static function getType(): string { return 'condition'; }
    public static function getFullType(): string { return 'logic.condition'; }
    public static function getLabel(): string { return 'Condition (if/else)'; }
    public static function getDescription(): string { return "Branche le flow selon une condition. Sortie 'true' ou 'false'."; }
    public static function getIcon(): string { return 'GitBranch'; }

    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['field', 'operator'],
            'properties' => [
                'field' => [
                    'type' => 'string',
                    'title' => 'Champ',
                    'description' => 'Chemin du champ (ex: entry.status)',
                    'template' => false,
                ],
                'operator' => [
                    'type' => 'string',
                    'enum' => ['eq', 'neq', 'in', 'contains', 'gt', 'gte', 'lt', 'lte', 'empty', 'notEmpty'],
                    'title' => 'Opérateur',
                    'default' => 'eq',
                ],
                'value' => [
                    'type' => 'string',
                    'title' => 'Valeur',
                    'description' => 'Valeur à comparer',
                ],
            ],
        ];
    }

    public static function getOutputPorts(): array { return ['true', 'false']; }
}
