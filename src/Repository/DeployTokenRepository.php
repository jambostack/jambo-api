<?php
// src/Repository/DeployTokenRepository.php
namespace App\Repository;

use App\Entity\DeployToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DeployToken> */
class DeployTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeployToken::class);
    }

    public function findForUser(User $user, string $provider): ?DeployToken
    {
        return $this->findOneBy(['user' => $user, 'provider' => $provider]);
    }

    /** @return DeployToken[] */
    public function findAllForUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['provider' => 'ASC']);
    }
}
