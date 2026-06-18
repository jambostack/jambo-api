<?php

namespace App\Service;

use App\Entity\EndUser;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Validation\Constraint\Leeway;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

class EndUserJwtService
{
    private Configuration $config;

    public function __construct(
        private string $appSecret,
        private ClockInterface $clock,
        private ?LoggerInterface $logger = null,
    ) {
        $this->config = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($appSecret),
        );
    }

    /** Default TTLs when project has no custom value configured */
    public const DEFAULT_ACCESS_TTL  = 900;      // 15 minutes
    public const DEFAULT_REFRESH_TTL = 2592000;   // 30 days

    /** Maximum allowed TTL: 1 year (security ceiling) */
    public const MAX_TTL = 31536000; // 365 days

    /** Generate access token. TTL from project settings, falls back to 15 min. */
    public function createAccessToken(EndUser $endUser): string
    {
        return $this->createToken($endUser, false);
    }

    /** Generate refresh token. TTL from project settings, falls back to 30 days. */
    public function createRefreshToken(EndUser $endUser): string
    {
        return $this->createToken($endUser, true);
    }

    /**
     * Validate and parse a token. Returns claims or null on failure.
     */
    public function validateToken(string $jwt): ?array
    {
        try {
            $token = $this->config->parser()->parse($jwt);
            $constraints = [
                new SignedWith($this->config->signer(), $this->config->signingKey()),
                new StrictValidAt($this->clock, new Leeway(30)),
            ];
            $this->config->validator()->assert($token, ...$constraints);
            return [
                'euid' => $token->claims()->get('euid'),
                'pid'  => $token->claims()->get('pid'),
                'tkn'  => $token->claims()->get('tkn'),
                'ref'  => $token->claims()->get('ref') ?? false,
                'tfa'  => $token->claims()->get('tfa') ?? false,
                'code' => $token->claims()->get('code'),
            ];
        } catch (\Throwable $e) {
            $this->logger?->warning('JWT validation failed: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);
            return null;
        }
    }

    /** Returns true if the token claims represent a refresh token */
    public function isRefreshToken(array $claims): bool
    {
        return ($claims['ref'] ?? false) === true;
    }

    /** Generate a short-lived 2FA challenge token (TTL 60 seconds).
     *  @param string|null $emailCodeHash sha256 hash of the email code (never the plaintext code) */
    public function createTwoFactorToken(EndUser $endUser, ?string $emailCodeHash = null): string
    {
        $now = new \DateTimeImmutable();
        $builder = $this->config->builder()
            ->issuedAt($now)
            ->expiresAt($now->modify('+60 seconds'))
            ->withClaim('euid', $endUser->uuid->toRfc4122())
            ->withClaim('pid', $endUser->project->uuid->toRfc4122())
            ->withClaim('tfa', true); // marker: this is a 2FA token

        if ($emailCodeHash !== null) {
            $builder = $builder->withClaim('ech', $emailCodeHash); // email code hash
        }

        return $builder->getToken($this->config->signer(), $this->config->signingKey())->toString();
    }

    /** Validate a 2FA token. Returns claims or null. Same TTL validation but marked as 2FA. */
    public function validateTwoFactorToken(string $jwt): ?array
    {
        $claims = $this->validateToken($jwt);
        if ($claims === null) return null;
        if (!($claims['tfa'] ?? false)) return null;
        return $claims;
    }

    /** Resolve effective TTL: use project value if &gt; 0, else fallback to default. */
    public static function resolveTtl(?int $projectTtl, int $default): int
    {
        return ($projectTtl !== null && $projectTtl > 0) ? $projectTtl : $default;
    }

    // ─── Private ───────────────────────────────────────────────────────────

    private function createToken(EndUser $endUser, bool $isRefresh): string
    {
        if ($endUser->uuid === null) {
            throw new \RuntimeException('Cannot generate JWT: EndUser has no UUID.');
        }
        if ($endUser->project->uuid === null) {
            throw new \RuntimeException('Cannot generate JWT: Project has no UUID.');
        }

        $ttl = $isRefresh
            ? self::resolveTtl($endUser->project->jwtRefreshTtl, self::DEFAULT_REFRESH_TTL)
            : self::resolveTtl($endUser->project->jwtAccessTtl, self::DEFAULT_ACCESS_TTL);

        $now = $this->clock->now();
        $builder = $this->config->builder()
            ->issuedBy('jamboapi')
            ->permittedFor('jamboapi')
            ->identifiedBy(bin2hex(random_bytes(16)))
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($now->modify("+{$ttl} seconds"))
            ->withClaim('euid', $endUser->uuid->toString())
            ->withClaim('pid', $endUser->project->uuid->toString())
            ->withClaim('tkn', $endUser->tokenVersion);

        if ($isRefresh) {
            $builder = $builder->withClaim('ref', true);
        }

        return $builder
            ->getToken($this->config->signer(), $this->config->signingKey())
            ->toString();
    }
}
