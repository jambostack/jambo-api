<?php

namespace App\Repository;

use App\Entity\Automation;
use App\Entity\AutomationRun;
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
}
