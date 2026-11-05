<?php

namespace App\Service\Flow\Handlers\Action;

use App\Entity\Notification;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowNodeHandler;
use App\Service\Flow\NodeOutput;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class SendNotificationHandler implements FlowNodeHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?NotificationService $notificationService = null,
        private readonly ?UserRepository $userRepository = null,
        private readonly ?ProjectRepository $projectRepository = null,
        private readonly ?TranslatorInterface $translator = null,
    ) {}

    public function execute(array $input, FlowContext $ctx): NodeOutput
    {
        $config = $ctx->variables['_node_config'] ?? [];

        $project = $ctx->project ?? ($this->projectRepository && !empty($ctx->projectUuid)
            ? $this->projectRepository->findOneBy(['uuid' => $ctx->projectUuid])
            : null);

        $recipient = $this->resolveRecipient($config, $input, $ctx, $project);
        if ($recipient === null) {
            $errorMessage = $this->trans('notification.flow_missing_recipient', [], 'Recipient is required to send notification.');
            return new NodeOutput(data: [
                'sent'  => false,
                'error' => $errorMessage,
            ]);
        }

        $defaultTitle = $this->trans('notification.automation_title', [], 'Automation notification');
        $rawTitle = $config['title'] ?? $input['title'] ?? $defaultTitle;
        $title = !empty(trim((string) $rawTitle)) ? (string) $rawTitle : $defaultTitle;

        $body = (string) ($config['body'] ?? $input['body'] ?? '');
        $link = isset($config['link']) ? (string) $config['link'] : null;

        try {
            if ($this->notificationService !== null) {
                $notification = $this->notificationService->notifyAutomation(
                    recipient: $recipient,
                    title: $title,
                    body: $body !== '' ? $body : null,
                    link: $link,
                    project: $project,
                );
            } else {
                $notification = new Notification();
                $notification->recipient = $recipient;
                $notification->title = mb_substr($title, 0, 255);
                $notification->body = $body !== '' ? mb_substr($body, 0, 2000) : null;
                $notification->type = 'automation';
                $notification->link = $link ?? ($project?->uuid ? '/projects/' . $project->uuid->toRfc4122() : '');

                $this->em->persist($notification);
                $this->em->flush();
            }

            return new NodeOutput(data: [
                'sent'           => true,
                'title'          => $title,
                'recipient_id'   => $recipient->id,
                'recipient_name' => $recipient->name ?: $recipient->email,
            ]);
        } catch (\Throwable $e) {
            return new NodeOutput(data: ['sent' => false, 'error' => $e->getMessage()]);
        }
    }

    private function resolveRecipient(array $config, array $input, FlowContext $ctx, ?Project $project = null): ?User
    {
        $rawRecipient = $config['recipient']
            ?? $config['recipient_email']
            ?? $config['recipient_id']
            ?? $input['recipient']
            ?? $input['recipient_id']
            ?? $input['recipient_email']
            ?? $ctx->variables['recipient']
            ?? $ctx->variables['user']
            ?? null;

        if ($rawRecipient instanceof User) {
            return $rawRecipient;
        }

        if ($this->userRepository !== null) {
            if (is_numeric($rawRecipient)) {
                $found = $this->userRepository->find((int) $rawRecipient);
                if ($found instanceof User) {
                    return $found;
                }
            } elseif (is_string($rawRecipient) && filter_var($rawRecipient, FILTER_VALIDATE_EMAIL)) {
                $found = $this->userRepository->findOneBy(['email' => $rawRecipient]);
                if ($found instanceof User) {
                    return $found;
                }
            }
        }

        // Si le contexte a un projet avec un propriétaire (owner)
        if ($project?->owner instanceof User) {
            return $project->owner;
        }

        return null;
    }

    private function trans(string $id, array $parameters = [], string $default = ''): string
    {
        if ($this->translator !== null) {
            $translated = $this->translator->trans($id, $parameters);
            if ($translated !== $id) {
                return $translated;
            }
        }

        if ($default !== '') {
            return strtr($default, $parameters);
        }

        return $id;
    }

    public static function getCategory(): string { return 'action'; }
    public static function getType(): string { return 'send_notification'; }
    public static function getFullType(): string { return 'action.send_notification'; }
    public static function getLabel(): string { return 'flow.action.send_notification.label'; }
    public static function getDescription(): string { return 'flow.action.send_notification.description'; }
    public static function getIcon(): string { return 'Bell'; }
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['title', 'body'],
            'properties' => [
                'recipient' => ['type' => 'string', 'title' => 'Recipient (ID or Email)', 'description' => 'Optional recipient ID or email; defaults to project owner.'],
                'title' => ['type' => 'string', 'title' => 'Title', 'template' => true],
                'body' => ['type' => 'string', 'format' => 'textarea', 'title' => 'Content', 'template' => true],
                'link' => ['type' => 'string', 'title' => 'Link', 'description' => 'Optional relative or absolute link for the notification.'],
            ],
        ];
    }
    public static function getOutputPorts(): array { return ['default']; }
}

