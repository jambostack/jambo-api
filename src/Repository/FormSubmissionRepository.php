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

    public function countByForm(Form $form, ?string $spamFilter = null): int
    {
        $qb = $this->createQueryBuilder('fs')
            ->select('COUNT(fs.id)')
            ->where('fs.form = :form')
            ->setParameter('form', $form);

        if ($spamFilter === 'spam') {
            $qb->andWhere('fs.isSpam = :isSpam')->setParameter('isSpam', true);
        } elseif ($spamFilter === 'ham') {
            $qb->andWhere('fs.isSpam = :isSpam')->setParameter('isSpam', false);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return FormSubmission[]
     */
    public function findPaginatedByForm(Form $form, int $page = 1, int $perPage = 25, ?string $spamFilter = null): array
    {
        $qb = $this->createQueryBuilder('fs')
            ->where('fs.form = :form')
            ->setParameter('form', $form)
            ->orderBy('fs.createdAt', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage);

        if ($spamFilter === 'spam') {
            $qb->andWhere('fs.isSpam = :isSpam')->setParameter('isSpam', true);
        } elseif ($spamFilter === 'ham') {
            $qb->andWhere('fs.isSpam = :isSpam')->setParameter('isSpam', false);
        }

        return $qb->getQuery()->getResult();
    }

    public function deleteByForm(Form $form): int
    {
        return $this->createQueryBuilder('fs')
            ->delete()
            ->where('fs.form = :form')
            ->setParameter('form', $form)
            ->getQuery()
            ->execute();
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
