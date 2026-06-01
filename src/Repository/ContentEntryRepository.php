<?php

namespace App\Repository;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Project;
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

    /**
     * Trouve une entrée publiée par le slug stocké dans le champ EAV 'slug'.
     */
    public function findOneByCollectionAndSlug(Collection $collection, string $slug): ?ContentEntry
    {
        return $this->createQueryBuilder('e')
            ->join('e.fieldValues', 'fv')
            ->join('fv.field', 'f')
            ->where('e.collection = :collection')
            ->andWhere('e.deletedAt IS NULL')
            ->andWhere('e.status = :status')
            ->andWhere('f.slug = :fieldSlug')
            ->andWhere('fv.textValue = :slugValue')
            ->setParameter('collection', $collection)
            ->setParameter('status', 'published')
            ->setParameter('fieldSlug', 'slug')
            ->setParameter('slugValue', $slug)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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

    /**
     * Vérifie l'appartenance au projet d'une liste d'UUIDs de content entries.
     * Retourne uniquement les UUIDs qui appartiennent bien au projet donné.
     *
     * @param string[] $uuids
     * @return string[]
     */
    public function findProjectEntryUuids(Project $project, array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        $uidObjects = array_map(fn ($u) => \Symfony\Component\Uid\Uuid::fromString($u), $uuids);

        $rows = $this->createQueryBuilder('e')
            ->select('e.uuid')
            ->where('e.project = :project')
            ->andWhere('e.uuid IN (:uuids)')
            ->andWhere('e.deletedAt IS NULL')
            ->setParameter('project', $project)
            ->setParameter('uuids', $uidObjects)
            ->getQuery()
            ->getResult();

        return array_map(fn ($row) => $row['uuid']->toRfc4122(), $rows);
    }
}
