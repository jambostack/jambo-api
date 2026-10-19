<?php

namespace App\EventSubscriber;

use App\Entity\Automation;
use App\Entity\AutomationRun;
use App\Event\ContentEvent;
use App\Message\ExecuteAutomationMessage;
use App\Repository\AutomationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class AutomationTriggerSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AutomationRepository $automationRepo,
        private readonly MessageBusInterface $bus,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
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
        $project = $event->project;
        $entry = $event->entry;
        $eventName = $event->eventName;
        $previousStatus = $event->previousStatus;

        $triggerType = match ($eventName) {
            ContentEvent::CREATED        => 'trigger.content.created',
            ContentEvent::UPDATED        => 'trigger.content.updated',
            ContentEvent::DELETED        => 'trigger.content.deleted',
            ContentEvent::STATUS_CHANGED => 'trigger.content.status_changed',
        };

        $automations = $this->automationRepo->findActiveByTriggerType($project, $triggerType);

        if (empty($automations)) return;

        $payload = $this->buildPayload($eventName, $project, $entry, $previousStatus);

        foreach ($automations as $automation) {
            $this->executeAutomation($automation, $payload);
        }
    }

    private function executeAutomation(Automation $automation, array $payload): void
    {
        try {
            $run = new AutomationRun();
            $run->automation = $automation;
            $run->status = 'pending';
            if ($automation->debugMode) {
                $run->triggerPayload = $payload;
            }
            $this->em->persist($run);
            $this->em->flush();

            $this->bus->dispatch(new ExecuteAutomationMessage(
                automationId: $automation->id,
                runId: $run->id,
                triggerPayload: $payload,
                projectUuid: $automation->project?->uuid?->toRfc4122() ?? '',
                debugMode: (bool) $automation->debugMode,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('Failed to dispatch automation message in subscriber', [
                'automation_id' => $automation->id,
                'automation_name' => $automation->name,
                'project_uuid' => $automation->project?->uuid?->toRfc4122(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildPayload(string $eventName, $project, $entry, string $previousStatus): array
    {
        return [
            'trigger' => $eventName,
            'project_uuid' => $project->uuid?->toRfc4122(),
            'timestamp' => time(),
            'entry' => [
                'id' => $entry->id,
                'uuid' => $entry->uuid?->toRfc4122(),
                'title' => $entry->name ?? 'Sans titre',
                'slug' => $entry->slug,
                'status' => $entry->status,
                'previous_status' => $previousStatus,
                'collection_slug' => $entry->collection?->slug ?? '',
                'collection_id' => $entry->collection?->id,
                'created_at' => $entry->createdAt?->format('c'),
                'updated_at' => $entry->updatedAt?->format('c'),
            ],
        ];
    }
}
