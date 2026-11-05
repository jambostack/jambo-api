<?php

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /** @return Notification[] */
    public function findByRecipientPaginated(User $user, int $page = 1, int $perPage = 20, bool $unreadOnly = false): array
    {
        $qb = $this->createQueryBuilder('n')
            ->where('n.recipient = :user')->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage);
        if ($unreadOnly) $qb->andWhere('n.readAt IS NULL');
        return $qb->getQuery()->getResult();
    }

    public function countUnreadByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('n')->select('COUNT(n.id)')
            ->where('n.recipient = :user')->andWhere('n.readAt IS NULL')
            ->setParameter('user', $user)->getQuery()->getSingleScalarResult();
    }

    /** @return Notification[] */
    public function findByRecipientAndProjectPaginated(User $user, Project $project, int $page = 1, int $perPage = 20, bool $unreadOnly = false): array
    {
        $projectUuid = $project->uuid?->toRfc4122() ?? '';
        $projectId = $project->id ? (string) $project->id : '';

        $qb = $this->createQueryBuilder('n')
            ->where('n.recipient = :user')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        $this->applyProjectFilter($qb, $projectUuid, $projectId);

        if ($unreadOnly) {
            $qb->andWhere('n.readAt IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    public function countUnreadByUserAndProject(User $user, Project $project): int
    {
        $projectUuid = $project->uuid?->toRfc4122() ?? '';
        $projectId = $project->id ? (string) $project->id : '';

        $qb = $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.recipient = :user')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('user', $user);

        $this->applyProjectFilter($qb, $projectUuid, $projectId);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function markAllAsReadForProject(User $user, Project $project): void
    {
        $projectUuid = $project->uuid?->toRfc4122() ?? '';
        $projectId = $project->id ? (string) $project->id : '';

        $qb = $this->createQueryBuilder('n')->update()
            ->set('n.readAt', ':now')
            ->setParameter('now', new \DateTimeImmutable())
            ->where('n.recipient = :user')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('user', $user);

        $this->applyProjectFilter($qb, $projectUuid, $projectId);

        $qb->getQuery()->execute();
    }

    public function markAllAsRead(User $user): void
    {
        $this->createQueryBuilder('n')->update()
            ->set('n.readAt', ':now')->setParameter('now', new \DateTimeImmutable())
            ->where('n.recipient = :user')->andWhere('n.readAt IS NULL')
            ->setParameter('user', $user)->getQuery()->execute();
    }

    /**
     * Compte les notifications lues créées avant une certaine date,
     * avec filtrage optionnel par projet (tenant).
     */
    public function countReadOlderThan(\DateTimeImmutable $threshold, ?Project $project = null): int
    {
        $qb = $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.readAt IS NOT NULL')
            ->andWhere('n.createdAt < :threshold')
            ->setParameter('threshold', $threshold);

        if ($project !== null) {
            $projectUuid = $project->uuid?->toRfc4122() ?? '';
            $projectId = $project->id ? (string) $project->id : '';
            $this->applyProjectFilter($qb, $projectUuid, $projectId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Supprime les notifications lues créées avant une certaine date pour libérer l'espace,
     * avec filtrage optionnel par projet (tenant).
     */
    public function deleteReadOlderThan(\DateTimeImmutable $threshold, ?Project $project = null): int
    {
        $qb = $this->createQueryBuilder('n')
            ->delete()
            ->where('n.readAt IS NOT NULL')
            ->andWhere('n.createdAt < :threshold')
            ->setParameter('threshold', $threshold);

        if ($project !== null) {
            $projectUuid = $project->uuid?->toRfc4122() ?? '';
            $projectId = $project->id ? (string) $project->id : '';
            $this->applyProjectFilter($qb, $projectUuid, $projectId);
        }

        return (int) $qb->getQuery()->execute();
    }

    public function countByRecipient(User $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.recipient = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Marque un lot d'identifiants de notifications comme lues pour un utilisateur donné.
     *
     * @param int[] $ids
     */
    public function markBatchAsRead(User $user, array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        return (int) $this->createQueryBuilder('n')->update()
            ->set('n.readAt', ':now')
            ->setParameter('now', new \DateTimeImmutable())
            ->where('n.recipient = :user')
            ->andWhere('n.id IN (:ids)')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }

    /**
     * Supprime un lot de notifications appartenant à l'utilisateur.
     *
     * @param int[] $ids
     */
    public function deleteBatch(User $user, array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        return (int) $this->createQueryBuilder('n')->delete()
            ->where('n.recipient = :user')
            ->andWhere('n.id IN (:ids)')
            ->setParameter('user', $user)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }

    /**
     * Supprime les notifications lues créées avant un seuil temporel pour un utilisateur précis.
     */
    public function pruneReadOlderThanForUser(User $user, \DateTimeImmutable $threshold, ?Project $project = null): int
    {
        $qb = $this->createQueryBuilder('n')
            ->delete()
            ->where('n.recipient = :user')
            ->andWhere('n.readAt IS NOT NULL')
            ->andWhere('n.createdAt < :threshold')
            ->setParameter('user', $user)
            ->setParameter('threshold', $threshold);

        if ($project !== null) {
            $projectUuid = $project->uuid?->toRfc4122() ?? '';
            $projectId = $project->id ? (string) $project->id : '';
            $this->applyProjectFilter($qb, $projectUuid, $projectId);
        }

        return (int) $qb->getQuery()->execute();
    }

    /**
     * Émonde les notifications les plus anciennes d'un utilisateur au-delà du quota maximum autorisé.
     */
    public function pruneOldestForUser(User $user, int $keepCount = 1000): int
    {
        $total = $this->countByRecipient($user);
        if ($total <= $keepCount) {
            return 0;
        }

        $excess = $total - $keepCount;

        // On supprime d'abord les notifications déjà lues les plus anciennes
        $oldestReadIds = $this->createQueryBuilder('n')
            ->select('n.id')
            ->where('n.recipient = :user')
            ->andWhere('n.readAt IS NOT NULL')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', 'ASC')
            ->setMaxResults($excess)
            ->getQuery()
            ->getSingleColumnResult();

        if (!empty($oldestReadIds)) {
            return $this->deleteBatch($user, $oldestReadIds);
        }

        // Si l'utilisateur n'a aucune notification lue, on supprime les plus anciennes non lues
        $oldestIds = $this->createQueryBuilder('n')
            ->select('n.id')
            ->where('n.recipient = :user')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', 'ASC')
            ->setMaxResults($excess)
            ->getQuery()
            ->getSingleColumnResult();

        if (!empty($oldestIds)) {
            return $this->deleteBatch($user, $oldestIds);
        }

        return 0;
    }

    private function applyProjectFilter(\Doctrine\ORM\QueryBuilder $qb, string $projectUuid, string $projectId): void
    {
        if ($projectUuid !== '' && $projectId !== '') {
            $qb->andWhere('(n.link LIKE :projectUuidPattern OR n.link LIKE :projectIdPattern)')
                ->setParameter('projectUuidPattern', '%' . $projectUuid . '%')
                ->setParameter('projectIdPattern', '%/projects/' . $projectId . '/%');
        } elseif ($projectUuid !== '') {
            $qb->andWhere('n.link LIKE :projectUuidPattern')
                ->setParameter('projectUuidPattern', '%' . $projectUuid . '%');
        } elseif ($projectId !== '') {
            $qb->andWhere('n.link LIKE :projectIdPattern')
                ->setParameter('projectIdPattern', '%/projects/' . $projectId . '/%');
        }
    }
}

