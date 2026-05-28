<?php

namespace App\Entity;

use App\Repository\ContentVersionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ContentVersionRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'content_version')]
class ContentVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    public ?Uuid $uuid = null;

    #[ORM\ManyToOne(targetEntity: ContentEntry::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?ContentEntry $contentEntry = null {
        get => $this->contentEntry;
        set { $this->contentEntry = $value; }
    }

    #[ORM\Column(type: 'json')]
    public array $snapshot = [] {
        get => $this->snapshot;
        set { $this->snapshot = $value; }
    }

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $label = null {
        get => $this->label;
        set { $this->label = $value; }
    }

    #[ORM\Column]
    public int $versionNumber = 1 {
        get => $this->versionNumber;
        set { $this->versionNumber = $value; }
    }

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    public ?User $createdBy = null;

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
