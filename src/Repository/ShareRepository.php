<?php

namespace App\Repository;

use App\Entity\Share;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Share>
 */
class ShareRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Share::class);
    }

    public function findOneByTokenHash(string $tokenHash): ?Share
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    /** @return Share[] */
    public function findByEntry(\App\Entity\ContentEntry $entry): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.entry = :entry')->setParameter('entry', $entry)
            ->orderBy('s.createdAt', 'DESC')
            ->getQuery()->getResult();
    }

    public function countExpired(): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('(s.expiresAt IS NOT NULL AND s.expiresAt < :now) OR s.revokedAt IS NOT NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function purgeExpired(): int
    {
        return $this->createQueryBuilder('s')
            ->delete()
            ->where('(s.expiresAt IS NOT NULL AND s.expiresAt < :now) OR s.revokedAt IS NOT NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->execute();
    }
}
