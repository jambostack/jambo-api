<?php

namespace App\Repository;

use App\Entity\Project;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Project>
 */
class ProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    /** @return Project[] */
    public function findByMember(User $user): array
    {
        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return $this->createQueryBuilder('p')
                ->orderBy('p.name', 'ASC')
                ->getQuery()
                ->getResult();
        }

        return $this->createQueryBuilder('p')
            ->join('p.projectMembers', 'pm')
            ->where('pm.user = :user')
            ->andWhere('pm.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', ProjectMemberStatus::Active)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve un projet par son UUID (RFC 4122), son ID numérique ou son nom exact.
     */
    public function findByIdentifier(string $identifier): ?Project
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        if (Uuid::isValid($identifier)) {
            $project = $this->findOneBy(['uuid' => Uuid::fromString($identifier)]);
            if ($project !== null) {
                return $project;
            }
        }

        if (ctype_digit($identifier)) {
            $project = $this->find((int) $identifier);
            if ($project !== null) {
                return $project;
            }
        }

        return $this->findOneBy(['name' => $identifier]);
    }
}
