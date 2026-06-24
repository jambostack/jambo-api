<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\Redirect;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Redirect>
 */
class RedirectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Redirect::class);
    }

    /**
     * @return Redirect[]
     */
    public function findByProject(Project $project): array
    {
        return $this->findBy(['project' => $project], ['id' => 'DESC']);
    }

    /**
     * @return Redirect[]
     */
    public function findActiveByProject(Project $project): array
    {
        return $this->findBy(
            ['project' => $project, 'isEnabled' => true],
            ['id' => 'DESC'],
        );
    }

    /**
     * @return Redirect[]
     */
    public function findActiveByProjectForPath(Project $project, string $fromPath): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.project = :project')
            ->andWhere('r.isEnabled = :enabled')
            ->andWhere('r.fromPath = :fromPath')
            ->setParameter('project', $project)
            ->setParameter('enabled', true)
            ->setParameter('fromPath', $fromPath)
            ->orderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
