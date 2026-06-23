<?php

namespace App\Service\Share;

use App\Entity\ApiToken;
use App\Entity\ContentEntry;
use App\Entity\Share;
use App\Entity\User;
use App\Enum\ShareDuration;
use App\Repository\ShareRepository;
use Doctrine\ORM\EntityManagerInterface;

class ShareService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ShareRepository $shares,
        private readonly string $appSecret,
    ) {}

    /**
     * @return array{share: Share, plainToken: string}
     */
    public function create(ContentEntry $entry, ShareDuration $duration, ?User $creator): array
    {
        $plain = 'jbo_share_' . ApiToken::generatePlainToken();

        $share = new Share();
        $share->tokenHash = ApiToken::hashToken($plain, $this->appSecret);
        $share->entry = $entry;
        $share->project = $entry->project;
        $share->createdBy = $creator;
        $share->expiresAt = $duration->expiresAtFrom(new \DateTimeImmutable());

        $this->em->persist($share);
        $this->em->flush();

        return ['share' => $share, 'plainToken' => $plain];
    }

    public function resolve(string $plainToken): ?Share
    {
        $hash = ApiToken::hashToken($plainToken, $this->appSecret);
        return $this->shares->findOneByTokenHash($hash);
    }

    public function revoke(Share $share): void
    {
        $share->revokedAt = new \DateTimeImmutable();
        $this->em->flush();
    }

    public function recordAccess(Share $share): void
    {
        $share->viewCount++;
        $share->lastAccessedAt = new \DateTimeImmutable();
        $this->em->flush();
    }
}
