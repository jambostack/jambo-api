<?php

namespace App\Repository;

use App\Entity\WorkbenchEnvVar;
use App\Entity\WorkbenchProject;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<WorkbenchEnvVar> */
class WorkbenchEnvVarRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkbenchEnvVar::class);
    }

    /** @return WorkbenchEnvVar[] */
    public function findByWorkbench(WorkbenchProject $w): array
    {
        return $this->findBy(['workbenchProject' => $w], ['keyName' => 'ASC']);
    }

    public function findOneByKey(WorkbenchProject $w, string $keyName): ?WorkbenchEnvVar
    {
        return $this->findOneBy(['workbenchProject' => $w, 'keyName' => $keyName]);
    }
}
