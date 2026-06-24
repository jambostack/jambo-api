<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\ContentEntry;
use App\Entity\Redirect;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreUpdateEventArgs;

class SlugChangeRedirectListener
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function preUpdate(ContentEntry $entry, PreUpdateEventArgs $args): void
    {
        if (!$args->hasChangedField('slug') || $entry->status !== 'published') {
            return;
        }

        $oldSlug = $args->getOldValue('slug');
        $newSlug = $args->getNewValue('slug');

        if ($oldSlug === $newSlug || '' === $oldSlug) {
            return;
        }

        $collectionSlug = $entry->collection?->slug ?? '';
        $fromPath = '/' . $collectionSlug . '/' . $oldSlug;
        $toPath = '/' . $collectionSlug . '/' . $newSlug;

        // Rechercher une redirection auto existante pour cette entree
        $existing = $this->em->getRepository(Redirect::class)
            ->findOneBy(['sourceEntry' => $entry, 'isAuto' => true]);

        if ($existing instanceof Redirect) {
            $existing->toPath = $toPath;
            $existing->updatedAt = new \DateTimeImmutable();
        } else {
            $redirect = new Redirect();
            $redirect->project = $entry->project;
            $redirect->fromPath = $fromPath;
            $redirect->toPath = $toPath;
            $redirect->httpCode = 301;
            $redirect->isAuto = true;
            $redirect->sourceEntry = $entry;
            $redirect->createdBy = $entry->updatedBy;

            $this->em->persist($redirect);
        }
    }
}
