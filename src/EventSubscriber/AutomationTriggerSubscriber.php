<?php

namespace App\EventSubscriber;

use App\Entity\Automation;
use App\Event\ContentEvent;
use App\Repository\AutomationRepository;
use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowInterpreter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class AutomationTriggerSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AutomationRepository $automationRepo,
        private readonly FlowInterpreter $interpreter,
        private readonly EntityManagerInterface $em,
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
        $graph = $automation->flowGraph;
        if (!$graph || empty($graph['nodes'])) return;

        $ctx = new FlowContext(
            automationId: $automation->id,
            projectUuid: $automation->project?->uuid?->toRfc4122() ?? '',
            debugMode: $automation->debugMode,
        );

        $result = $this->interpreter->executeFlow($graph, $payload, $ctx);

        // Met à jour lastRunAt
        $automation->lastRunAt = new \DateTimeImmutable();
        $this->em->flush();

        // Note: la création d'AutomationRun avec stepLog est prévue pour v1.14
        // (le mécanisme de Run existant reste fonctionnel)
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
