<?php

namespace App\Repository;

use App\Entity\ApiToken;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiToken>
 */
class ApiTokenRepository extends ServiceEntityRepository
{
    private string $appSecret;

    public function __construct(ManagerRegistry $registry, string $appSecret)
    {
        parent::__construct($registry, ApiToken::class);
        $this->appSecret = $appSecret;
    }

    public function findByPlainToken(string $plainToken): ?ApiToken
    {
        // Try new HMAC hash first
        $hmacHash = ApiToken::hashToken($plainToken, $this->appSecret);
        $token = $this->findOneBy(['tokenHash' => $hmacHash, 'tokenVersion' => 2]);

        if ($token !== null) {
            return $token;
        }

        // Fallback to legacy SHA-256 for existing tokens
        $legacyHash = ApiToken::hashTokenLegacy($plainToken);
        return $this->findOneBy(['tokenHash' => $legacyHash, 'tokenVersion' => 1]);
    }

    /** @return ApiToken[] */
    public function findByProject(Project $project): array
    {
        return $this->findBy(['project' => $project], ['createdAt' => 'DESC']);
    }
}
