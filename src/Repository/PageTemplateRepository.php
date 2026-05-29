<?php

namespace App\Repository;

use App\Entity\PageTemplate;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PageTemplate>
 */
class PageTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PageTemplate::class);
    }

    /** @return PageTemplate[] */
    public function findByProject(Project $project): array
    {
        return $this->createQueryBuilder('pt')
            ->andWhere('pt.project = :project')
            ->setParameter('project', $project)
            ->orderBy('pt.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByProjectAndSlug(Project $project, string $slug): ?PageTemplate
    {
        return $this->findOneBy(['project' => $project, 'slug' => $slug]);
    }
}
