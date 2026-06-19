<?php

namespace App\Service\Flow\Handlers\Http;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class ResponseToJsonHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $inputData = array_values($input)[0]->data ?? [];
        $body = $inputData['body'] ?? '{}';
        $parsed = json_decode($body, true) ?? ['raw' => $body];
        return new NodeOutput(data: $parsed);
    }

    public static function getCategory(): string { return 'http'; }
    public static function getType(): string { return 'response_to_json'; }
    public static function getFullType(): string { return 'http.response_to_json'; }
    public static function getLabel(): string { return 'Reponse -> JSON'; }
    public static function getDescription(): string { return 'Parse le corps de la reponse HTTP en JSON'; }
    public static function getIcon(): string { return 'Braces'; }
    public static function getConfigSchema(): array { return ['type' => 'object', 'properties' => []]; }
    public static function getOutputPorts(): array { return ['default']; }
}
