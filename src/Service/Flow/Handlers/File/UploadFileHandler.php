<?php

namespace App\Service\Flow\Handlers\File;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class UploadFileHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        return new NodeOutput(data: [
            'result' => '[File Upload -- a implementer]',
            'filename' => $config['filename'] ?? '',
        ]);
    }

    public static function getCategory(): string { return 'file'; }
    public static function getType(): string { return 'upload'; }
    public static function getFullType(): string { return 'file.upload'; }
    public static function getLabel(): string { return 'Uploader un fichier'; }
    public static function getDescription(): string { return 'Upload un fichier en base64 ou depuis une URL'; }
    public static function getIcon(): string { return 'Upload'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['filename'],
            'properties' => [
                'filename' => ['type' => 'string', 'title' => 'Nom du fichier'],
                'content' => ['type' => 'string', 'format' => 'textarea', 'title' => 'Contenu (base64)', 'template' => true],
                'source_url' => ['type' => 'string', 'format' => 'url', 'title' => 'URL source', 'template' => true],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
