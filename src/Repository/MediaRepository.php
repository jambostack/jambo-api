<?php

namespace App\Repository;

use App\Entity\Media;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Media>
 */
class MediaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Media::class);
    }

    /** @return Media[] */
    public function findByProjectPaginated(Project $project, int $page, int $perPage): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.project = :project')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('project', $project)
            ->orderBy('m.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countByProject(Project $project): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.project = :project')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Vérifie l'appartenance au projet d'une liste d'UUIDs de média.
     * Retourne uniquement les UUIDs qui appartiennent bien au projet donné.
     *
     * @param string[] $uuids
     * @return string[]
     */
    public function findProjectMediaUuids(Project $project, array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        // findOneBy gère correctement la conversion UUID binaire (même logique que
        // AssetController::show). On évite le QueryBuilder dont le IN() ne convertit
        // pas fiablement les UUIDs en binaire avec Doctrine ORM 3.x.
        $validUuids = [];
        foreach ($uuids as $uuid) {
            $media = $this->findOneBy(['uuid' => $uuid, 'project' => $project, 'deletedAt' => null]);
            if ($media !== null) {
                $validUuids[] = $media->uuid->toRfc4122();
            }
        }

        return $validUuids;
    }
}
