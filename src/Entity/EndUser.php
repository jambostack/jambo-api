<?php

namespace App\Entity;

use App\Repository\EndUserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: EndUserRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(name: 'uniq_end_user_project_email', columns: ['project_id', 'email'])]
class EndUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    public ?Uuid $uuid = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Project $project;

    #[ORM\Column(length: 180)]
    public string $email;

    #[ORM\Column(length: 255)]
    public string $password;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $name = null;

    #[ORM\Column(length: 500, nullable: true)]
    public ?string $avatarUrl = null;

    #[ORM\Column(length: 20)]
    public string $status = 'active';

    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $customFields = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'integer')]
    public int $tokenVersion = 1;

    public function __construct(Project $project, string $email)
    {
        $this->project = $project;
        $this->email = $email;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->tokenVersion = 1;
    }

    #[ORM\PrePersist]
    public function setUuid(): void
    {
        if ($this->uuid === null) {
            $this->uuid = Uuid::v4();
        }
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function getRoles(): array
    {
        return ['ROLE_END_USER'];
    }

    public function eraseCredentials(): void
    {
    }
}
