<?php

namespace App\Repository;

use App\Entity\ContentEntry;
use App\Entity\ContentFieldGroup;
use App\Entity\Field;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContentFieldGroup>
 */
class ContentFieldGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContentFieldGroup::class);
    }

    /**
     * @return ContentFieldGroup[]
     */
    public function findByEntryAndField(ContentEntry $entry, Field $field): array
    {
        return $this->createQueryBuilder('g')
            ->leftJoin('g.values', 'v')
            ->addSelect('v')
            ->where('g.contentEntry = :entry')
            ->andWhere('g.field = :field')
            ->setParameter('entry', $entry)
            ->setParameter('field', $field)
            ->orderBy('g.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
