<?php

namespace App\Repository;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Project;
use App\Entity\User;
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
    public function findByCollectionPaginated(Collection $collection, int $page, int $perPage, ?string $locale = null, ?string $status = null, ?int $assignedToId = null): array
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

        if ($assignedToId !== null) {
            $qb->andWhere('e.assignedTo = :assignedTo')->setParameter('assignedTo', $assignedToId);
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
     * @return ContentEntry[]
     */
    public function findScheduledToPublish(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.status = :status')
            ->andWhere('e.scheduledAt <= :now')
            ->andWhere('e.deletedAt IS NULL')
            ->setParameter('status', 'scheduled')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    /**
     * @return ContentEntry[]
     */
    public function findByAssigneePaginated(Collection $collection, User $assignee, int $page = 1, int $perPage = 15, ?string $locale = null, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->andWhere('e.collection = :collection')
            ->andWhere('e.assignedTo = :assignee')
            ->andWhere('e.deletedAt IS NULL')
            ->setParameter('collection', $collection)
            ->setParameter('assignee', $assignee)
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

        // Convert UUID strings to binary form for native SQL comparison.
        // Doctrine QueryBuilder cannot bind an array of UUID objects in IN(:uuids)
        // without explicit type conversion; native DBAL avoids the issue entirely.
        $binaries = [];
        $rfc4122Map = [];
        foreach ($uuids as $u) {
            try {
                $uuid = \Symfony\Component\Uid\Uuid::fromString((string) $u);
                $bin  = $uuid->toBinary();
                $binaries[]        = $bin;
                $rfc4122Map[$bin] = $uuid->toRfc4122();
            } catch (\Exception) {
                // Skip malformed UUIDs
            }
        }

        if ($binaries === []) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();
        $placeholders = implode(',', array_fill(0, \count($binaries), '?'));

        $rows = $conn->executeQuery(
            "SELECT uuid FROM content_entry WHERE project_id = ? AND deleted_at IS NULL AND uuid IN ({$placeholders})",
            [$project->id, ...$binaries],
        )->fetchAllAssociative();

        return array_map(
            fn ($row) => \Symfony\Component\Uid\Uuid::fromBinary($row['uuid'])->toRfc4122(),
            $rows,
        );
    }
}
