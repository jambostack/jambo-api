<?php

namespace App\Service\Flow\Handlers\Transform;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class TemplateHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        $inputData = array_values($input)[0]->data ?? [];
        $template = $config['template'] ?? '';

        $rendered = $this->renderTemplate($template, $inputData);

        return new NodeOutput(data: ['rendered' => $rendered]);
    }

    private function renderTemplate(string $template, array $data): string
    {
        $result = $template;
        foreach ($data as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $result = str_replace('{{ ' . $key . ' }}', (string) ($value ?? ''), $result);
                $result = str_replace('{{' . $key . '}}', (string) ($value ?? ''), $result);
            }
        }
        return $result;
    }

    public static function getCategory(): string { return 'transform'; }
    public static function getType(): string { return 'template'; }
    public static function getFullType(): string { return 'transform.template'; }
    public static function getLabel(): string { return 'Template'; }
    public static function getDescription(): string { return 'Rend un template avec les donnees en entree'; }
    public static function getIcon(): string { return 'FileCode'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['template'],
            'properties' => [
                'template' => ['type' => 'string', 'format' => 'textarea', 'title' => 'Template', 'template' => true],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
