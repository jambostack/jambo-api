<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\ContentEntry;
use App\Entity\SeoRevision;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreUpdateEventArgs;

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

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function preUpdate(ContentEntry $entry, PreUpdateEventArgs $args): void
    {
        // Vérifier si au moins un champ SEO a changé
        $hasSeoChange = false;
        foreach (self::SEO_FIELDS as $field) {
            if ($args->hasChangedField($field)) {
                $hasSeoChange = true;
                break;
            }
        }

        if (!$hasSeoChange) {
            return;
        }

        // Éviter de créer des révisions en cascade pour le listener lui-même
        // (on vérifie qu'on n'est pas déjà en train de persister une SeoRevision)
        $uow = $this->em->getUnitOfWork();
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof SeoRevision && $entity->entry?->id === $entry->id) {
                return;
            }
        }

        $revision = new SeoRevision();
        $revision->entry = $entry;
        $revision->metaTitle = $entry->metaTitle;
        $revision->metaDescription = $entry->metaDescription;
        $revision->slug = $entry->slug;
        $revision->canonicalUrl = $entry->canonicalUrl;
        $revision->ogImage = $entry->ogImage;
        $revision->seoScore = $entry->seoScore;
        $revision->changedBy = $entry->updatedBy;

        $this->em->persist($revision);
    }
}
