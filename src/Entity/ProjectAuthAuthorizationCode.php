<?php

namespace App\Entity;

use App\Repository\ProjectAuthAuthorizationCodeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProjectAuthAuthorizationCodeRepository::class)]
#[ORM\Table(name: 'project_auth_authorization_codes')]
class ProjectAuthAuthorizationCode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 128, unique: true)]
    public string $code = '';

    #[ORM\ManyToOne(targetEntity: ProjectAuthClient::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ProjectAuthClient $client;

    #[ORM\ManyToOne(targetEntity: EndUser::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public EndUser $endUser;

    #[ORM\Column(length: 512)]
    public string $redirectUri = '';

    #[ORM\Column(type: 'json')]
    public array $scopes = [];

    #[ORM\Column(length: 128, nullable: true)]
    public ?string $codeChallenge = null;

    #[ORM\Column(length: 10, nullable: true)]
    public ?string $codeChallengeMethod = 'S256';

    #[ORM\Column]
    public bool $isUsed = false;

    #[ORM\Column]
    public \DateTimeImmutable $expiresAt;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->code = bin2hex(random_bytes(32));
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = (new \DateTimeImmutable())->modify('+10 minutes');
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }
}
