<?php

namespace App\Entity;

use App\Repository\MediaRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Uid\Uuid;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

#[ORM\Entity(repositoryClass: MediaRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[Vich\Uploadable]
class Media
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    public ?Uuid $uuid = null;

    #[Vich\UploadableField(mapping: 'media_files', fileNameProperty: 'fileName', size: 'fileSize', mimeType: 'mimeType', originalName: 'originalName')]
    public ?File $file = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $fileName = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $originalName = null;

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $mimeType = null;

    #[ORM\Column(nullable: true)]
    public ?int $fileSize = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $alt = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $caption = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false)]
    public ?Project $project = null;

    #[ORM\ManyToOne(targetEntity: MediaFolder::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?MediaFolder $folder = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    public ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    public ?User $updatedBy = null;

    #[ORM\OneToOne(targetEntity: AssetMetadata::class, mappedBy: 'media', cascade: ['persist', 'remove'])]
    public ?AssetMetadata $metadata = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $deletedAt = null;

    #[ORM\ManyToOne(targetEntity: ProjectStorageProfile::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?ProjectStorageProfile $storageProfile = null;

    /** Mapping {"<profile_uuid>": "<relative_path>", …}. Ex: {"a1b2c3": "projects/f99cb038/photo.jpg"} */
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $storagePaths = null;

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

    #[ORM\PreUpdate]
    public function setUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getFile(): ?File
    {
        return $this->file;
    }

    public function setFile(?File $file): void
    {
        $this->file = $file;
        if ($file !== null) {
            $this->updatedAt = new \DateTimeImmutable();
        }
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function getPublicUrl(): ?string
    {
        if ($this->fileName === null) {
            return null;
        }

        // Files are stored under a per-project directory (see ProjectDirNamer),
        // so the public URL must include the project UUID to resolve correctly.
        if ($this->project?->uuid !== null) {
            return '/uploads/media/' . $this->project->uuid . '/' . $this->fileName;
        }

        return '/uploads/media/' . $this->fileName;
    }
}
