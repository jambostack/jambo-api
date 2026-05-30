<?php

namespace App\Entity;

use App\Repository\ProjectMailerSettingsRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProjectMailerSettingsRepository::class)]
#[ORM\Table(name: 'project_mailer_settings')]
#[ORM\HasLifecycleCallbacks]
class ProjectMailerSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\OneToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    public Project $project;

    #[Assert\NotBlank]
    #[ORM\Column(length: 255)]
    public string $host = '';

    #[Assert\Range(min: 1, max: 65535)]
    #[ORM\Column]
    public int $port = 587;

    #[ORM\Column(length: 255)]
    public string $username = '';

    /** Chiffré avec APP_SECRET (AES-256-GCM via sodium). */
    #[ORM\Column(type: 'text')]
    public string $encryptedPassword = '';

    #[Assert\Choice(['tls', 'ssl', 'none'])]
    #[ORM\Column(length: 10)]
    public string $encryption = 'tls';

    #[Assert\NotBlank]
    #[Assert\Email]
    #[ORM\Column(length: 255)]
    public string $fromEmail = '';

    #[ORM\Column(length: 255)]
    public string $fromName = '';

    #[ORM\Column]
    public bool $enabled = false;

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
    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
