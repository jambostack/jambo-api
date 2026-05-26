<?php

namespace App\Service;

use App\Entity\ApiToken;
use App\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

class ApiTokenChecker
{
    public function __construct(
        private ApiTokenRepository $tokenRepository,
        private EntityManagerInterface $em,
        private string $appSecret,
    ) {}

    /**
     * Resolves an ApiToken from the Authorization: Bearer header.
     * Returns null if the token is missing, invalid, or expired.
     */
    public function resolve(Request $request): ?ApiToken
    {
        $header = $request->headers->get('Authorization', '');
        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $plainToken = substr($header, 7);
        $token = $this->tokenRepository->findByPlainToken($plainToken);

        if ($token === null || $token->isExpired()) {
            return null;
        }

        // Track last usage without blocking the request
        $token->lastUsedAt = new \DateTimeImmutable();
        if ($token->tokenVersion === 1) {
            // Upgrade to HMAC on first use
            $token->tokenHash    = ApiToken::hashToken($plainToken, $this->appSecret);
            $token->tokenVersion = 2;
        }
        $this->em->persist($token);
        $this->em->flush();

        return $token;
    }
}
