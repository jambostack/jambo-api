<?php

namespace App\Repository;

use App\Entity\EndUserField;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class EndUserFieldRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EndUserField::class);
    }

    /** @return EndUserField[] */
    public function findByProject(Project $project): array
    {
        return $this->findBy(['project' => $project], ['order' => 'ASC']);
    }

    public function findOneByProjectAndSlug(Project $project, string $slug): ?EndUserField
    {
        return $this->findOneBy(['project' => $project, 'slug' => $slug]);
    }
}
