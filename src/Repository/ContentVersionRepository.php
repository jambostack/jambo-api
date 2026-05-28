<?php

namespace App\Repository;

use App\Entity\ContentEntry;
use App\Entity\ContentVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContentVersion>
 */
class ContentVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContentVersion::class);
    }

    /** @return ContentVersion[] */
    public function findByEntry(ContentEntry $entry): array
    {
        return $this->createQueryBuilder('v')
            ->where('v.contentEntry = :entry')
            ->setParameter('entry', $entry)
            ->orderBy('v.versionNumber', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function getNextVersionNumber(ContentEntry $entry): int
    {
        $last = $this->createQueryBuilder('v')
            ->select('MAX(v.versionNumber)')
            ->where('v.contentEntry = :entry')
            ->setParameter('entry', $entry)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $last) + 1;
    }

    public function findVersionByNumber(ContentEntry $entry, int $versionNumber): ?ContentVersion
    {
        return $this->findOneBy([
            'contentEntry' => $entry,
            'versionNumber' => $versionNumber,
        ]);
    }
}
