<?php

namespace App\Service\Flow\Handlers\Logic;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class LoopHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $inputData = array_values($input)[0]->data ?? [];
        $config = $ctx->variables['_node_config'] ?? [];
        $items = $config['items'] ?? $inputData['items'] ?? $inputData;

        if (!is_array($items) || empty($items)) {
            return new NodeOutput(data: ['iterations' => 0, 'results' => []]);
        }

        $results = [];
        foreach ($items as $index => $item) {
            $results[] = $item;
        }

        return new NodeOutput(data: [
            'iterations' => count($items),
            'results' => $results,
        ]);
    }

    public static function getCategory(): string { return 'logic'; }
    public static function getType(): string { return 'loop'; }
    public static function getFullType(): string { return 'logic.loop'; }
    public static function getLabel(): string { return 'Boucle (for each)'; }
    public static function getDescription(): string { return "Itère sur chaque élément d'une liste"; }
    public static function getIcon(): string { return 'Repeat'; }

    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'items_path' => [
                    'type' => 'string',
                    'title' => 'Chemin des items',
                    'description' => 'Chemin vers la liste dans les données',
                    'template' => true,
                ],
            ],
        ];
    }

    public static function getOutputPorts(): array { return ['default']; }
}
