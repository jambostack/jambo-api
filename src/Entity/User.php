<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection as DoctrineCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    public ?Uuid $uuid = null;

    #[ORM\Column(length: 180, unique: true)]
    public string $email {
        get => $this->email;
        set { $this->email = $value; }
    }

    #[ORM\Column]
    public array $roles = [];

    #[ORM\Column]
    public string $password = '';

    #[ORM\Column(length: 255)]
    public string $name = '';

    #[ORM\Column(length: 5, options: ['default' => 'en'])]
    public string $locale = 'en';

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    public bool $twoFactorEnabled = false;

    #[ORM\Column(length: 10, nullable: true)]
    public ?string $twoFactorMethod = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $twoFactorSecret = null;

    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $twoFactorBackupCodes = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $twoFactorConfirmedAt = null;

    #[ORM\ManyToMany(targetEntity: Role::class, inversedBy: 'users')]
    #[ORM\JoinTable(name: 'user_roles')]
    public DoctrineCollection $userRoles;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->userRoles = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function hasRole(string $roleName): bool
    {
        foreach ($this->userRoles as $role) {
            if ($role->name === $roleName) {
                return true;
            }
        }
        return false;
    }

    public function hasPermission(string $permissionName): bool
    {
        foreach ($this->userRoles as $role) {
            if ($role->hasPermission($permissionName)) {
                return true;
            }
        }
        return false;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
    }

    #[ORM\PrePersist]
    public function setUuidValue(): void
    {
        if ($this->uuid === null) {
            $this->uuid = Uuid::v4();
        }
    }
}
