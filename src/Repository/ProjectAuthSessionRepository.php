<?php

namespace App\Repository;

use App\Entity\EndUser;
use App\Entity\ProjectAuthSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProjectAuthSession>
 */
class ProjectAuthSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectAuthSession::class);
    }

    /**
     * @return ProjectAuthSession[]
     */
    public function findByEndUser(EndUser $endUser): array
    {
        return $this->findBy(['endUser' => $endUser], ['lastActiveAt' => 'DESC']);
    }

    public function revokeSession(string $sessionId): void
    {
        $session = $this->findOneBy(['sessionId' => $sessionId]);
        if ($session) {
            $this->getEntityManager()->remove($session);
            $this->getEntityManager()->flush();
        }
    }
}
