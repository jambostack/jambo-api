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
     * Resolves an ApiToken from the Authorization: Bearer header or token query parameter.
     * Returns null if the token is missing, invalid, or expired.
     */
    public function resolve(Request $request): ?ApiToken
    {
        $header = $request->headers->get('Authorization', '');
        if (str_starts_with($header, 'Bearer ')) {
            $plainToken = substr($header, 7);
        } else {
            $plainToken = $request->query->get('token') ?? $request->query->get('preview_token');
        }

        if (empty($plainToken) || !is_string($plainToken)) {
            return null;
        }

        $plainToken = trim($plainToken);
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

    /**
     * Checks whether an ApiToken is permitted to perform the requested HTTP method or ability.
     * Enforces strict read vs write/mutation partitioning:
     * - 'read' is allowed for GET and HEAD requests.
     * - 'create' or 'write' is allowed for POST requests.
     * - 'update' or 'write' is allowed for PUT and PATCH requests.
     * - 'delete' or 'write' is allowed for DELETE requests.
     * - Read-only tokens (abilities=['read']) are strictly forbidden from mutation operations.
     */
    public function isAllowedForMethod(ApiToken $token, string $method): bool
    {
        $m = strtoupper(trim($method));
        $required = match ($m) {
            'GET', 'HEAD'  => 'read',
            'POST'         => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE'       => 'delete',
            default        => null,
        };

        if ($required === null) {
            return false;
        }

        if ($required === 'read') {
            return $token->can('read') || $token->can('write') || $token->can('*');
        }

        return $token->can($required) || $token->can('write') || $token->can('*');
    }

    /**
     * Checks if a token has a specific ability, adhering to inheritance / wildcards.
     */
    public function canAccess(ApiToken $token, string $ability): bool
    {
        if ($token->can('*')) {
            return true;
        }
        if ($token->can($ability)) {
            return true;
        }
        if ($token->can('write') && in_array($ability, ['read', 'create', 'update', 'delete'], true)) {
            return true;
        }
        return false;
    }
}
