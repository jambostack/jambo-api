<?php

namespace App\Service\Flow\Handlers\Action;

use App\Repository\ProjectRepository;
use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;
use App\Service\ProjectMailerService;

class SendEmailHandler implements FlowNodeHandler
{
    public function __construct(
        private readonly ProjectRepository $projectRepo,
        private readonly ProjectMailerService $mailerService,
    ) {}

    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $firstInput = array_values($input)[0] ?? null;
        $inputData = $firstInput instanceof NodeOutput ? $firstInput->data : (is_array($firstInput) ? $firstInput : []);
        $safeVars = $ctx->getSafeVariables();

        $config = $ctx->variables['_node_config'] ?? [];
        $project = $this->projectRepo->findOneBy(['uuid' => $ctx->projectUuid]);
        if (!$project) {
            return new NodeOutput(data: ['sent' => false, 'error' => 'Project not found for UUID: ' . $ctx->projectUuid]);
        }

        $rawTo = (string) ($config['to'] ?? '');
        $rawSubject = (string) ($config['subject'] ?? 'Automatisation Jambo');
        $rawBody = (string) ($config['body'] ?? '');

        // Safe contextual interpolation
        $to = trim($this->interpolateSafe($rawTo, $inputData, $safeVars));
        $subject = trim($this->interpolateSafe($rawSubject, $inputData, $safeVars));
        $body = $this->interpolateSafe($rawBody, $inputData, $safeVars);

        // Anti-CRLF and control characters defense against header injection
        $to = preg_replace('/[\r\n\x00-\x1F\x7F]/', '', $to);
        $subject = preg_replace('/[\r\n\x00-\x1F\x7F]/', ' ', $subject);

        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return new NodeOutput(data: ['sent' => false, 'to' => $to, 'error' => 'Invalid destination email address.']);
        }

        try {
            $this->mailerService->send(
                project: $project,
                to: $to,
                subject: $subject,
                body: $body,
                htmlBody: $body,
            );
            return new NodeOutput(data: ['sent' => true, 'to' => $to]);
        } catch (\Throwable $e) {
            return new NodeOutput(data: ['sent' => false, 'error' => $e->getMessage()]);
        }
    }

    private function interpolateSafe(string $text, array $data, array $safeVars): string
    {
        if (!str_contains($text, '{{')) {
            return $text;
        }

        return preg_replace_callback('/\{\{([a-zA-Z0-9_.]+)\}\}/', function ($m) use ($data, $safeVars) {
            $path = explode('.', $m[1]);
            $val = null;

            if ($path[0] === 'env' || $path[0] === 'server') {
                return '';
            }

            if ($path[0] === 'input') {
                $val = $this->getNestedValue($data, array_slice($path, 1));
            } else {
                $val = $this->getNestedValue($safeVars, $path) ?? $this->getNestedValue($data, $path);
            }

            if (is_scalar($val)) {
                return (string) $val;
            }
            if ($val === null) {
                return '';
            }
            return json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }, $text);
    }

    private function getNestedValue(array $arr, array $path): mixed
    {
        $current = $arr;
        foreach ($path as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }
        return $current;
    }

    public static function getCategory(): string { return 'action'; }
    public static function getType(): string { return 'send_email'; }
    public static function getFullType(): string { return 'action.send_email'; }
    public static function getLabel(): string { return 'Envoyer un email'; }
    public static function getDescription(): string { return "Envoie un email via le service de messagerie du projet"; }
    public static function getIcon(): string { return 'Mail'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['to', 'subject', 'body'],
            'properties' => [
                'to' => ['type' => 'string', 'format' => 'email-list', 'title' => 'Destinataire(s)', 'template' => true],
                'subject' => ['type' => 'string', 'title' => 'Sujet', 'template' => true],
                'body' => ['type' => 'string', 'format' => 'richtext', 'title' => 'Corps', 'template' => true],
                'priority' => ['type' => 'string', 'enum' => ['low', 'normal', 'high'], 'title' => 'Priorité', 'default' => 'normal'],
                'reply_to' => ['type' => 'string', 'format' => 'email', 'title' => 'Reply-To', 'template' => true],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
