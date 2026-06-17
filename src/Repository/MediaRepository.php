<?php

namespace App\Repository;

use App\Entity\Media;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
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

        $conn = $this->getEntityManager()->getConnection();
        $binaryUuids = array_map(fn ($u) => Uuid::fromString($u)->toBinary(), $uuids);

        $sql = 'SELECT BIN_TO_UUID(m.uuid) as uuid FROM media m WHERE m.project_id = :pid AND m.uuid IN (:uuids) AND m.deleted_at IS NULL';
        $stmt = $conn->executeQuery($sql, ['pid' => $project->getId(), 'uuids' => $binaryUuids], ['uuids' => ArrayParameterType::STRING]);

        return array_column($stmt->fetchAllAssociative(), 'uuid');
    }
}
