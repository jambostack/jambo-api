<?php

namespace App\Repository;

use App\Entity\Webhook;
use App\Entity\WebhookLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WebhookLog>
 */
class WebhookLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebhookLog::class);
    }

    /** @return WebhookLog[] */
    public function findRecentByWebhook(Webhook $webhook, int $page = 1, int $perPage = 20): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.webhook = :webhook')
            ->setParameter('webhook', $webhook)
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($perPage)
            ->setFirstResult(($page - 1) * $perPage)
            ->getQuery()
            ->getResult();
    }

    public function countByWebhook(Webhook $webhook): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.webhook = :webhook')
            ->setParameter('webhook', $webhook)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
