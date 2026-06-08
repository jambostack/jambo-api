<?php

namespace App\Entity;

use App\Repository\ProjectStorageProfileRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ProjectStorageProfileRepository::class)]
#[ORM\Table(name: 'project_storage_profile')]
#[ORM\HasLifecycleCallbacks]
class ProjectStorageProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    public ?Uuid $uuid = null;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'storageProfiles')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Project $project;

    #[ORM\Column(length: 100)]
    public string $name = '';

    /** 'local' | 's3' */
    #[ORM\Column(length: 20)]
    public string $driver = 'local';

    #[ORM\Column]
    public int $priority = 0;

    #[ORM\Column]
    public bool $enabled = true;

    #[ORM\Column]
    public bool $isDefault = false;

    // ── S3 fields (nullable — utilisés seulement si driver = s3) ──

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $s3Key = null;

    /** Chiffré avec APP_SECRET (sodium secretbox). */
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $s3Secret = null;

    #[ORM\Column(length: 50, nullable: true)]
    public ?string $s3Region = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $s3Bucket = null;

    /** Pour S3-compatible (R2, MinIO…). Null = AWS default. */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $s3Endpoint = null;

    /** Pour MinIO et certains S3-compatibles. */
    #[ORM\Column]
    public bool $s3UsePathStyle = false;

    /** CDN ou custom domain. Si null → URL signée S3. */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $baseUrl = null;

    // ── Local fields ──

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $rootPath = null;

    // ── Timestamps ──

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
        if ($this->uuid === null) {
            $this->uuid = Uuid::v4();
        }
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** Ne jamais retourner le secret en clair dans l'API. */
    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'uuid'             => $this->uuid?->toRfc4122(),
            'name'             => $this->name,
            'driver'           => $this->driver,
            'priority'         => $this->priority,
            'enabled'          => $this->enabled,
            'is_default'       => $this->isDefault,
            's3_key'           => $this->s3Key,
            's3_region'        => $this->s3Region,
            's3_bucket'        => $this->s3Bucket,
            's3_endpoint'      => $this->s3Endpoint,
            's3_use_path_style' => $this->s3UsePathStyle,
            'base_url'          => $this->baseUrl,
            'root_path'         => $this->rootPath,
            'created_at'       => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at'       => $this->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
