<?php

namespace App\Service\Flow\Handlers\Logic;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class OrHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $anyTrue = false;
        foreach ($input as $nodeId => $output) {
            if ($output->data['condition_result'] ?? $output->data['result'] ?? false) {
                $anyTrue = true;
                break;
            }
        }

        return new NodeOutput(
            data: ['result' => $anyTrue],
            branch: $anyTrue ? 'true' : 'false',
        );
    }

    public static function getCategory(): string { return 'logic'; }
    public static function getType(): string { return 'or'; }
    public static function getFullType(): string { return 'logic.or'; }
    public static function getLabel(): string { return 'OU logique'; }
    public static function getDescription(): string { return "Vrai si au moins une entrée est vraie"; }
    public static function getIcon(): string { return 'Ampersands'; }
    public static function getConfigSchema(): array { return ['type' => 'object', 'properties' => []]; }
    public static function getOutputPorts(): array { return ['true', 'false']; }
}
