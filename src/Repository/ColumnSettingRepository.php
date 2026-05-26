<?php

namespace App\Repository;

use App\Entity\Collection;
use App\Entity\ColumnSetting;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ColumnSetting>
 */
class ColumnSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ColumnSetting::class);
    }

    public function findOneByUserAndCollection(User $user, Collection $collection): ?ColumnSetting
    {
        return $this->findOneBy(['user' => $user, 'collection' => $collection]);
    }
}
