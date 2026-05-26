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

    #[ORM\Column(length: 50)]
    public string $disk = 'public' {
        get => $this->disk;
        set { $this->disk = $value; }
    }

    #[ORM\Column]
    public bool $publicApi = false {
        get => $this->publicApi;
        set { $this->publicApi = $value; }
    }

    #[ORM\OneToMany(targetEntity: Collection::class, mappedBy: 'project', cascade: ['persist', 'remove'])]
    public DoctrineCollection $collections;

    #[ORM\OneToMany(targetEntity: ProjectMember::class, mappedBy: 'project', cascade: ['persist', 'remove'])]
    public DoctrineCollection $projectMembers;

    public function __construct()
    {
        $this->collections    = new ArrayCollection();
        $this->projectMembers = new ArrayCollection();
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
