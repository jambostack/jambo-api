<?php

namespace App\Entity;

use App\Repository\WorkbenchEnvVarRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WorkbenchEnvVarRepository::class)]
#[ORM\Table(name: 'workbench_env_var')]
#[ORM\UniqueConstraint(name: 'uniq_workbench_env_var', columns: ['workbench_project_id', 'key_name'])]
#[ORM\HasLifecycleCallbacks]
class WorkbenchEnvVar
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WorkbenchProject::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public WorkbenchProject $workbenchProject;

    #[ORM\Column(length: 120)]
    public string $keyName = '';

    #[ORM\Column(type: 'text')]
    public string $value = '';

    /** Masqué dans l'UI — NE garantit PAS la confidentialité côté front statique. */
    #[ORM\Column]
    public bool $isSecret = false;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
