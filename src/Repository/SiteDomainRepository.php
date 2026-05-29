<?php

namespace App\Repository;

use App\Entity\SiteDomain;
use App\Entity\WorkbenchProject;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SiteDomain> */
class SiteDomainRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SiteDomain::class);
    }

    public function findByDomain(string $domain): ?SiteDomain
    {
        return $this->findOneBy(['domain' => $domain]);
    }

    /** @return SiteDomain[] */
    public function findByWorkbench(WorkbenchProject $w): array
    {
        return $this->findBy(['workbenchProject' => $w], ['isPrimary' => 'DESC', 'createdAt' => 'ASC']);
    }
}
