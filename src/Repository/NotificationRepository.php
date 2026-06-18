<?php

namespace App\Repository;

use App\Entity\Notification;
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

    public function markAllAsRead(User $user): void
    {
        $this->createQueryBuilder('n')->update()
            ->set('n.readAt', ':now')->setParameter('now', new \DateTimeImmutable())
            ->where('n.recipient = :user')->andWhere('n.readAt IS NULL')
            ->setParameter('user', $user)->getQuery()->execute();
    }
}
