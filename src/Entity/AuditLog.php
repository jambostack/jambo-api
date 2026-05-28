<?php

namespace App\Entity;

use App\Repository\AuditLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(columns: ['project_id', 'created_at'])]
#[ORM\Index(columns: ['tool_name'])]
#[ORM\Index(columns: ['created_by'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    public ?Uuid $uuid = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: true)]
    public ?Project $project = null;

    #[ORM\Column(length: 100)]
    public string $toolName {
        get => $this->toolName;
        set { $this->toolName = $value; }
    }

    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $input = null {
        get => $this->input;
        set { $this->input = $value; }
    }

    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $output = null {
        get => $this->output;
        set { $this->output = $value; }
    }

    #[ORM\Column(length: 20)]
    public string $status = 'success' {
        get => $this->status;
        set { $this->status = $value; }
    }

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $errorMessage = null {
        get => $this->errorMessage;
        set { $this->errorMessage = $value; }
    }

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $createdBy = null {
        get => $this->createdBy;
        set { $this->createdBy = $value; }
    }

    #[ORM\Column(length: 50, nullable: true)]
    public ?string $source = null {
        get => $this->source;
        set { $this->source = $value; }
    }

    #[ORM\Column(nullable: true)]
    public ?int $durationMs = null {
        get => $this->durationMs;
        set { $this->durationMs = $value; }
    }

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

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
}
