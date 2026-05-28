<?php

namespace App\EventSubscriber;

use App\Entity\ContentEntry;
use App\Service\SearchService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(Events::postPersist)]
#[AsDoctrineListener(Events::postUpdate)]
#[AsDoctrineListener(Events::postRemove)]
class SearchIndexSubscriber
{
    public function __construct(
        private SearchService $search,
    ) {}

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof ContentEntry) {
            $this->search->indexEntry($entity);
        }
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof ContentEntry) {
            if ($entity->isDeleted()) {
                $this->search->removeEntry($entity);
            } else {
                $this->search->indexEntry($entity);
            }
        }
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof ContentEntry) {
            $this->search->removeEntry($entity);
        }
    }
}
