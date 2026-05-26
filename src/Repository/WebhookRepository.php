<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\Webhook;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Webhook>
 */
class WebhookRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Webhook::class);
    }

    /** @return Webhook[] */
    public function findActiveByProjectAndEvent(Project $project, string $event): array
    {
        $webhooks = $this->createQueryBuilder('w')
            ->leftJoin('w.collections', 'c')
            ->addSelect('c')
            ->where('w.project = :project')
            ->andWhere('w.isActive = true')
            ->setParameter('project', $project)
            ->getQuery()
            ->getResult();

        return array_values(array_filter($webhooks, fn (Webhook $w) => in_array($event, $w->events, true)));
    }
}
