<?php

namespace App\Repository;

use App\Entity\AuditLog;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /** @return AuditLog[] */
    public function findByProject(Project $project, int $limit = 100, int $offset = 0): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.project = :project')
            ->setParameter('project', $project)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /** @return AuditLog[] */
    public function findByTool(string $toolName, int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.toolName = :tool')
            ->setParameter('tool', $toolName)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return AuditLog[] */
    public function findErrors(int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.status = :status')
            ->setParameter('status', 'error')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByProjectToday(Project $project): int
    {
        $today = new \DateTimeImmutable('today');

        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.project = :project')
            ->andWhere('a.createdAt >= :today')
            ->setParameter('project', $project)
            ->setParameter('today', $today)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
