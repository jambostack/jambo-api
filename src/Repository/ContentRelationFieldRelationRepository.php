<?php

namespace App\Repository;

use App\Entity\ContentRelationFieldRelation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContentRelationFieldRelation>
 */
class ContentRelationFieldRelationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContentRelationFieldRelation::class);
    }
}
