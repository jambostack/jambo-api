<?php

namespace App\Service\Flow\Handlers\Logic;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class SwitchHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $inputData = array_values($input)[0] ?? [];
        $config = $ctx->variables['_node_config'] ?? [];
        $fieldPath = $config['field'] ?? '';
        $cases = $config['cases'] ?? [];

        $actual = $this->getNestedValue($inputData, $fieldPath);

        foreach ($cases as $case) {
            if ((string)$actual === (string)($case['value'] ?? '')) {
                return new NodeOutput(
                    data: array_merge($inputData, ['switch_value' => $actual]),
                    branch: $case['branch'] ?? 'default',
                );
            }
        }

        return new NodeOutput(
            data: array_merge($inputData, ['switch_value' => $actual]),
            branch: $config['default_branch'] ?? 'default',
        );
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

    public static function getCategory(): string { return 'logic'; }
    public static function getType(): string { return 'switch'; }
    public static function getFullType(): string { return 'logic.switch'; }
    public static function getLabel(): string { return 'Switch (multi-branches)'; }
    public static function getDescription(): string { return "Route le flow vers une branche selon la valeur d'un champ"; }
    public static function getIcon(): string { return 'ArrowLeftRight'; }

    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['field', 'cases'],
            'properties' => [
                'field' => ['type' => 'string', 'title' => 'Champ à évaluer'],
                'cases' => [
                    'type' => 'array',
                    'title' => 'Cas',
                    'items' => [
                        'type' => 'object',
                        'required' => ['value', 'branch'],
                        'properties' => [
                            'value' => ['type' => 'string', 'title' => 'Valeur'],
                            'branch' => ['type' => 'string', 'title' => 'Nom de branche'],
                        ],
                    ],
                ],
                'default_branch' => ['type' => 'string', 'title' => 'Branche par défaut', 'default' => 'default'],
            ],
        ];
    }

    public static function getOutputPorts(): array
    {
        // Les ports sont dynamiques (définis dans la config 'cases').
        // Le port par défaut est 'default', les autres sont 'case_0', 'case_1', etc.
        return ['default'];
    }
}
