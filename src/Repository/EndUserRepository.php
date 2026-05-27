<?php

namespace App\Repository;

use App\Entity\EndUser;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class EndUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EndUser::class);
    }

    public function findOneByProjectAndEmail(Project $project, string $email): ?EndUser
    {
        return $this->findOneBy(['project' => $project, 'email' => $email]);
    }

    /** @return EndUser[] */
    public function findByProject(Project $project, ?string $status = null): array
    {
        $criteria = ['project' => $project];
        if ($status !== null) {
            $criteria['status'] = $status;
        }
        return $this->findBy($criteria, ['createdAt' => 'DESC']);
    }
}
