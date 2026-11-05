<?php

namespace App\Repository;

use App\Entity\PasswordResetToken;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PasswordResetToken>
 */
class PasswordResetTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordResetToken::class);
    }

    public function findValidByToken(string $token, ?Project $project = null): ?PasswordResetToken
    {
        $criteria = ['token' => $token];
        if ($project !== null) {
            $criteria['project'] = $project;
        }
        $record = $this->findOneBy($criteria);
        if ($record === null || $record->isExpired()) {
            return null;
        }
        return $record;
    }

    public function countExpired(?Project $project = null): int
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.expiresAt < :now')
            ->setParameter('now', new \DateTimeImmutable());

        if ($project !== null) {
            $qb->andWhere('t.project = :project')
                ->setParameter('project', $project);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function deleteExpired(?Project $project = null): int
    {
        $qb = $this->createQueryBuilder('t')
            ->delete()
            ->where('t.expiresAt < :now')
            ->setParameter('now', new \DateTimeImmutable());

        if ($project !== null) {
            $qb->andWhere('t.project = :project')
                ->setParameter('project', $project);
        }

        return (int) $qb->getQuery()->execute();
    }
}
