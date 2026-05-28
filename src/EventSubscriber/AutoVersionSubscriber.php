<?php

namespace App\EventSubscriber;

use App\Entity\ContentEntry;
use App\Entity\ContentFieldValue;
use App\Service\EavDataFormatterService;
use App\Service\VersioningService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(Events::onFlush)]
class AutoVersionSubscriber
{
    public function __construct(
        private VersioningService $versioning,
        private EavDataFormatterService $formatter,
    ) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof ContentEntry) {
                continue;
            }

            if ($entity->isDeleted()) {
                continue;
            }

            $changes = $uow->getEntityChangeSet($entity);
            $significantChanges = array_diff_key($changes, array_flip(['status', 'updatedAt', 'deletedAt']));

            if (empty($significantChanges)) {
                continue;
            }

            // Reconstruire l'état ANCIEN (avant la modification courante)
            $oldSnapshot = $this->buildOldSnapshot($entity, $changes);

            $this->versioning->createSnapshot($entity, $oldSnapshot, 'Auto-save');
        }
    }

    /**
     * Reconstitue un snapshot à partir de l'état antérieur via le changeSet.
     */
    private function buildOldSnapshot(ContentEntry $entry, array $changeSet): array
    {
        $current = $this->formatter->formatEntry($entry);

        foreach ($changeSet as $field => [$oldValue, $newValue]) {
            if (isset($current[$field])) {
                $current[$field] = $this->formatOldValue($oldValue);
            }
        }

        return $current;
    }

    private function formatOldValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if ($value instanceof \Doctrine\Common\Collections\Collection) {
            return $value->toArray();
        }

        return $value;
    }
}
