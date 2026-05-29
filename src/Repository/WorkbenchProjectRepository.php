<?php

namespace App\Repository;

use App\Entity\WorkbenchProject;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WorkbenchProject> */
class WorkbenchProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkbenchProject::class);
    }

    /** @return WorkbenchProject[] */
    public function findByProject(Project $project): array
    {
        return $this->createQueryBuilder('w')
            ->andWhere('w.project = :project')
            ->setParameter('project', $project)
            ->orderBy('w.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
