<?php

namespace App\Repository;

use App\Entity\ContentEntry;
use App\Entity\ContentVersion;
use App\Entity\Project;
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

    public function countByEntry(ContentEntry $entry): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.contentEntry = :entry')
            ->setParameter('entry', $entry)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByProject(Project $project): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->join('v.contentEntry', 'e')
            ->where('e.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();
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

    /** @return ContentVersion[] */
    public function findOlderThanForProject(Project $project, \DateTimeImmutable $before): array
    {
        return $this->createQueryBuilder('v')
            ->join('v.contentEntry', 'e')
            ->where('e.project = :project')
            ->andWhere('v.createdAt < :before')
            ->setParameter('project', $project)
            ->setParameter('before', $before)
            ->getQuery()
            ->getResult();
    }

    public function pruneOlderThanForProject(Project $project, \DateTimeImmutable $before): int
    {
        $versions = $this->findOlderThanForProject($project, $before);
        $count = count($versions);
        if ($count === 0) {
            return 0;
        }

        $em = $this->getEntityManager();
        foreach ($versions as $version) {
            $em->remove($version);
        }
        $em->flush();

        return $count;
    }
}
