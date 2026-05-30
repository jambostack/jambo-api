<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\ProjectMailerSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ProjectMailerSettings> */
class ProjectMailerSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectMailerSettings::class);
    }

    public function findByProject(Project $project): ?ProjectMailerSettings
    {
        return $this->findOneBy(['project' => $project]);
    }
}
