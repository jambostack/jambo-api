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
        $config = $ctx->variables['_node_config'] ?? [];
        $project = $this->projectRepo->findOneBy(['uuid' => $ctx->projectUuid]);

        try {
            $this->mailerService->send(
                project: $project,
                to: $config['to'] ?? '',
                subject: $config['subject'] ?? 'Automatisation Jambo',
                body: $config['body'] ?? '',
                htmlBody: $config['body'] ?? '',
            );
            return new NodeOutput(data: ['sent' => true, 'to' => $config['to'] ?? '']);
        } catch (\Throwable $e) {
            return new NodeOutput(data: ['sent' => false, 'error' => $e->getMessage()]);
        }
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
