<?php

namespace App\Repository;

use App\Entity\Automation;
use App\Entity\AutomationRun;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AutomationRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AutomationRun::class);
    }

    /** @return AutomationRun[] */
    public function findByAutomationPaginated(Automation $automation, int $page = 1, int $perPage = 20): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.automation = :automation')
            ->setParameter('automation', $automation)
            ->orderBy('r.startedAt', 'DESC')
            ->setMaxResults($perPage)
            ->setFirstResult(($page - 1) * $perPage)
            ->getQuery()
            ->getResult();
    }

    public function countByAutomation(Automation $automation): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.automation = :automation')
            ->setParameter('automation', $automation)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Supprime les exécutions d'automatisation antérieures au seuil de rétention spécifié,
     * avec filtrage optionnel par projet (tenant).
     */
    public function deleteOlderThan(\DateTimeImmutable $threshold, ?Project $project = null): int
    {
        $qb = $this->createQueryBuilder('r')
            ->delete()
            ->where('r.startedAt < :threshold')
            ->setParameter('threshold', $threshold);

        if ($project !== null) {
            $qb->andWhere('r.automation IN (SELECT auto.id FROM App\Entity\Automation auto WHERE auto.project = :project)')
               ->setParameter('project', $project);
        }

        return (int) $qb->getQuery()->execute();
    }

    /**
     * Compte les exécutions d'automatisation antérieures au seuil de rétention (mode dry-run),
     * avec filtrage optionnel par projet (tenant).
     */
    public function countOlderThan(\DateTimeImmutable $threshold, ?Project $project = null): int
    {
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.startedAt < :threshold')
            ->setParameter('threshold', $threshold);

        if ($project !== null) {
            $qb->andWhere('r.automation IN (SELECT auto.id FROM App\Entity\Automation auto WHERE auto.project = :project)')
               ->setParameter('project', $project);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Compte le nombre d'exécutions d'automatisation d'un projet depuis une date donnée.
     */
    public function countRunsForProjectSince(Project $project, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.startedAt >= :since')
            ->andWhere('r.automation IN (SELECT auto.id FROM App\Entity\Automation auto WHERE auto.project = :project)')
            ->setParameter('since', $since)
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
