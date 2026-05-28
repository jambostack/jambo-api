<?php

namespace App\Repository;

use App\Entity\Collection;
use App\Entity\Field;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Field>
 */
class FieldRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Field::class);
    }

    /** @return Field[] */
    public function findByCollection(Collection $collection): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.collection = :collection')
            ->andWhere('f.deletedAt IS NULL')
            ->setParameter('collection', $collection)
            ->orderBy('f.order', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Loads all fields of multiple collections in a single query, grouped by collection id.
     *
     * @param Collection[] $collections
     * @return array<int, Field[]>  Keyed by collection id
     */
    public function findByCollectionsGrouped(array $collections): array
    {
        if (empty($collections)) {
            return [];
        }

        $fields = $this->createQueryBuilder('f')
            ->where('f.collection IN (:collections)')
            ->andWhere('f.deletedAt IS NULL')
            ->setParameter('collections', $collections)
            ->orderBy('f.collection', 'ASC')
            ->addOrderBy('f.order', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($fields as $field) {
            $grouped[$field->collection->id][] = $field;
        }

        return $grouped;
    }

    public function findOneByCollectionAndSlug(Collection $collection, string $slug): ?Field
    {
        return $this->createQueryBuilder('f')
            ->where('f.collection = :collection')
            ->andWhere('f.slug = :slug')
            ->andWhere('f.deletedAt IS NULL')
            ->setParameter('collection', $collection)
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
