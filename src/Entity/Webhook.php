<?php

namespace App\Entity;

use App\Repository\WebhookRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection as DoctrineCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: WebhookRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Webhook
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    public ?Uuid $uuid = null;

    #[ORM\Column(length: 255)]
    public string $name = '';

    #[ORM\Column(length: 500)]
    public string $url = '';

    /** Events that trigger this webhook: content.created, content.updated, content.deleted */
    #[ORM\Column(type: 'json')]
    public array $events = [];

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $secret = null;

    #[ORM\Column]
    public bool $isActive = true;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Project $project = null;

    #[ORM\ManyToMany(targetEntity: Collection::class)]
    #[ORM\JoinTable(name: 'webhook_collections')]
    public DoctrineCollection $collections;

    #[ORM\OneToMany(targetEntity: WebhookLog::class, mappedBy: 'webhook', cascade: ['remove'])]
    public DoctrineCollection $logs;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->collections = new ArrayCollection();
        $this->logs = new ArrayCollection();
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
}
