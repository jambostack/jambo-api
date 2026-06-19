<?php

namespace App\Service\Flow\Handlers\Action;

use App\Entity\Notification;
use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;
use Doctrine\ORM\EntityManagerInterface;

class SendNotificationHandler implements FlowNodeHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];
        $title = $config['title'] ?? 'Automatisation';
        $body  = $config['body']  ?? '';

        try {
            $notification = new Notification();
            $notification->title = $title;
            $notification->body = $body;
            $notification->type = 'automation';

            $this->em->persist($notification);
            $this->em->flush();

            return new NodeOutput(data: ['sent' => true, 'title' => $title]);
        } catch (\Throwable $e) {
            return new NodeOutput(data: ['sent' => false, 'error' => $e->getMessage()]);
        }
    }

    public static function getCategory(): string { return 'action'; }
    public static function getType(): string { return 'send_notification'; }
    public static function getFullType(): string { return 'action.send_notification'; }
    public static function getLabel(): string { return 'Envoyer une notification'; }
    public static function getDescription(): string { return "Cree une notification interne dans le système"; }
    public static function getIcon(): string { return 'Bell'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['title', 'body'],
            'properties' => [
                'title' => ['type' => 'string', 'title' => 'Titre', 'template' => true],
                'body' => ['type' => 'string', 'format' => 'textarea', 'title' => 'Contenu', 'template' => true],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}
