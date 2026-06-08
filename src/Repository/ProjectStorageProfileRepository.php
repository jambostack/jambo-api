<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\ProjectStorageProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ProjectStorageProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectStorageProfile::class);
    }

    /** @return ProjectStorageProfile[] */
    public function findByProject(Project $project): array
    {
        return $this->findBy(['project' => $project], ['priority' => 'ASC']);
    }

    public function findDefault(Project $project): ?ProjectStorageProfile
    {
        return $this->findOneBy(['project' => $project, 'isDefault' => true]);
    }

    /** @return ProjectStorageProfile[] */
    public function findActive(Project $project): array
    {
        return $this->findBy(['project' => $project, 'enabled' => true], ['priority' => 'ASC']);
    }

    public function countByProject(Project $project): int
    {
        return $this->count(['project' => $project]);
    }
}
