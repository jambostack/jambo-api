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

    /**
     * Trouve les automatisations actives dont le premier nœud du graphe
     * correspond au type de déclencheur (ex: trigger.content.created).
     *
     * Utilise JSON_EXTRACT (MySQL) si disponible, sinon filtre en PHP.
     *
     * @return Automation[]
     */
    public function findActiveByTriggerType(Project $project, string $triggerType): array
    {
        // Tentative avec JSON_EXTRACT (MySQL / MariaDB)
        try {
            return $this->createQueryBuilder('a')
                ->where('a.project = :project')
                ->andWhere('a.isActive = true')
                ->andWhere('JSON_EXTRACT(a.flowGraph, \'$.nodes[0].type\') = :triggerType')
                ->setParameter('project', $project)
                ->setParameter('triggerType', $triggerType)
                ->getQuery()
                ->getResult();
        } catch (\Exception) {
            // Fallback PHP si le driver ne supporte pas JSON_EXTRACT
        }

        // Fallback : récupère toutes les actives et filtre en PHP
        $allActive = $this->createQueryBuilder('a')
            ->where('a.project = :project')
            ->andWhere('a.isActive = true')
            ->setParameter('project', $project)
            ->getQuery()
            ->getResult();

        return array_filter($allActive, fn (Automation $a) =>
            isset($a->flowGraph['nodes'][0]['type'])
            && str_starts_with($a->flowGraph['nodes'][0]['type'], $triggerType)
        );
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
