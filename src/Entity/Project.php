<?php

namespace App\Entity;

use App\Enum\ProjectMemberStatus;
use App\Repository\ProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection as DoctrineCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Project
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

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $description = null {
        get => $this->description;
        set { $this->description = $value; }
    }

    #[ORM\Column(length: 10)]
    public string $defaultLocale = 'en' {
        get => $this->defaultLocale;
        set { $this->defaultLocale = $value; }
    }

    #[ORM\Column(type: 'json')]
    public array $locales = ['en'] {
        get => $this->locales;
        set { $this->locales = $value; }
    }

    /**
     * @deprecated Use storageProfiles + storageStrategy instead.
     */
    #[ORM\Column(length: 50, nullable: true)]
    public ?string $disk = 'public' {
        get {
            if (!isset($this->storageProfiles)) {
                return $this->disk;
            }
            $default = $this->storageProfiles
                ->filter(fn (ProjectStorageProfile $p) => $p->isDefault)
                ->first();
            if (!$default) {
                return 'public';
            }
            return $default->driver === 's3' ? 's3' : 'public';
        }
        set { $this->disk = $value; }
    }

    #[ORM\Column]
    public bool $publicApi = false {
        get => $this->publicApi;
        set { $this->publicApi = $value; }
    }

    #[ORM\Column(length: 20)]
    public string $storageStrategy = 'default_only' {
        get => $this->storageStrategy;
        set { $this->storageStrategy = $value; }
    }

    /** Paramètres additionnels du projet (sécurité end-users, social login, etc.) */
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $settings = null;

    #[ORM\OneToMany(targetEntity: ProjectStorageProfile::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    public DoctrineCollection $storageProfiles;

    #[ORM\OneToMany(targetEntity: StorageRule::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    public DoctrineCollection $storageRules;

    /**
     * JWT access token TTL in seconds. Null = use default (900s = 15 min).
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $jwtAccessTtl = null {
        get => $this->jwtAccessTtl;
        set { $this->jwtAccessTtl = $value; }
    }

    /**
     * JWT refresh token TTL in seconds. Null = use default (2592000s = 30 days).
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    public ?int $jwtRefreshTtl = null {
        get => $this->jwtRefreshTtl;
        set { $this->jwtRefreshTtl = $value; }
    }

    #[ORM\OneToMany(targetEntity: Collection::class, mappedBy: 'project', cascade: ['persist', 'remove'])]
    public DoctrineCollection $collections;

    #[ORM\OneToMany(targetEntity: ProjectMember::class, mappedBy: 'project', cascade: ['persist', 'remove'])]
    public DoctrineCollection $projectMembers;

    public function __construct()
    {
        $this->collections     = new ArrayCollection();
        $this->projectMembers  = new ArrayCollection();
        $this->storageProfiles = new ArrayCollection();
        $this->storageRules    = new ArrayCollection();
    }

    public function hasMember(User $user): bool
    {
        foreach ($this->projectMembers as $member) {
            if ($member->user?->id === $user->id && $member->status === ProjectMemberStatus::Active) {
                return true;
            }
        }
        return false;
    }

    public function getMemberForUser(User $user): ?ProjectMember
    {
        foreach ($this->projectMembers as $member) {
            if ($member->user?->id === $user->id && $member->status === ProjectMemberStatus::Active) {
                return $member;
            }
        }
        return null;
    }

    #[ORM\PrePersist]
    public function setUuidValue(): void
    {
        if ($this->uuid === null) {
            $this->uuid = Uuid::v4();
        }
    }
}
