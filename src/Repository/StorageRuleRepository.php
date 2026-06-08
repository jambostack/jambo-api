<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\StorageRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class StorageRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StorageRule::class);
    }

    /** @return StorageRule[] */
    public function findByProject(Project $project): array
    {
        return $this->findBy(['project' => $project], ['priority' => 'ASC']);
    }
}
