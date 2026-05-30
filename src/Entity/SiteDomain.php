<?php

namespace App\Entity;

use App\Repository\SiteDomainRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: SiteDomainRepository::class)]
#[ORM\Table(name: 'site_domain')]
#[ORM\HasLifecycleCallbacks]
class SiteDomain
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    public Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: WorkbenchProject::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public WorkbenchProject $workbenchProject;

    #[Assert\NotBlank]
    #[Assert\Length(max: 253)]
    #[ORM\Column(length: 253, unique: true)]
    public string $domain = '';

    #[ORM\Column]
    public bool $isPrimary = false;

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
