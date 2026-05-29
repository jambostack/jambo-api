<?php
// src/Entity/HostedApp.php
namespace App\Entity;

use App\Repository\HostedAppRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: HostedAppRepository::class)]
#[ORM\Table(name: 'hosted_app')]
#[ORM\HasLifecycleCallbacks]
class HostedApp
{
    public const STATUS_PROVISIONING = 'provisioning';
    public const STATUS_RUNNING       = 'running';
    public const STATUS_STOPPED       = 'stopped';
    public const STATUS_FAILED        = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    public Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: WorkbenchProject::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public WorkbenchProject $workbenchProject;

    #[ORM\Column(length: 100, unique: true)]
    public string $subdomain = '';

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $containerId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $imageRef = null;

    #[ORM\Column]
    public int $internalPort = 3000;

    #[ORM\Column(length: 20)]
    public string $status = self::STATUS_PROVISIONING;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $lastError = null;

    /** @var Collection<int, CustomDomain> */
    #[ORM\OneToMany(mappedBy: 'hostedApp', targetEntity: CustomDomain::class, cascade: ['remove'])]
    public Collection $customDomains;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->uuid          = Uuid::v4();
        $this->createdAt     = new \DateTimeImmutable();
        $this->updatedAt     = new \DateTimeImmutable();
        $this->customDomains = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
