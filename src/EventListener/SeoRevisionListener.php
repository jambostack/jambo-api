<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\ContentEntry;
use App\Entity\SeoRevision;
use Doctrine\ORM\Event\OnFlushEventArgs;

/**
 * Crée automatiquement un snapshot SEO (SeoRevision) lorsqu'un champ SEO
 * de ContentEntry est modifié (metaTitle, metaDescription, slug,
 * canonicalUrl, ogImage, seoScore).
 */
class SeoRevisionListener
{
    /** @var list<string> champs SEO surveillés */
    private const SEO_FIELDS = [
        'metaTitle',
        'metaDescription',
        'slug',
        'canonicalUrl',
        'ogImage',
        'seoScore',
    ];

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof ContentEntry) {
                continue;
            }

            $changeSet = $uow->getEntityChangeSet($entity);
            $hasSeoChange = false;
            foreach (self::SEO_FIELDS as $field) {
                if (isset($changeSet[$field])) {
                    $hasSeoChange = true;
                    break;
                }
            }

            if (!$hasSeoChange) {
                continue;
            }

            // Éviter de créer des révisions en cascade si déjà planifiée pour cette entité
            $alreadyScheduled = false;
            foreach ($uow->getScheduledEntityInsertions() as $inserted) {
                if ($inserted instanceof SeoRevision && $inserted->entry === $entity) {
                    $alreadyScheduled = true;
                    break;
                }
            }
            if ($alreadyScheduled) {
                continue;
            }

            $revision = new SeoRevision();
            $revision->entry = $entity;
            $revision->metaTitle = $entity->metaTitle;
            $revision->metaDescription = $entity->metaDescription;
            $revision->slug = $entity->slug;
            $revision->canonicalUrl = $entity->canonicalUrl;
            $revision->ogImage = $entity->ogImage;
            $revision->seoScore = $entity->seoScore;
            $revision->changedBy = $entity->updatedBy;

            $em->persist($revision);
            $uow->computeChangeSet($em->getClassMetadata(SeoRevision::class), $revision);
        }
    }
}
