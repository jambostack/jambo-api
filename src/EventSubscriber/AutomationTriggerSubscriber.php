<?php

namespace App\EventSubscriber;

use App\Event\ContentEvent;
use App\Service\Automation\AutomationEngine;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class AutomationTriggerSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AutomationEngine $engine,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ContentEvent::CREATED        => 'onContentEvent',
            ContentEvent::UPDATED        => 'onContentEvent',
            ContentEvent::DELETED        => 'onContentEvent',
            ContentEvent::STATUS_CHANGED => 'onContentEvent',
        ];
    }

    public function onContentEvent(ContentEvent $event): void
    {
        $previousStatus = '';
        // Pour STATUS_CHANGED, on voudrait le statut précédent.
        // On le passe via une propriété dynamique si elle existe.
        if (property_exists($event, 'previousStatus')) {
            $previousStatus = $event->previousStatus;
        }

        $this->engine->dispatchForContentEvent(
            $event->eventName,
            $event->project,
            $event->entry,
            $previousStatus,
        );
    }
}
