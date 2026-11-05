<?php

namespace App\Repository;

use App\Entity\EmailLog;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<EmailLog> */
class EmailLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailLog::class);
    }

    /**
     * Supprime les logs d'emails antérieurs au seuil de rétention spécifié,
     * avec filtrage optionnel par projet (tenant).
     */
    public function deleteOlderThan(\DateTimeImmutable $threshold, ?Project $project = null): int
    {
        $qb = $this->createQueryBuilder('e')
            ->delete()
            ->where('e.sentAt < :threshold')
            ->setParameter('threshold', $threshold);

        if ($project !== null) {
            $qb->andWhere('e.project = :project')
               ->setParameter('project', $project);
        }

        return (int) $qb->getQuery()->execute();
    }

    /**
     * Compte les logs d'emails antérieurs au seuil de rétention (mode dry-run),
     * avec filtrage optionnel par projet (tenant).
     */
    public function countOlderThan(\DateTimeImmutable $threshold, ?Project $project = null): int
    {
        $qb = $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.sentAt < :threshold')
            ->setParameter('threshold', $threshold);

        if ($project !== null) {
            $qb->andWhere('e.project = :project')
               ->setParameter('project', $project);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
