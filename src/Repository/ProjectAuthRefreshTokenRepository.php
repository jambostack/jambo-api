<?php

namespace App\Repository;

use App\Entity\EndUser;
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

    public function findByTokenHash(string $plainToken): ?ProjectAuthRefreshToken
    {
        $hash = ProjectAuthRefreshToken::hash($plainToken);

        return $this->createQueryBuilder('r')
            ->where('r.tokenHash = :hash')
            ->setParameter('hash', $hash)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function revokeAllForEndUser(EndUser $endUser): int
    {
        return (int) $this->createQueryBuilder('r')
            ->update()
            ->set('r.isRevoked', 'true')
            ->where('r.endUser = :endUser')
            ->andWhere('r.isRevoked = false')
            ->setParameter('endUser', $endUser)
            ->getQuery()
            ->execute();
    }

    public function deleteExpired(): int
    {
        return $this->createQueryBuilder('r')
            ->delete()
            ->where('r.expiresAt < :now OR r.isRevoked = true')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();
    }
}
