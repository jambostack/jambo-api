<?php

namespace App\Entity;

use App\Repository\AutomationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AutomationRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Automation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    public ?Uuid $uuid = null;

    #[ORM\Column(length: 255)]
    public string $name;

    #[ORM\Column]
    public bool $isActive = true;

    #[ORM\Column]
    public bool $debugMode = false;

    /** content.created | content.updated | content.deleted | content.status_changed | schedule.cron */
    #[ORM\Column(length: 50)]
    public string $triggerType;

    /** JSON: {collection_slugs: [...], schedule: "0 9 * * *"} */
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $triggerConfig = null;

    /** JSON: [{field, operator, value}] */
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $conditions = null;

    /** send_email | call_webhook | create_entry | update_entry | send_notification */
    #[ORM\Column(length: 50)]
    public string $actionType;

    /** JSON spécifique à l'action */
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $actionConfig = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Project $project = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $lastRunAt = null;

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
