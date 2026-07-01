<?php

namespace App\Repository;

use App\Entity\Media;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

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
     * Verifie l'appartenance au projet d'une liste d'UUIDs de media.
     * Retourne uniquement les UUIDs qui appartiennent bien au projet donne.
     *
     * @param string[] $uuids
     * @return string[]
     */
    public function findProjectMediaUuids(Project $project, array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        $binaries = [];
        foreach ($uuids as $u) {
            try {
                $binaries[] = Uuid::fromString((string) $u)->toBinary();
            } catch (\Exception) {
                // ignore malformed UUIDs
            }
        }

        if ($binaries === []) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();
        $placeholders = implode(',', array_fill(0, \count($binaries), '?'));

        $rows = $conn->executeQuery(
            "SELECT uuid FROM media WHERE project_id = ? AND deleted_at IS NULL AND uuid IN ({$placeholders})",
            [$project->id, ...$binaries],
        )->fetchAllAssociative();

        return array_map(
            fn ($row) => Uuid::fromBinary($row['uuid'])->toRfc4122(),
            $rows,
        );
    }
}
