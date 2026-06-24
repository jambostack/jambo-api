<?php

namespace App\Repository;

use App\Entity\Form;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Form>
 */
class FormRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Form::class);
    }

    /**
     * @return Form[]
     */
    public function findByProject(Project $project): array
    {
        return $this->findBy(['project' => $project], ['id' => 'DESC']);
    }

    public function findOneByProjectAndSlug(Project $project, string $slug): ?Form
    {
        return $this->findOneBy(['project' => $project, 'slug' => $slug]);
    }
}
