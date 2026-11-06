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

    public function countByForm(Form $form, ?string $spamFilter = null, ?string $readFilter = null): int
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

        if ($readFilter === 'read') {
            $qb->andWhere('fs.isRead = :isRead')->setParameter('isRead', true);
        } elseif ($readFilter === 'unread') {
            $qb->andWhere('fs.isRead = :isRead')->setParameter('isRead', false);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return FormSubmission[]
     */
    public function findPaginatedByForm(Form $form, int $page = 1, int $perPage = 25, ?string $spamFilter = null, ?string $readFilter = null): array
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

        if ($readFilter === 'read') {
            $qb->andWhere('fs.isRead = :isRead')->setParameter('isRead', true);
        } elseif ($readFilter === 'unread') {
            $qb->andWhere('fs.isRead = :isRead')->setParameter('isRead', false);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @param int[] $ids
     */
    public function batchMarkRead(Form $form, array $ids, bool $isRead): int
    {
        if (empty($ids)) {
            return 0;
        }

        return (int) $this->createQueryBuilder('fs')
            ->update()
            ->set('fs.isRead', ':isRead')
            ->where('fs.form = :form')
            ->andWhere('fs.id IN (:ids)')
            ->setParameter('isRead', $isRead)
            ->setParameter('form', $form)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }

    /**
     * @param int[] $ids
     */
    public function batchMarkSpam(Form $form, array $ids, bool $isSpam): int
    {
        if (empty($ids)) {
            return 0;
        }

        return (int) $this->createQueryBuilder('fs')
            ->update()
            ->set('fs.isSpam', ':isSpam')
            ->where('fs.form = :form')
            ->andWhere('fs.id IN (:ids)')
            ->setParameter('isSpam', $isSpam)
            ->setParameter('form', $form)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }

    /**
     * @param int[] $ids
     */
    public function batchDelete(Form $form, array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        return (int) $this->createQueryBuilder('fs')
            ->delete()
            ->where('fs.form = :form')
            ->andWhere('fs.id IN (:ids)')
            ->setParameter('form', $form)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
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
