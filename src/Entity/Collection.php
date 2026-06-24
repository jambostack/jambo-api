<?php

namespace App\Entity;

use App\Repository\CollectionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection as DoctrineCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CollectionRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: '`collection`')]
class Collection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    public ?Uuid $uuid = null;

    #[ORM\Column(length: 255)]
    public string $name {
        get => $this->name;
        set { $this->name = $value; }
    }

    #[ORM\Column(length: 255)]
    public string $slug {
        get => $this->slug;
        set { $this->slug = $value; }
    }

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $description = null {
        get => $this->description;
        set { $this->description = $value; }
    }

    #[ORM\Column]
    public bool $isSingleton = false {
        get => $this->isSingleton;
        set { $this->isSingleton = $value; }
    }

    #[ORM\Column(name: '`order`')]
    public int $order = 0 {
        get => $this->order;
        set { $this->order = $value; }
    }

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'collections')]
    #[ORM\JoinColumn(nullable: false)]
    public ?Project $project = null {
        get => $this->project;
        set { $this->project = $value; }
    }

    #[ORM\OneToMany(targetEntity: Field::class, mappedBy: 'collection', cascade: ['persist', 'remove'])]
    public DoctrineCollection $fields;

    #[ORM\OneToMany(targetEntity: ContentEntry::class, mappedBy: 'collection', cascade: ['persist', 'remove'])]
    public DoctrineCollection $contentEntries;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $settings = null;

    /**
     * @return array<array{slug: string, label: string, color: string, published: bool}>
     */
    public function getWorkflowStatuses(): array
    {
        $defaults = [
            ['slug' => 'draft',     'label' => 'Draft',       'color' => '#6b7280', 'published' => false],
            ['slug' => 'published', 'label' => 'Published',   'color' => '#10b981', 'published' => true],
        ];
        if ($this->settings === null || !isset($this->settings['workflow'])) {
            return $defaults;
        }
        $statuses = $this->settings['workflow']['statuses'] ?? $defaults;
        if (empty($statuses)) {
            return $defaults;
        }
        return $statuses;
    }

    /**
     * @return array{indexable: bool, sitemapPriority: float, sitemapChangefreq: string, autoGenerateSlug: bool, slugSourceField: ?string, defaultOgImage: ?string, structuredDataType: string}
     */
    public function getSeoSettings(): array
    {
        $s = $this->settings['seo'] ?? [];
        return [
            'indexable' => $s['indexable'] ?? true,
            'sitemapPriority' => (float) ($s['sitemapPriority'] ?? 0.5),
            'sitemapChangefreq' => $s['sitemapChangefreq'] ?? 'weekly',
            'autoGenerateSlug' => $s['autoGenerateSlug'] ?? true,
            'slugSourceField' => $s['slugSourceField'] ?? 'title',
            'defaultOgImage' => $s['defaultOgImage'] ?? null,
            'structuredDataType' => $s['structuredDataType'] ?? 'Article',
        ];
    }

    public function getDefaultStatus(): string
    {
        if ($this->settings === null || !isset($this->settings['workflow'])) {
            return 'draft';
        }
        return $this->settings['workflow']['defaultStatus'] ?? 'draft';
    }

    public function getPublishedStatus(): ?string
    {
        $statuses = $this->getWorkflowStatuses();
        foreach ($statuses as $s) {
            if ($s['published'] ?? false) {
                return $s['slug'];
            }
        }
        return null;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function __construct()
    {
        $this->fields = new ArrayCollection();
        $this->contentEntries = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function setUuidValue(): void
    {
        if ($this->uuid === null) {
            $this->uuid = Uuid::v4();
        }
    }
}
