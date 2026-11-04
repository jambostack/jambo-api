<?php

namespace App\Repository;

use App\Entity\EndUser;
use App\Entity\ProjectAuthSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProjectAuthSession>
 */
class ProjectAuthSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProjectAuthSession::class);
    }

    /**
     * @return ProjectAuthSession[]
     */
    public function findByEndUser(EndUser $endUser): array
    {
        return $this->findBy(['endUser' => $endUser], ['lastActiveAt' => 'DESC']);
    }

    public function revokeSession(string $sessionId): void
    {
        $session = $this->findOneBy(['sessionId' => $sessionId]);
        if ($session) {
            $this->getEntityManager()->remove($session);
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Crée et persiste une nouvelle session pour un EndUser avec limitation optionnelle des sessions concurrentes.
     */
    public function createSession(EndUser $endUser, ?string $ipAddress = null, ?string $userAgent = null, int $maxConcurrentSessions = 5): ProjectAuthSession
    {
        $em = $this->getEntityManager();

        // Enforce maximum concurrent sessions (FIFO: revoke oldest when limit reached)
        if ($maxConcurrentSessions > 0) {
            $existing = $this->findBy(['endUser' => $endUser], ['lastActiveAt' => 'ASC']);
            $count = count($existing);
            if ($count >= $maxConcurrentSessions) {
                $toRemove = array_slice($existing, 0, $count - $maxConcurrentSessions + 1);
                foreach ($toRemove as $oldSession) {
                    $em->remove($oldSession);
                }
            }
        }

        $session = new ProjectAuthSession();
        $session->endUser = $endUser;
        $session->ipAddress = $ipAddress ? substr($ipAddress, 0, 45) : null;
        $session->userAgent = $userAgent ? substr($userAgent, 0, 500) : null;

        $em->persist($session);
        $em->flush();

        return $session;
    }

    /**
     * Révoque toutes les sessions d'un EndUser (ex: changement de mot de passe ou compromise de compte).
     * Permet d'épargner la session courante si $exceptSessionId est fourni.
     */
    public function revokeAllForEndUser(EndUser $endUser, ?string $exceptSessionId = null): int
    {
        $qb = $this->createQueryBuilder('s')
            ->delete()
            ->where('s.endUser = :endUser')
            ->setParameter('endUser', $endUser);

        if ($exceptSessionId !== null && $exceptSessionId !== '') {
            $qb->andWhere('s.sessionId != :except')
               ->setParameter('except', $exceptSessionId);
        }

        return (int) $qb->getQuery()->execute();
    }

    public function countActiveForEndUser(EndUser $endUser): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.endUser = :endUser')
            ->setParameter('endUser', $endUser)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function deleteInactive(\DateTimeImmutable $threshold): int
    {
        return $this->createQueryBuilder('s')
            ->delete()
            ->where('s.lastActiveAt < :threshold')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->execute();
    }
}
