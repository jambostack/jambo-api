<?php
namespace App\Repository;
use App\Entity\Comment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, Comment::class); }

    /** @return Comment[] */
    public function findByEntryPaginated(int $entryId, int $page = 1, int $perPage = 15): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.children', 'ch')->addSelect('ch')
            ->leftJoin('c.author', 'a')->addSelect('a')
            ->leftJoin('ch.author', 'cha')->addSelect('cha')
            ->where('c.entry = :entryId')->andWhere('c.parent IS NULL')
            ->setParameter('entryId', $entryId)
            ->orderBy('c.createdAt', 'ASC')->addOrderBy('ch.createdAt', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)
            ->getQuery()->getResult();
    }

    public function countByEntry(int $entryId): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')->where('c.entry = :entryId')
            ->setParameter('entryId', $entryId)->getQuery()->getSingleScalarResult();
    }
}
