<?php

namespace App\Service\Flow\Handlers\Transform;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class FlattenHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $inputData = array_values($input)[0]->data ?? [];
        $items = $inputData['items'] ?? $inputData;

        if (!is_array($items)) {
            $items = [$items];
        }

        $flattened = $this->flatten($items);

        return new NodeOutput(data: ['items' => $flattened, 'count' => count($flattened)]);
    }

    private function flatten(array $array, int $depth = PHP_INT_MAX): array
    {
        $result = [];
        foreach ($array as $item) {
            if (is_array($item) && $depth > 0) {
                $result = array_merge($result, $this->flatten($item, $depth - 1));
            } else {
                $result[] = $item;
            }
        }
        return $result;
    }

    public static function getCategory(): string { return 'transform'; }
    public static function getType(): string { return 'flatten'; }
    public static function getFullType(): string { return 'transform.flatten'; }
    public static function getLabel(): string { return 'Aplatir'; }
    public static function getDescription(): string { return 'Aplatit un tableau imbrique'; }
    public static function getIcon(): string { return 'ChevronsDownUp'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
