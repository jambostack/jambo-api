<?php

namespace App\Service\Flow\Handlers\Database;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class RawQueryHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        throw new \RuntimeException(
            'RawQueryHandler: Raw DQL execution is disabled for security reasons. Use FindEntriesHandler or CountEntriesHandler instead.'
        );
    }

    public static function getCategory(): string { return 'db'; }
    public static function getType(): string { return 'raw_query'; }
    public static function getFullType(): string { return 'db.raw_query'; }
    public static function getLabel(): string { return 'Requete DQL'; }
    public static function getDescription(): string { return 'Execute une requete DQL personnalisee'; }
    public static function getIcon(): string { return 'Database'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['dql'],
            'properties' => [
                'dql' => ['type' => 'string', 'format' => 'textarea', 'title' => 'Requete DQL', 'template' => true],
                'parameters' => ['type' => 'object', 'title' => 'Parametres', 'default' => []],
                'max_results' => ['type' => 'number', 'title' => 'Max resultats', 'default' => 100],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
