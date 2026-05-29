<?php
// src/Repository/HostedAppRepository.php
namespace App\Repository;

use App\Entity\HostedApp;
use App\Entity\WorkbenchProject;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<HostedApp> */
class HostedAppRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HostedApp::class);
    }

    public function findByWorkbench(WorkbenchProject $w): ?HostedApp
    {
        return $this->findOneBy(['workbenchProject' => $w]);
    }

    public function findBySubdomain(string $subdomain): ?HostedApp
    {
        return $this->findOneBy(['subdomain' => $subdomain]);
    }
}
