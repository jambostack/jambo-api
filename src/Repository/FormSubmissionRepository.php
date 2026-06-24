<?php

namespace App\Repository;

use App\Entity\Form;
use App\Entity\FormSubmission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FormSubmission>
 */
class FormSubmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FormSubmission::class);
    }

    /**
     * @return FormSubmission[]
     */
    public function findByForm(Form $form): array
    {
        return $this->findBy(['form' => $form], ['createdAt' => 'DESC']);
    }

    public function countUnread(Form $form): int
    {
        return (int) $this->createQueryBuilder('fs')
            ->select('COUNT(fs.id)')
            ->where('fs.form = :form')
            ->andWhere('fs.isRead = :isRead')
            ->setParameter('form', $form)
            ->setParameter('isRead', false)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
