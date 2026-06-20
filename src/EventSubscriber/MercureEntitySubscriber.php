<?php

namespace App\EventSubscriber;

use App\Entity\ContentEntry;
use App\Entity\Media;
use App\Service\MercurePublisher;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Events;

/**
 * Publie automatiquement les événements temps réel sur mutations Doctrine.
 *
 * Intercepte les persist/update/remove sur ContentEntry et Media,
 * ainsi que les changements de statut workflow, et appelle MercurePublisher.
 *
 * Les uploads média sont gérés explicitement par MediaController et
 * TusController (payload enrichi via MediaSerializer). Ce subscriber
 * ne traite que la suppression média (preRemove).
 *
 * Utilise les attributs #[AsDoctrineListener] (pattern du projet)
 * plutôt que EventSubscriberInterface — les événements Doctrine ORM
 * passent par l'EventManager de Doctrine, pas par l'EventDispatcher
 * de Symfony.
 */
#[AsDoctrineListener(Events::postPersist)]
#[AsDoctrineListener(Events::postUpdate)]
#[AsDoctrineListener(Events::preRemove)]
#[AsDoctrineListener(Events::onFlush)]
#[AsDoctrineListener(Events::postFlush)]
class MercureEntitySubscriber
{
    /**
     * Événements de statut accumulés pendant onFlush, publiés dans postFlush.
     *
     * @var array<int, array{projectUuid: string, title: string, from: string, to: string}>
     */
    private array $statusEvents = [];

    public function __construct(
        private readonly MercurePublisher $mercure,
    ) {}

    // ─── Content ──────────────────────────────────────────────────────

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof ContentEntry) {
            $projectUuid = $entity->project?->uuid?->toRfc4122();
            if ($projectUuid === null) {
                return;
            }

            $this->mercure->contentChanged($projectUuid, 'created', [
                'action' => 'created',
                'uuid'   => $entity->uuid?->toRfc4122(),
            ]);
        }
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof ContentEntry) {
            $projectUuid = $entity->project?->uuid?->toRfc4122();
            if ($projectUuid === null) {
                return;
            }

            $this->mercure->contentChanged($projectUuid, 'updated', [
                'action' => 'updated',
                'uuid'   => $entity->uuid?->toRfc4122(),
            ]);
        }
    }

    // ─── Suppression (ContentEntry + Media) ───────────────────────────

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof ContentEntry) {
            $projectUuid = $entity->project?->uuid?->toRfc4122();
            if ($projectUuid === null) {
                return;
            }

            $this->mercure->contentChanged($projectUuid, 'deleted', [
                'action' => 'deleted',
                'uuid'   => $entity->uuid?->toRfc4122(),
            ]);
        }

        if ($entity instanceof Media) {
            $projectUuid = $entity->project?->uuid?->toRfc4122();
            if ($projectUuid === null) {
                return;
            }

            $this->mercure->mediaDeleted(
                $projectUuid,
                $entity->originalName ?? $entity->fileName ?? 'fichier_inconnu'
            );
        }
    }

    // ─── Changement de statut ─────────────────────────────────────────

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof ContentEntry) {
                continue;
            }

            $changeSet = $uow->getEntityChangeSet($entity);
            if (!isset($changeSet['status'])) {
                continue;
            }

            $projectUuid = $entity->project?->uuid?->toRfc4122();
            if ($projectUuid === null) {
                continue;
            }

            [$from, $to] = $changeSet['status'];

            // Utiliser l'UUID comme identifiant en attendant d'avoir un getter name
            $entryId = $entity->uuid?->toRfc4122() ?? 'inconnu';

            $this->statusEvents[] = [
                'projectUuid' => $projectUuid,
                'title'       => $entryId,
                'from'        => $from,
                'to'          => $to,
            ];
        }
    }

    /**
     * Publie les événements de statut accumulés dans onFlush.
     * Appelé APRÈS le flush, quand les données sont garanties en base.
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        foreach ($this->statusEvents as $event) {
            $this->mercure->statusChanged(
                $event['projectUuid'],
                $event['title'],
                $event['from'],
                $event['to'],
            );
        }

        $this->statusEvents = [];
    }
}
