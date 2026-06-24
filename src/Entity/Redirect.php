<?php

namespace App\Entity;

use App\Repository\RedirectRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: RedirectRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Redirect
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    public ?Uuid $uuid = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false)]
    public ?Project $project = null;

    #[ORM\Column(length: 512)]
    public string $fromPath = '';

    #[ORM\Column(length: 512)]
    public string $toPath = '';

    #[ORM\Column]
    public int $httpCode = 301;

    #[ORM\Column]
    public bool $isPattern = false;

    #[ORM\Column]
    public bool $isEnabled = true;

    #[ORM\Column]
    public int $hits = 0;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $lastHitAt = null;

    #[ORM\Column]
    public bool $isAuto = false;

    #[ORM\ManyToOne(targetEntity: ContentEntry::class)]
    #[ORM\JoinColumn(nullable: true)]
    public ?ContentEntry $sourceEntry = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    public ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    public ?User $updatedBy = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function setUuidValue(): void
    {
        if ($this->uuid === null) $this->uuid = Uuid::v4();
    }

    #[ORM\PreUpdate]
    public function setUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
