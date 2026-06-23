<?php

namespace App\Entity;

use App\Repository\ShareRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ShareRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'share')]
class Share
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    public ?Uuid $uuid = null;

    /** Hashed token (HMAC) — plaintext shown only once at creation. */
    #[ORM\Column(length: 64, unique: true)]
    public string $tokenHash = '';

    #[ORM\ManyToOne(targetEntity: ContentEntry::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?ContentEntry $entry = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Project $project = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?User $createdBy = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $lastAccessedAt = null;

    #[ORM\Column(options: ['default' => 0])]
    public int $viewCount = 0;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function setUuidValue(): void
    {
        if ($this->uuid === null) {
            $this->uuid = Uuid::v4();
        }
    }

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt < new \DateTimeImmutable();
    }

    public function isValid(): bool
    {
        return !$this->isRevoked() && !$this->isExpired();
    }
}
