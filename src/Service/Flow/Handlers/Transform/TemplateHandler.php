<?php

namespace App\Service\Flow\Handlers\Transform;

use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;

class TemplateHandler implements FlowNodeHandler
{
    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->getCurrentNodeConfig();
        $inputData = array_values($input)[0]->data ?? [];
        $template = (string) ($config['template'] ?? '');

        $safeVars = $ctx->getSafeVariables();
        $rendered = $this->renderTemplate($template, $inputData, $safeVars);

        return new NodeOutput(data: ['rendered' => $rendered]);
    }

    private function renderTemplate(string $template, array $data, array $contextVars = []): string
    {
        // 1. Anti-escape de variables globales d'environnement
        $forbiddenEnvPatterns = [
            '/\{\{\s*(?:_?ENV|_?SERVER|GLOBALS|getenv|putenv|env|server)\b[^\}]*\}\}/i',
            '/\{\{\s*(?:APP_SECRET|DATABASE_URL|MAILER_DSN|JWT_SECRET|MERCURE_JWT_SECRET)[^\}]*\}\}/i',
        ];
        $result = (string) preg_replace($forbiddenEnvPatterns, '', $template);

        // 2. Fusionne les variables contextuelles sûres et les données en entrée
        $merged = array_merge($contextVars, $data);

        foreach ($merged as $key => $value) {
            if (!is_string($key) || str_starts_with($key, '_')) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $valStr = (string) ($value ?? '');
                $result = str_replace('{{ ' . $key . ' }}', $valStr, $result);
                $result = str_replace('{{' . $key . '}}', $valStr, $result);
            }
        }

        // Limite stricte anti-DoS sur la taille finale du rendu (1 Mo max)
        if (strlen($result) > 1048576) {
            $result = substr($result, 0, 1048576);
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
