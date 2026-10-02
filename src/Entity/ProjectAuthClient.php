<?php

namespace App\Entity;

use App\Repository\ProjectAuthClientRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ProjectAuthClientRepository::class)]
#[ORM\Table(name: 'project_auth_clients')]
#[ORM\HasLifecycleCallbacks]
class ProjectAuthClient
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    public ?Uuid $uuid = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Project $project;

    #[ORM\Column(length: 100)]
    public string $name = '';

    #[ORM\Column(length: 64, unique: true)]
    public string $clientId = '';

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $clientSecretHash = null;

    #[ORM\Column(type: 'json')]
    public array $redirectUris = [];

    #[ORM\Column(type: 'json')]
    public array $allowedScopes = ['openid', 'profile', 'email'];

    #[ORM\Column]
    public bool $isConfidential = false;

    #[ORM\Column]
    public bool $isActive = true;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->uuid = Uuid::v4();
        $this->clientId = 'client_' . bin2hex(random_bytes(16));
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function initializeUuid(): void
    {
        if ($this->uuid === null) {
            $this->uuid = Uuid::v4();
        }
        if ($this->clientId === '') {
            $this->clientId = 'client_' . bin2hex(random_bytes(16));
        }
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isRedirectUriAllowed(string $uri): bool
    {
        return in_array($uri, $this->redirectUris, true);
    }

    public function areScopesAllowed(array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if (!in_array($scope, $this->allowedScopes, true)) {
                return false;
            }
        }
        return true;
    }
}
