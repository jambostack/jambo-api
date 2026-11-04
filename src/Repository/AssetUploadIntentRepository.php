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

    /**
     * Retourne les intentions d'upload expirées encore au statut pending.
     *
     * @return AssetUploadIntent[]
     */
    public function findExpiredPending(?\App\Entity\Project $project = null, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('i')
            ->where('i.status = :status')
            ->andWhere('i.expiresAt <= :now')
            ->setParameter('status', AssetUploadIntent::STATUS_PENDING)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('i.expiresAt', 'ASC')
            ->setMaxResults($limit);

        if ($project !== null) {
            $qb->andWhere('i.project = :project')
               ->setParameter('project', $project);
        }

        return $qb->getQuery()->getResult();
    }

    public function countExpiredPending(?\App\Entity\Project $project = null): int
    {
        $qb = $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.status = :status')
            ->andWhere('i.expiresAt <= :now')
            ->setParameter('status', AssetUploadIntent::STATUS_PENDING)
            ->setParameter('now', new \DateTimeImmutable());

        if ($project !== null) {
            $qb->andWhere('i.project = :project')
               ->setParameter('project', $project);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Supprime définitivement les intentions clôturées ou expirées antérieures au seuil de rétention.
     */
    public function deleteOldIntents(\DateTimeImmutable $threshold): int
    {
        return (int) $this->createQueryBuilder('i')
            ->delete()
            ->where('i.createdAt < :threshold')
            ->andWhere('i.status IN (:terminalStatuses)')
            ->setParameter('threshold', $threshold)
            ->setParameter('terminalStatuses', [
                AssetUploadIntent::STATUS_COMPLETED,
                AssetUploadIntent::STATUS_ABORTED,
                AssetUploadIntent::STATUS_EXPIRED,
            ])
            ->getQuery()
            ->execute();
    }

    public function countOldIntents(\DateTimeImmutable $threshold): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.createdAt < :threshold')
            ->andWhere('i.status IN (:terminalStatuses)')
            ->setParameter('threshold', $threshold)
            ->setParameter('terminalStatuses', [
                AssetUploadIntent::STATUS_COMPLETED,
                AssetUploadIntent::STATUS_ABORTED,
                AssetUploadIntent::STATUS_EXPIRED,
            ])
            ->getQuery()
            ->getSingleScalarResult();
    }
}
