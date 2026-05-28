<?php

namespace App\EventSubscriber;

use App\Entity\ContentEntry;
use App\Service\VersioningService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(Events::preUpdate)]
class AutoVersionSubscriber
{
    public function __construct(
        private VersioningService $versioning,
    ) {}

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$entity instanceof ContentEntry) {
            return;
        }

        // Ne pas versionner les soft-delete
        if ($entity->isDeleted()) {
            return;
        }

        // Ne versionner que si les champs ont changé (pas juste le statut ou updatedAt)
        $changes = $args->getEntityChangeSet();
        $significantChanges = array_diff_key($changes, array_flip(['status', 'updatedAt', 'deletedAt']));

        if (empty($significantChanges)) {
            return;
        }

        $this->versioning->createVersion($entity, 'Auto-save avant modification');
    }
}
