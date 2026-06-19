<?php

namespace App\Service\Flow\Handlers\Logic;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class AndHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $allTrue = true;
        $results = [];
        foreach ($input as $nodeId => $output) {
            $val = $output->data['condition_result'] ?? $output->data['result'] ?? false;
            $results[$nodeId] = $val;
            if (!$val) {
                $allTrue = false;
            }
        }

        return new NodeOutput(
            data: ['result' => $allTrue, 'details' => $results],
            branch: $allTrue ? 'true' : 'false',
        );
    }

    public static function getCategory(): string { return 'logic'; }
    public static function getType(): string { return 'and'; }
    public static function getFullType(): string { return 'logic.and'; }
    public static function getLabel(): string { return 'ET logique'; }
    public static function getDescription(): string { return "Vrai si toutes les entrées sont vraies"; }
    public static function getIcon(): string { return 'Ampersand'; }
    public static function getConfigSchema(): array { return ['type' => 'object', 'properties' => []]; }
    public static function getOutputPorts(): array { return ['true', 'false']; }
}
