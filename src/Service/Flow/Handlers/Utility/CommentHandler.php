<?php

namespace App\Service\Flow\Handlers\Utility;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class CommentHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $inputData = array_values($input)[0]->data ?? [];

        // Pure cosmetic node, no runtime effect. Pass data through.
        return new NodeOutput(data: $inputData);
    }

    public static function getCategory(): string { return 'util'; }
    public static function getType(): string { return 'comment'; }
    public static function getFullType(): string { return 'util.comment'; }
    public static function getLabel(): string { return 'Commentaire'; }
    public static function getDescription(): string { return 'Node purement visuel, ignore a l execution'; }
    public static function getIcon(): string { return 'MessageSquare'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'text' => ['type' => 'string', 'format' => 'textarea', 'title' => 'Commentaire'],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
