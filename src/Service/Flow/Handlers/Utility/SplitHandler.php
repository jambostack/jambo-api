<?php

namespace App\Service\Flow\Handlers\Utility;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class SplitHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        $inputData = array_values($input)[0]->data ?? [];
        $items = $inputData['items'] ?? $inputData;
        $batchSize = max(1, (int) ($config['batch_size'] ?? 10));

        if (!is_array($items)) {
            $items = [$items];
        }

        $batches = array_chunk($items, $batchSize);

        return new NodeOutput(data: [
            'batches' => $batches,
            'total_items' => count($items),
            'batch_count' => count($batches),
        ]);
    }

    public static function getCategory(): string { return 'util'; }
    public static function getType(): string { return 'split'; }
    public static function getFullType(): string { return 'util.split'; }
    public static function getLabel(): string { return 'Diviser'; }
    public static function getDescription(): string { return 'Divise un tableau en lots (batches)'; }
    public static function getIcon(): string { return 'GitFork'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'batch_size' => ['type' => 'number', 'title' => 'Taille du lot', 'default' => 10, 'minimum' => 1],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
