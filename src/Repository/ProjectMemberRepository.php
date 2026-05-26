<?php
// src/Repository/ProjectMemberRepository.php
namespace App\Repository;

use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProjectMember>
 */
class ProjectMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectMember::class);
    }

    public function findActiveByUserAndProject(User $user, Project $project): ?ProjectMember
    {
        return $this->findOneBy([
            'user'    => $user,
            'project' => $project,
            'status'  => ProjectMemberStatus::Active,
        ]);
    }

    public function findByProject(Project $project): array
    {
        return $this->createQueryBuilder('pm')
            ->leftJoin('pm.user', 'u')
            ->leftJoin('pm.role', 'r')
            ->leftJoin('pm.invitedBy', 'ib')
            ->addSelect('u', 'r', 'ib')
            ->where('pm.project = :project')
            ->setParameter('project', $project)
            ->orderBy('pm.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countActiveByProject(Project $project): int
    {
        return (int) $this->createQueryBuilder('pm')
            ->select('COUNT(pm.id)')
            ->where('pm.project = :project')
            ->andWhere('pm.status = :status')
            ->setParameter('project', $project)
            ->setParameter('status', ProjectMemberStatus::Active)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findSoleManagePermissionMember(Project $project): ?ProjectMember
    {
        $results = $this->createQueryBuilder('pm')
            ->join('pm.role', 'r')
            ->join('r.permissions', 'p')
            ->where('pm.project = :project')
            ->andWhere('pm.status = :status')
            ->andWhere('p.name = :perm')
            ->setParameter('project', $project)
            ->setParameter('status', ProjectMemberStatus::Active)
            ->setParameter('perm', 'project.manage')
            ->getQuery()
            ->getResult();

        return count($results) === 1 ? $results[0] : null;
    }
}
