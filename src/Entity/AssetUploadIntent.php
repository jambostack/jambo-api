<?php

namespace App\Entity;

use App\Repository\AssetUploadIntentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AssetUploadIntentRepository::class)]
#[ORM\Table(name: 'asset_upload_intent')]
#[ORM\HasLifecycleCallbacks]
class AssetUploadIntent
{
    public const MODE_SINGLE_PUT = 'single_put';
    public const MODE_MULTIPART = 'multipart';

    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ABORTED = 'aborted';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    public ?Uuid $uuid = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Project $project;

    #[ORM\ManyToOne(targetEntity: ProjectStorageProfile::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?ProjectStorageProfile $storageProfile = null;

    #[ORM\Column(length: 255)]
    public string $storageKey = '';

    #[ORM\Column(length: 255)]
    public string $originalFilename = '';

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $clientMimeType = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    public ?int $maxBytes = null;

    #[ORM\Column(length: 20)]
    public string $mode = self::MODE_SINGLE_PUT;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $s3MultipartUploadId = null;

    #[ORM\Column(length: 20)]
    public string $status = self::STATUS_PENDING;

    #[ORM\Column]
    public \DateTimeImmutable $expiresAt;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->uuid = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = (new \DateTimeImmutable())->modify('+2 hours');
    }

    #[ORM\PrePersist]
    public function initializeUuid(): void
    {
        if ($this->uuid === null) {
            $this->uuid = Uuid::v4();
        }
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid?->toRfc4122(),
            'storage_key' => $this->storageKey,
            'original_filename' => $this->originalFilename,
            'client_mime_type' => $this->clientMimeType,
            'max_bytes' => $this->maxBytes,
            'mode' => $this->mode,
            'status' => $this->status,
            'expires_at' => $this->expiresAt->format(\DateTimeInterface::ATOM),
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
