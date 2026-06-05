<?php

namespace App\Repository;

use App\Entity\EndUser;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class EndUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EndUser::class);
    }

    public function findOneByProjectAndEmail(Project $project, string $email): ?EndUser
    {
        return $this->findOneBy(['project' => $project, 'email' => $email]);
    }

    /** @return EndUser[] */
    public function findByProject(Project $project, ?string $status = null): array
    {
        $criteria = ['project' => $project];
        if ($status !== null) {
            $criteria['status'] = $status;
        }
        return $this->findBy($criteria, ['createdAt' => 'DESC']);
    }

    /** @return string[] — UUIDs RFC4122 des end-users appartenant au projet dont l'UUID est dans $uuids */
    public function findProjectEndUserUuids(Project $project, array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        $binaries = [];
        foreach ($uuids as $u) {
            try {
                $binaries[] = \Symfony\Component\Uid\Uuid::fromString((string) $u)->toBinary();
            } catch (\Exception) {
                // ignore malformed UUIDs
            }
        }

        if ($binaries === []) {
            return [];
        }

        $conn         = $this->getEntityManager()->getConnection();
        $placeholders = implode(',', array_fill(0, \count($binaries), '?'));

        $rows = $conn->executeQuery(
            "SELECT uuid FROM end_user WHERE project_id = ? AND uuid IN ({$placeholders})",
            [$project->id, ...$binaries],
        )->fetchAllAssociative();

        return array_map(
            fn ($row) => \Symfony\Component\Uid\Uuid::fromBinary($row['uuid'])->toRfc4122(),
            $rows,
        );
    }
}
