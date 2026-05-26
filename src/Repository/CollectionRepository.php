<?php

namespace App\Repository;

use App\Entity\Collection;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Collection>
 */
class CollectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Collection::class);
    }

    /** @return Collection[] */
    public function findByProject(Project $project): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.project = :project')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('project', $project)
            ->orderBy('c.order', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByProjectAndSlug(Project $project, string $slug): ?Collection
    {
        return $this->createQueryBuilder('c')
            ->where('c.project = :project')
            ->andWhere('c.slug = :slug')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('project', $project)
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return Collection[] */
    public function findByProjectPaginated(Project $project, int $page, int $perPage): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.project = :project')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('project', $project)
            ->orderBy('c.order', 'ASC')
            ->setMaxResults($perPage)
            ->setFirstResult(($page - 1) * $perPage)
            ->getQuery()
            ->getResult();
    }

    public function countByProject(Project $project): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.project = :project')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
