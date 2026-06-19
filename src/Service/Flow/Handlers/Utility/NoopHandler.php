<?php

namespace App\Service\Flow\Handlers\Utility;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class NoopHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $inputData = array_values($input)[0]->data ?? [];
        return new NodeOutput(data: $inputData);
    }

    public static function getCategory(): string { return 'util'; }
    public static function getType(): string { return 'noop'; }
    public static function getFullType(): string { return 'util.noop'; }
    public static function getLabel(): string { return 'Ne rien faire'; }
    public static function getDescription(): string { return 'Node pass-through qui ne fait rien'; }
    public static function getIcon(): string { return 'Minus'; }
    public static function getConfigSchema(): array { return ['type' => 'object', 'properties' => []]; }
    public static function getOutputPorts(): array { return ['default']; }
}
