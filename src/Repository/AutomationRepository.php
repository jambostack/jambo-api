<?php

namespace App\Repository;

use App\Entity\Automation;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AutomationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Automation::class);
    }

    /** @return Automation[] */
    public function findByProject(Project $project): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.project = :project')
            ->setParameter('project', $project)
            ->orderBy('a.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Automation[] */
    public function findActiveByTrigger(Project $project, string $triggerType): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.project = :project')
            ->andWhere('a.isActive = true')
            ->andWhere('a.triggerType = :trigger')
            ->setParameter('project', $project)
            ->setParameter('trigger', $triggerType)
            ->orderBy('a.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Automatisations planifiées actives */
    public function findActiveScheduled(): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.isActive = true')
            ->andWhere('a.triggerType = :trigger')
            ->setParameter('trigger', 'schedule.cron')
            ->getQuery()
            ->getResult();
    }
}
