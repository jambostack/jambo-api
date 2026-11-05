<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\ProjectAuthAuthorizationCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProjectAuthAuthorizationCode>
 */
class ProjectAuthAuthorizationCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectAuthAuthorizationCode::class);
    }

    public function findValidCode(string $code): ?ProjectAuthAuthorizationCode
    {
        return $this->createQueryBuilder('c')
            ->where('c.code = :code')
            ->andWhere('c.isUsed = false')
            ->andWhere('c.expiresAt > :now')
            ->setParameter('code', $code)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countExpired(?Project $project = null): int
    {
        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.expiresAt < :now OR c.isUsed = true')
            ->setParameter('now', new \DateTimeImmutable());

        if ($project !== null) {
            $qb->join('c.endUser', 'u')
               ->andWhere('u.project = :project')
               ->setParameter('project', $project);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function deleteExpired(?Project $project = null): int
    {
        $qb = $this->createQueryBuilder('c')
            ->delete()
            ->where('c.expiresAt < :now OR c.isUsed = true')
            ->setParameter('now', new \DateTimeImmutable());

        if ($project !== null) {
            $qb->andWhere('c.endUser IN (SELECT u.id FROM App\Entity\EndUser u WHERE u.project = :project)')
               ->setParameter('project', $project);
        }

        return (int) $qb->getQuery()->execute();
    }
}
