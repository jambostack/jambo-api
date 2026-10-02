<?php

namespace App\Entity;

use App\Repository\ProjectAuthSessionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProjectAuthSessionRepository::class)]
#[ORM\Table(name: 'project_auth_sessions')]
class ProjectAuthSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    public string $sessionId = '';

    #[ORM\ManyToOne(targetEntity: EndUser::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public EndUser $endUser;

    #[ORM\Column(length: 45, nullable: true)]
    public ?string $ipAddress = null;

    #[ORM\Column(length: 500, nullable: true)]
    public ?string $userAgent = null;

    #[ORM\Column]
    public \DateTimeImmutable $lastActiveAt;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->sessionId = bin2hex(random_bytes(32));
        $this->createdAt = new \DateTimeImmutable();
        $this->lastActiveAt = new \DateTimeImmutable();
    }

    public function touch(): void
    {
        $this->lastActiveAt = new \DateTimeImmutable();
    }
}
