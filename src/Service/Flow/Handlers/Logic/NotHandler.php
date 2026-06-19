<?php

namespace App\Service\Flow\Handlers\Logic;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class NotHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $firstInput = array_values($input)[0] ?? null;
        $value = $firstInput?->data['condition_result'] ?? $firstInput?->data['result'] ?? false;

        return new NodeOutput(data: ['result' => !$value]);
    }

    public static function getCategory(): string { return 'logic'; }
    public static function getType(): string { return 'not'; }
    public static function getFullType(): string { return 'logic.not'; }
    public static function getLabel(): string { return 'NON logique'; }
    public static function getDescription(): string { return "Inverse la valeur booléenne d'entrée"; }
    public static function getIcon(): string { return 'Ban'; }
    public static function getConfigSchema(): array { return ['type' => 'object', 'properties' => []]; }
    public static function getOutputPorts(): array { return ['default']; }
}
