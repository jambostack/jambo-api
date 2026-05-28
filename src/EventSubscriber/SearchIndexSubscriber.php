<?php

namespace App\EventSubscriber;

use App\Entity\ContentEntry;
use App\Service\SearchService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(Events::onFlush)]
#[AsDoctrineListener(Events::postFlush)]
class SearchIndexSubscriber
{
    /** @var array<string, ContentEntry> */
    private array $toIndex = [];

    /** @var array<string, ContentEntry> */
    private array $toRemove = [];

    public function __construct(
        private SearchService $search,
    ) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof ContentEntry) {
                $this->toIndex[$entity->uuid->toRfc4122()] = $entity;
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof ContentEntry) {
                if ($entity->isDeleted()) {
                    $this->toRemove[$entity->uuid->toRfc4122()] = $entity;
                    unset($this->toIndex[$entity->uuid->toRfc4122()]);
                } else {
                    $this->toIndex[$entity->uuid->toRfc4122()] = $entity;
                }
            }
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($entity instanceof ContentEntry) {
                $this->toRemove[$entity->uuid->toRfc4122()] = $entity;
                unset($this->toIndex[$entity->uuid->toRfc4122()]);
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        foreach ($this->toIndex as $entry) {
            try {
                $this->search->indexEntry($entry);
            } catch (\Throwable) {}
        }
        foreach ($this->toRemove as $entry) {
            try {
                $this->search->removeEntry($entry);
            } catch (\Throwable) {}
        }

        $this->toIndex = [];
        $this->toRemove = [];
    }
}
