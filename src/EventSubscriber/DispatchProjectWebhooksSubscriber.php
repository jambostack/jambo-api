<?php

namespace App\EventSubscriber;

use App\Event\ContentEvent;
use App\Message\SendWebhookMessage;
use App\Repository\WebhookRepository;
use App\Service\EavDataFormatterService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class DispatchProjectWebhooksSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private WebhookRepository $webhookRepository,
        private EavDataFormatterService $formatter,
        private MessageBusInterface $bus,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ContentEvent::CREATED => 'onContentEvent',
            ContentEvent::UPDATED => 'onContentEvent',
            ContentEvent::DELETED => 'onContentEvent',
        ];
    }

    public function onContentEvent(ContentEvent $event): void
    {
        $webhooks = $this->webhookRepository->findActiveByProjectAndEvent(
            $event->project,
            $event->eventName
        );

        if (empty($webhooks)) {
            return;
        }

        $payload = [
            'event'   => $event->eventName,
            'project' => $event->project->uuid?->toString(),
            'data'    => $this->formatter->formatEntry($event->entry),
        ];

        foreach ($webhooks as $webhook) {
            // Filter by collection if the webhook has collection restrictions
            if ($webhook->collections->count() > 0) {
                $collectionIds = $webhook->collections->map(fn ($c) => $c->id)->toArray();
                if (!in_array($event->entry->collection?->id, $collectionIds, true)) {
                    continue;
                }
            }

            $this->bus->dispatch(new SendWebhookMessage($webhook->id, $event->eventName, $payload));
        }
    }
}
