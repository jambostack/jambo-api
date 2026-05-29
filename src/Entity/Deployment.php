<?php

namespace App\Entity;

use App\Repository\DeploymentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DeploymentRepository::class)]
#[ORM\Table(name: 'deployment')]
#[ORM\Index(name: 'idx_deployment_project_started', columns: ['project_id', 'started_at'])]
class Deployment
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELED  = 'canceled';

    public const ENV_PREVIEW    = 'preview';
    public const ENV_STAGING    = 'staging';
    public const ENV_PRODUCTION = 'production';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    public Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?Project $project = null;

    #[ORM\Column(length: 40)]
    public string $commitSha = '';

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $branch = null;

    #[ORM\Column(length: 20)]
    public string $environment = self::ENV_PREVIEW;

    #[ORM\Column(length: 20)]
    public string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $imageRef = null;

    #[ORM\Column(length: 500, nullable: true)]
    public ?string $previewUrl = null;

    #[ORM\Column(length: 500, nullable: true)]
    public ?string $runUrl = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $errorMessage = null;

    #[ORM\Column]
    public \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $finishedAt = null;

    public function __construct()
    {
        $this->uuid = Uuid::v4();
        $this->startedAt = new \DateTimeImmutable();
    }

    public function durationSeconds(): ?int
    {
        if ($this->finishedAt === null) return null;
        return $this->finishedAt->getTimestamp() - $this->startedAt->getTimestamp();
    }
}
