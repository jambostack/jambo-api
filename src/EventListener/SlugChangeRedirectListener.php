<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\ContentEntry;
use App\Entity\Redirect;
use Doctrine\ORM\Event\OnFlushEventArgs;

class SlugChangeRedirectListener
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof ContentEntry || $entity->status !== 'published') {
                continue;
            }

            $changeSet = $uow->getEntityChangeSet($entity);
            if (!isset($changeSet['slug'])) {
                continue;
            }

            [$oldSlug, $newSlug] = $changeSet['slug'];
            if ($oldSlug === $newSlug || '' === $oldSlug || $oldSlug === null) {
                continue;
            }

            $collectionSlug = $entity->collection?->slug ?? '';
            $fromPath = '/' . $collectionSlug . '/' . $oldSlug;
            $toPath = '/' . $collectionSlug . '/' . $newSlug;

            // Rechercher une redirection auto existante pour cette entrée
            $existing = $em->getRepository(Redirect::class)
                ->findOneBy(['sourceEntry' => $entity, 'isAuto' => true]);

            if ($existing instanceof Redirect) {
                $existing->toPath = $toPath;
                $existing->updatedAt = new \DateTimeImmutable();
                $uow->computeChangeSet($em->getClassMetadata(Redirect::class), $existing);
            } else {
                $redirect = new Redirect();
                $redirect->project = $entity->project;
                $redirect->fromPath = $fromPath;
                $redirect->toPath = $toPath;
                $redirect->httpCode = 301;
                $redirect->isAuto = true;
                $redirect->sourceEntry = $entity;
                $redirect->createdBy = $entity->updatedBy;

                $em->persist($redirect);
                $uow->computeChangeSet($em->getClassMetadata(Redirect::class), $redirect);
            }
        }
    }
}
