<?php

namespace App\Entity;

use App\Repository\WorkbenchProjectRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: WorkbenchProjectRepository::class)]
#[ORM\Table(name: 'workbench_project')]
#[ORM\HasLifecycleCallbacks]
class WorkbenchProject
{
    public const FRAMEWORKS = ['nextjs', 'nuxt', 'astro', 'sveltekit'];
    public const STATUS_DRAFT    = 'draft';
    public const STATUS_EXPORTED = 'exported';
    public const STATUS_DEPLOYED = 'deployed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    public Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Project $project;

    #[ORM\Column(length: 255)]
    public string $name = '';

    #[ORM\Column(length: 50)]
    public string $framework = 'nextjs';

    /** @var array<string,string> Map of file path → file content */
    #[ORM\Column(type: 'json')]
    public array $files = [];

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $generatedPrompt = null;

    #[ORM\Column(length: 20)]
    public string $deployStatus = self::STATUS_DRAFT;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $publishedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    public ?User $createdBy = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->uuid      = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
