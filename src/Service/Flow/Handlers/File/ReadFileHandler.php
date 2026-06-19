<?php

namespace App\Service\Flow\Handlers\File;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class ReadFileHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        return new NodeOutput(data: [
            'result' => '[File Read -- a implementer]',
            'asset_uuid' => $config['asset_uuid'] ?? '',
        ]);
    }

    public static function getCategory(): string { return 'file'; }
    public static function getType(): string { return 'read'; }
    public static function getFullType(): string { return 'file.read'; }
    public static function getLabel(): string { return 'Lire un fichier'; }
    public static function getDescription(): string { return 'Lit le contenu et les metadonnees d un fichier'; }
    public static function getIcon(): string { return 'FileText'; }
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
