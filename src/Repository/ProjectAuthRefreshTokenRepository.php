<?php

namespace App\Repository;

use App\Entity\ProjectAuthRefreshToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProjectAuthRefreshToken>
 */
class ProjectAuthRefreshTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectAuthRefreshToken::class);
    }

    public function findValidToken(string $plainToken): ?ProjectAuthRefreshToken
    {
        $hash = ProjectAuthRefreshToken::hash($plainToken);

        return $this->createQueryBuilder('r')
            ->where('r.tokenHash = :hash')
            ->andWhere('r.isRevoked = false')
            ->andWhere('r.expiresAt > :now')
            ->setParameter('hash', $hash)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getOneOrNullResult();
    }
}
