<?php

namespace App\Entity;

use App\Repository\ProjectAuthRefreshTokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProjectAuthRefreshTokenRepository::class)]
#[ORM\Table(name: 'project_auth_refresh_tokens')]
class ProjectAuthRefreshToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    public string $tokenHash = '';

    #[ORM\ManyToOne(targetEntity: ProjectAuthClient::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ProjectAuthClient $client;

    #[ORM\ManyToOne(targetEntity: EndUser::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public EndUser $endUser;

    #[ORM\Column(type: 'json')]
    public array $scopes = [];

    #[ORM\Column]
    public bool $isRevoked = false;

    #[ORM\Column]
    public \DateTimeImmutable $expiresAt;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = (new \DateTimeImmutable())->modify('+30 days');
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
