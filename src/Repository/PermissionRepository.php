<?php

namespace App\Repository;

use App\Entity\Permission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Permission>
 */
class PermissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Permission::class);
    }

    /** @return Permission[] grouped by their group field */
    public function findAllGrouped(): array
    {
        $permissions = $this->findBy([], ['group' => 'ASC', 'name' => 'ASC']);
        $grouped = [];
        foreach ($permissions as $p) {
            $grouped[$p->group][] = $p;
        }
        return $grouped;
    }
}
