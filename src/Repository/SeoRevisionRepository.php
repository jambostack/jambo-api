<?php

namespace App\Repository;

use App\Entity\ContentEntry;
use App\Entity\SeoRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeoRevision>
 */
class SeoRevisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeoRevision::class);
    }

    /**
     * @return SeoRevision[]
     */
    public function findLatestByEntry(ContentEntry $entry, int $limit = 10): array
    {
        return $this->createQueryBuilder('sr')
            ->where('sr.entry = :entry')
            ->setParameter('entry', $entry)
            ->orderBy('sr.changedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findLatestByEntryWithScore(ContentEntry $entry): ?SeoRevision
    {
        return $this->createQueryBuilder('sr')
            ->where('sr.entry = :entry')
            ->andWhere('sr.seoScore IS NOT NULL')
            ->setParameter('entry', $entry)
            ->orderBy('sr.changedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
