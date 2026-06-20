<?php

namespace App\Service;

use App\Entity\ContentEntry;
use DateInterval;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Psr\Clock\ClockInterface;

/**
 * Genere et valide les JWT de preview utilises par le systeme de Live Preview.
 *
 * Le token permet au frontend externe d'acceder a une entree specifique
 * (meme en brouillon) via l'API publique.
 *
 * Claims :
 *   sub=preview  pid=<project_uuid>  eid=<entry_uuid>
 *   col=<collection_slug>  status=draft|published  exp=<+1h>
 */
class PreviewTokenService
{
    private Configuration $config;

    /** TTL du token de preview : 1 heure */
    private const TTL = 3600;

    private static ?DateInterval $leeway = null;

    public function __construct(
        private readonly string $appSecret,
        private readonly ClockInterface $clock,
    ) {
        $this->config = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($appSecret),
        );
    }

    /**
     * Genere un token de preview pour une entree.
     */
    public function createToken(ContentEntry $entry): string
    {
        if ($entry->uuid === null) {
            throw new \RuntimeException('Cannot generate preview token: ContentEntry has no UUID.');
        }
        if ($entry->project?->uuid === null) {
            throw new \RuntimeException('Cannot generate preview token: Project has no UUID.');
        }
        if ($entry->collection?->slug === null) {
            throw new \RuntimeException('Cannot generate preview token: Collection has no slug.');
        }

        $now = $this->clock->now();

        return $this->config->builder()
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($now->modify('+' . self::TTL . ' seconds'))
            ->relatedTo('preview')
            ->withClaim('pid', $entry->project->uuid->toRfc4122())
            ->withClaim('eid', $entry->uuid->toRfc4122())
            ->withClaim('col', $entry->collection->slug)
            ->withClaim('status', $entry->status ?? 'draft')
            ->getToken($this->config->signer(), $this->config->signingKey())
            ->toString();
    }

    /**
     * Valide un token de preview. Retourne les claims ou null si invalide.
     */
    public function validateToken(string $token): ?array
    {
        try {
            $parsedToken = $this->config->parser()->parse($token);

            $constraints = [
                new SignedWith($this->config->signer(), $this->config->signingKey()),
                new StrictValidAt($this->clock, self::$leeway ??= new DateInterval('PT30S')),
            ];

            $this->config->validator()->assert($parsedToken, ...$constraints);

            $claims = [
                'sub'    => $parsedToken->claims()->get('sub'),
                'pid'    => $parsedToken->claims()->get('pid'),
                'eid'    => $parsedToken->claims()->get('eid'),
                'col'    => $parsedToken->claims()->get('col'),
                'status' => $parsedToken->claims()->get('status'),
            ];

            if ($parsedToken->claims()->get('sub') !== 'preview') {
                return null;
            }

            return $claims;
        } catch (\Throwable) {
            return null;
        }
    }
}
