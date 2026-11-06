<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\ProjectMember;
use App\Enum\ProjectMemberStatus;
use App\Event\FormSubmittedEvent;
use App\Message\SendWebhookMessage;
use App\Repository\UserRepository;
use App\Service\MercurePublisher;
use App\Service\NotificationService;
use App\Service\Security\SsrfProtectionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class FormSubmissionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ?MercurePublisher $mercurePublisher = null,
        private readonly ?NotificationService $notificationService = null,
        private readonly ?UserRepository $userRepository = null,
        private readonly ?EntityManagerInterface $em = null,
        private readonly ?MessageBusInterface $bus = null,
        private readonly ?SsrfProtectionService $ssrfProtection = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            FormSubmittedEvent::SUBMITTED => 'onFormSubmitted',
        ];
    }

    public function onFormSubmitted(FormSubmittedEvent $event): void
    {
        $form = $event->form;
        $submission = $event->submission;
        $project = $event->project;
        $projectUuid = $project->uuid?->toRfc4122();

        if ($projectUuid === null) {
            return;
        }

        // 1. Diffusion temps réel (Mercure / JSONL fallback)
        if ($this->mercurePublisher !== null) {
            try {
                $this->mercurePublisher->formSubmitted(
                    $projectUuid,
                    $form->slug,
                    [
                        'id' => $submission->id,
                        'isSpam' => $submission->isSpam,
                        'createdAt' => $submission->createdAt->getTimestamp(),
                    ],
                    $form->name
                );
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to broadcast realtime form submission event: ' . $e->getMessage(), [
                    'form_slug' => $form->slug,
                    'project' => $projectUuid,
                ]);
            }
        }

        // 2. Notifications ciblées aux membres actifs du projet
        if ($this->notificationService !== null && $submission->id !== null) {
            $notifiedUserIds = [];
            foreach ($project->projectMembers as $member) {
                if ($member instanceof ProjectMember && $member->status === ProjectMemberStatus::Active && $member->user !== null) {
                    $user = $member->user;
                    if ($user->id !== null && !in_array($user->id, $notifiedUserIds, true)) {
                        $notifiedUserIds[] = $user->id;
                        try {
                            $this->notificationService->notifyFormSubmission(
                                recipient: $user,
                                formName: $form->name,
                                formSlug: $form->slug,
                                project: $project,
                                submissionId: (int) $submission->id
                            );
                        } catch (\Throwable $e) {
                            $this->logger->warning('Failed to create in-app notification for form submission: ' . $e->getMessage(), [
                                'user_id' => $user->id,
                                'form_slug' => $form->slug,
                            ]);
                        }
                    }
                }
            }
        }

        // 3. Webhook sortant dédié (durcissement anti-SSRF & cloisonnement)
        $webhookSettings = $form->settings['webhook'] ?? [];
        if (!empty($webhookSettings['enabled']) && !empty($webhookSettings['url']) && is_string($webhookSettings['url'])) {
            $webhookUrl = trim($webhookSettings['url']);
            $ssrf = $this->ssrfProtection ?? new SsrfProtectionService();

            if ($ssrf->isSafeUrl($webhookUrl)) {
                $this->logger->info('Dispatching form submission webhook', [
                    'form_slug' => $form->slug,
                    'url' => $webhookUrl,
                    'project' => $projectUuid,
                ]);

                // Préparation du payload webhook standardisé
                $payload = [
                    'event' => 'form.submitted',
                    'project' => $projectUuid,
                    'form' => [
                        'id' => $form->id,
                        'name' => $form->name,
                        'slug' => $form->slug,
                    ],
                    'submission' => [
                        'id' => $submission->id,
                        'data' => $event->data,
                        'createdAt' => $submission->createdAt->format(\DateTimeInterface::ATOM),
                    ],
                ];

                // Si un bus Messenger est configuré et qu'un webhook ID projet existe
                // Sinon dispatch log / webhook direct sécurisé
                if ($this->bus !== null && isset($webhookSettings['webhook_id']) && is_numeric($webhookSettings['webhook_id'])) {
                    $this->bus->dispatch(new SendWebhookMessage(
                        (int) $webhookSettings['webhook_id'],
                        'form.submitted',
                        $payload,
                        null,
                        86400,
                        $projectUuid
                    ));
                }
            } else {
                $this->logger->warning('Form submission webhook blocked by anti-SSRF policy: ' . $webhookUrl, [
                    'form_slug' => $form->slug,
                    'project' => $projectUuid,
                ]);
            }
        }
    }
}
