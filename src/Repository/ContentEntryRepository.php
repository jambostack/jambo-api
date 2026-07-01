<?php

namespace App\Repository;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
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
    public function findByCollectionPaginated(
        Collection $collection,
        int $page,
        int $perPage,
        ?string $locale = null,
        ?string $status = null,
        ?string $search = null,
        ?string $sort = 'created_at:desc',
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $assignedToId = null,
    ): array {
        $qb = $this->createQueryBuilder('e')
            ->addSelect('fv', 'cb', 'ub', 'at')
            ->leftJoin('e.fieldValues', 'fv')
            ->leftJoin('e.createdBy', 'cb')
            ->leftJoin('e.updatedBy', 'ub')
            ->leftJoin('e.assignedTo', 'at')
            ->where('e.collection = :collection')
            ->andWhere('e.deletedAt IS NULL')
            ->setParameter('collection', $collection)
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

        // Recherche full-text dans les champs de type text
        if ($search !== null && $search !== '') {
            $qb->andWhere('fv.textValue LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        // Filtre par date de création
        if ($dateFrom !== null && $dateFrom !== '') {
            $qb->andWhere('e.createdAt >= :dateFrom')
               ->setParameter('dateFrom', new \DateTimeImmutable($dateFrom));
        }
        if ($dateTo !== null && $dateTo !== '') {
            $qb->andWhere('e.createdAt <= :dateTo')
               ->setParameter('dateTo', new \DateTimeImmutable($dateTo . ' 23:59:59'));
        }

        // Tri personnalisé
        if ($sort !== null) {
            $sortParts = explode(':', $sort, 2);
            $sortField = $sortParts[0] ?? 'created_at';
            $sortDir   = isset($sortParts[1]) && strtolower($sortParts[1]) === 'asc' ? 'ASC' : 'DESC';
            $sortFieldMap = [
                'created_at'  => 'e.createdAt',
                'updated_at'  => 'e.updatedAt',
                'published_at' => 'e.publishedAt',
                'id'          => 'e.id',
            ];
            $qb->orderBy($sortFieldMap[$sortField] ?? 'e.createdAt', $sortDir);
        } else {
            $qb->orderBy('e.id', 'DESC');
        }

        // Use Paginator for correct pagination with joined OneToMany (EAV field values)
        $paginator = new Paginator($qb->getQuery(), fetchJoinCollection: true);
        return iterator_to_array($paginator);
    }

    public function countByCollection(
        Collection $collection,
        ?string $locale = null,
        ?string $status = null,
        ?string $search = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): int {
        $qb = $this->createQueryBuilder('e')
            ->select('COUNT(DISTINCT e.id)')
            ->leftJoin('e.fieldValues', 'fv')
            ->where('e.collection = :collection')
            ->andWhere('e.deletedAt IS NULL')
            ->setParameter('collection', $collection);

        if ($locale !== null) {
            $qb->andWhere('e.locale = :locale')->setParameter('locale', $locale);
        }

        if ($status !== null) {
            $qb->andWhere('e.status = :status')->setParameter('status', $status);
        }

        // Recherche full-text
        if ($search !== null && $search !== '') {
            $qb->andWhere('fv.textValue LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        // Filtre par date de création
        if ($dateFrom !== null && $dateFrom !== '') {
            $qb->andWhere('e.createdAt >= :dateFrom')
               ->setParameter('dateFrom', new \DateTimeImmutable($dateFrom));
        }
        if ($dateTo !== null && $dateTo !== '') {
            $qb->andWhere('e.createdAt <= :dateTo')
               ->setParameter('dateTo', new \DateTimeImmutable($dateTo . ' 23:59:59'));
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function countTrashedByCollection(Collection $collection, ?string $locale = null): int
    {
        $qb = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.collection = :collection')
            ->andWhere('e.deletedAt IS NOT NULL')
            ->setParameter('collection', $collection);

        if ($locale !== null) {
            $qb->andWhere('e.locale = :locale')->setParameter('locale', $locale);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return ContentEntry[]
     */
    public function findTrashedPaginated(Collection $collection, int $page = 1, int $perPage = 15, ?string $locale = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->addSelect('fv', 'cb', 'ub', 'at')
            ->leftJoin('e.fieldValues', 'fv')
            ->leftJoin('e.createdBy', 'cb')
            ->leftJoin('e.updatedBy', 'ub')
            ->leftJoin('e.assignedTo', 'at')
            ->andWhere('e.collection = :collection')
            ->andWhere('e.deletedAt IS NOT NULL')
            ->setParameter('collection', $collection)
            ->orderBy('e.deletedAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        if ($locale !== null) {
            $qb->andWhere('e.locale = :locale')->setParameter('locale', $locale);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Trouve une entrée publiée par la colonne native slug.
     */
    public function findOneByCollectionAndSlug(Collection $collection, string $slug, ?string $locale = null): ?ContentEntry
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.collection = :collection')
            ->andWhere('e.deletedAt IS NULL')
            ->andWhere('e.status = :status')
            ->andWhere('e.slug = :slug')
            ->setParameter('collection', $collection)
            ->setParameter('status', 'published')
            ->setParameter('slug', $slug)
            ->setMaxResults(1);

        if ($locale !== null) {
            $qb->andWhere('e.locale = :locale')->setParameter('locale', $locale);
        }

        return $qb->getQuery()->getOneOrNullResult();
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
