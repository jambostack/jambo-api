<?php

namespace App\Service\Flow\Handlers\Utility;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class MergeHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $merged = [];
        foreach ($input as $nodeId => $output) {
            $merged[$nodeId] = $output->data;
        }

        return new NodeOutput(data: [
            'branches' => $merged,
            'count' => count($merged),
        ]);
    }

    public static function getCategory(): string { return 'util'; }
    public static function getType(): string { return 'merge'; }
    public static function getFullType(): string { return 'util.merge'; }
    public static function getLabel(): string { return 'Fusionner'; }
    public static function getDescription(): string { return 'Fusionne les resultats de toutes les branches entrantes'; }
    public static function getIcon(): string { return 'GitMerge'; }
    public static function getConfigSchema(): array { return ['type' => 'object', 'properties' => []]; }
    public static function getOutputPorts(): array { return ['default']; }
}
