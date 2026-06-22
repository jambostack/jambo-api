<?php

namespace App\Entity;

use App\Repository\PersonalAccessTokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PersonalAccessTokenRepository::class)]
class PersonalAccessToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 255)]
    public string $name = '';

    /** Hashed token (HMAC) — plaintext shown only once at creation. */
    #[ORM\Column(length: 64, unique: true)]
    public string $tokenHash = '';

    #[ORM\Column(options: ['default' => 1])]
    public int $tokenVersion = 1;

    /** Scopes: projects:write, schema:write, content:read/write/delete. */
    #[ORM\Column(type: 'json')]
    public array $scopes = ['schema:write'];

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?User $user = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function can(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt < new \DateTimeImmutable();
    }
}
