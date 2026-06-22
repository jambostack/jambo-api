<?php

namespace App\Repository;

use App\Entity\ApiToken;
use App\Entity\PersonalAccessToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PersonalAccessToken>
 */
class PersonalAccessTokenRepository extends ServiceEntityRepository
{
    private string $appSecret;

    public function __construct(ManagerRegistry $registry, string $appSecret)
    {
        parent::__construct($registry, PersonalAccessToken::class);
        $this->appSecret = $appSecret;
    }

    public function findByPlainToken(string $plainToken): ?PersonalAccessToken
    {
        $hmac = ApiToken::hashToken($plainToken, $this->appSecret);
        $pat = $this->findOneBy(['tokenHash' => $hmac, 'tokenVersion' => 2]);
        if ($pat !== null) {
            return $pat;
        }

        $legacy = ApiToken::hashTokenLegacy($plainToken);
        return $this->findOneBy(['tokenHash' => $legacy, 'tokenVersion' => 1]);
    }

    /** @return PersonalAccessToken[] */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'DESC']);
    }
}
