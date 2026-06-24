<?php

namespace App\Entity;

use App\Repository\ContentEntryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection as DoctrineCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ContentEntryRepository::class)]
#[ORM\HasLifecycleCallbacks]
class ContentEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    public ?Uuid $uuid = null;

    #[ORM\Column(length: 10)]
    public string $locale = 'en' {
        get => $this->locale;
        set { $this->locale = $value; }
    }

    #[ORM\Column(length: 50)]
    public string $status = 'draft' {
        get => $this->status;
        set {
            if ($value === 'published' && $this->status !== 'published' && $this->publishedAt === null) {
                $this->publishedAt = new \DateTimeImmutable();
            }
            if ($value === 'published' && $this->status === 'scheduled') {
                $this->scheduledAt = null;
            }
            $this->status = $value;
        }
    }

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'contentEntries')]
    #[ORM\JoinColumn(nullable: false)]
    public ?Project $project = null {
        get => $this->project;
        set { $this->project = $value; }
    }

    #[ORM\ManyToOne(targetEntity: Collection::class, inversedBy: 'contentEntries')]
    #[ORM\JoinColumn(nullable: false)]
    public ?Collection $collection = null {
        get => $this->collection;
        set { $this->collection = $value; }
    }

    #[ORM\OneToMany(targetEntity: ContentFieldValue::class, mappedBy: 'contentEntry', cascade: ['persist', 'remove'])]
    public DoctrineCollection $fieldValues;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    public ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    public ?User $updatedBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'assigned_to_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    public ?User $assignedTo = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $scheduledAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $metaTitle = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $metaDescription = null;

    #[ORM\Column(length: 255)]
    public string $slug = '' {
        get => $this->slug;
        set { $this->slug = $value; }
    }

    #[ORM\Column(length: 512, nullable: true)]
    public ?string $canonicalUrl = null;

    #[ORM\Column(length: 36, nullable: true)]
    public ?string $ogImage = null;

    #[ORM\Column(nullable: true)]
    public ?int $seoScore = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $seoScoredAt = null;

    public function __construct()
    {
        $this->fieldValues = new ArrayCollection();
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

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
