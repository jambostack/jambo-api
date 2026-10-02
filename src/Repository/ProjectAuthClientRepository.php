<?php

namespace App\Repository;

use App\Entity\ProjectAuthClient;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProjectAuthClient>
 */
class ProjectAuthClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectAuthClient::class);
    }

    public function findActiveByClientId(string $clientId): ?ProjectAuthClient
    {
        return $this->findOneBy([
            'clientId' => $clientId,
            'isActive' => true,
        ]);
    }
}
