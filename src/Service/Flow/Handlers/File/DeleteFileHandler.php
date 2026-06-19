<?php

namespace App\Service\Flow\Handlers\File;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class DeleteFileHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        return new NodeOutput(data: [
            'result' => '[File Delete -- a implementer]',
            'asset_uuid' => $config['asset_uuid'] ?? '',
        ]);
    }

    public static function getCategory(): string { return 'file'; }
    public static function getType(): string { return 'delete'; }
    public static function getFullType(): string { return 'file.delete'; }
    public static function getLabel(): string { return 'Supprimer un fichier'; }
    public static function getDescription(): string { return 'Supprime un fichier via son UUID'; }
    public static function getIcon(): string { return 'Trash2'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['asset_uuid'],
            'properties' => [
                'asset_uuid' => ['type' => 'string', 'title' => 'UUID du fichier'],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
