<?php

namespace App\Repository;

use App\Entity\AssetUploadIntent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AssetUploadIntent>
 */
class AssetUploadIntentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssetUploadIntent::class);
    }

    public function findPendingByUuid(string $uuid): ?AssetUploadIntent
    {
        return $this->createQueryBuilder('i')
            ->where('i.uuid = :uuid')
            ->andWhere('i.status = :status')
            ->andWhere('i.expiresAt > :now')
            ->setParameter('uuid', $uuid)
            ->setParameter('status', AssetUploadIntent::STATUS_PENDING)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getOneOrNullResult();
    }
}
