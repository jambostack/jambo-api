<?php

namespace App\Service;

use App\Entity\EndUser;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Psr\Clock\ClockInterface;

class EndUserJwtService
{
    private Configuration $config;

    public function __construct(
        private string $appSecret,
        private ClockInterface $clock,
    ) {
        $this->config = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($appSecret),
        );
    }

    /** Default TTLs when project has no custom value configured */
    private const DEFAULT_ACCESS_TTL = 900;      // 15 minutes
    private const DEFAULT_REFRESH_TTL = 2592000; // 30 days

    /** Generate access token. TTL from project settings, falls back to 15 min. */
    public function createAccessToken(EndUser $endUser): string
    {
        $ttl = $endUser->project->jwtAccessTtl ?? self::DEFAULT_ACCESS_TTL;
        $now = $this->clock->now();
        return $this->config->builder()
            ->issuedBy('jamboapi')
            ->permittedFor('jamboapi')
            ->identifiedBy(bin2hex(random_bytes(16)))
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($now->modify("+{$ttl} seconds"))
            ->withClaim('euid', $endUser->uuid?->toString())
            ->withClaim('pid', $endUser->project->uuid?->toString())
            ->withClaim('tkn', $endUser->tokenVersion)
            ->getToken($this->config->signer(), $this->config->signingKey())
            ->toString();
    }

    /** Generate refresh token. TTL from project settings, falls back to 30 days. */
    public function createRefreshToken(EndUser $endUser): string
    {
        $ttl = $endUser->project->jwtRefreshTtl ?? self::DEFAULT_REFRESH_TTL;
        $now = $this->clock->now();
        return $this->config->builder()
            ->issuedBy('jamboapi')
            ->permittedFor('jamboapi')
            ->identifiedBy(bin2hex(random_bytes(16)))
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($now->modify("+{$ttl} seconds"))
            ->withClaim('euid', $endUser->uuid?->toString())
            ->withClaim('pid', $endUser->project->uuid?->toString())
            ->withClaim('tkn', $endUser->tokenVersion)
            ->withClaim('ref', true)
            ->getToken($this->config->signer(), $this->config->signingKey())
            ->toString();
    }

    /** Validate and parse a token. Returns claims or null on failure. */
    public function validateToken(string $jwt): ?array
    {
        try {
            $token = $this->config->parser()->parse($jwt);
            $constraints = [
                new SignedWith($this->config->signer(), $this->config->signingKey()),
                new StrictValidAt($this->clock),
            ];
            $this->config->validator()->assert($token, ...$constraints);
            return [
                'euid' => $token->claims()->get('euid'),
                'pid'  => $token->claims()->get('pid'),
                'tkn'  => $token->claims()->get('tkn'),
                'ref'  => $token->claims()->get('ref') ?? false,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /** Returns true if the token claims represent a refresh token */
    public function isRefreshToken(array $claims): bool
    {
        return ($claims['ref'] ?? false) === true;
    }
}
