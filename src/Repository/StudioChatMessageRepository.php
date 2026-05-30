<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\StudioChatMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<StudioChatMessage> */
class StudioChatMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StudioChatMessage::class);
    }

    /** @return StudioChatMessage[] */
    public function findByProject(Project $project, int $limit = 100): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.project = :project')
            ->setParameter('project', $project)
            ->orderBy('m.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function deleteByProject(Project $project): void
    {
        $this->createQueryBuilder('m')
            ->delete()
            ->where('m.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->execute();
    }
}
