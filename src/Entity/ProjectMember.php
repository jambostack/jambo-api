<?php
// src/Entity/ProjectMember.php
namespace App\Entity;

use App\Enum\ProjectMemberStatus;
use App\Repository\ProjectMemberRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProjectMemberRepository::class)]
#[ORM\Table(name: 'project_member')]
#[ORM\UniqueConstraint(name: 'uniq_project_email', columns: ['project_id', 'email'])]
#[ORM\UniqueConstraint(name: 'uniq_project_user', columns: ['project_id', 'user_id'])]
class ProjectMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'projectMembers')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Project $project;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    public ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?Role $role = null;

    #[ORM\Column(length: 255)]
    public string $email = '';

    #[ORM\Column(length: 20, enumType: ProjectMemberStatus::class)]
    public ProjectMemberStatus $status = ProjectMemberStatus::Active;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?User $invitedBy = null;

    #[ORM\Column(length: 64, nullable: true, unique: true)]
    public ?string $invitationToken = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $tokenExpiresAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $joinedAt = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function isPending(): bool
    {
        return $this->status === ProjectMemberStatus::Pending;
    }

    public function isTokenExpired(): bool
    {
        return $this->tokenExpiresAt !== null
            && $this->tokenExpiresAt < new \DateTimeImmutable();
    }
}
