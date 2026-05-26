<?php

namespace App\Repository;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContentEntry>
 */
class ContentEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContentEntry::class);
    }

    /** @return ContentEntry[] */
    public function findByCollectionPaginated(Collection $collection, int $page, int $perPage, ?string $locale = null, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.collection = :collection')
            ->andWhere('e.deletedAt IS NULL')
            ->setParameter('collection', $collection)
            ->orderBy('e.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        if ($locale !== null) {
            $qb->andWhere('e.locale = :locale')->setParameter('locale', $locale);
        }

        if ($status !== null) {
            $qb->andWhere('e.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    public function countByCollection(Collection $collection, ?string $locale = null, ?string $status = null): int
    {
        $qb = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.collection = :collection')
            ->andWhere('e.deletedAt IS NULL')
            ->setParameter('collection', $collection);

        if ($locale !== null) {
            $qb->andWhere('e.locale = :locale')->setParameter('locale', $locale);
        }

        if ($status !== null) {
            $qb->andWhere('e.status = :status')->setParameter('status', $status);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return ContentEntry[] */
    public function findTrashed(Collection $collection): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.collection = :collection')
            ->andWhere('e.deletedAt IS NOT NULL')
            ->setParameter('collection', $collection)
            ->orderBy('e.deletedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function hasNonDeletedEntryForCollection(Collection $collection): bool
    {
        $count = (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.collection = :collection')
            ->andWhere('e.deletedAt IS NULL')
            ->setParameter('collection', $collection)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
