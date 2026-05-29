<?php

namespace App\Entity;

use App\Repository\PageTemplateRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PageTemplateRepository::class)]
#[ORM\Table(name: 'page_template')]
#[ORM\UniqueConstraint(name: 'uniq_page_template_project_slug', columns: ['project_id', 'slug'])]
#[ORM\HasLifecycleCallbacks]
class PageTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    public ?Uuid $uuid = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Project $project;

    #[ORM\Column(length: 255)]
    public string $name = '';

    #[ORM\Column(length: 255)]
    public string $slug = '';

    /**
     * Sections describing the page layout. Schema:
     *   [{ "key": string, "type": "hero|list|detail|form|grid|custom",
     *      "title": string, "collection"?: string, "fields"?: string[],
     *      "customCode"?: string, "order": int }]
     */
    #[ORM\Column(type: 'json')]
    public array $sections = [];

    /** Generated React/TSX source last produced by the Page Builder. */
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $generatedCode = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    public ?User $createdBy = null;

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
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
