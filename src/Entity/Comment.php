<?php

namespace App\Entity;

use App\Repository\CommentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CommentRepository::class)]
#[ORM\Table(name: 'comment')]
#[ORM\HasLifecycleCallbacks]
class Comment
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    public ?Uuid $uuid = null;

    #[ORM\Column(type: 'text')]
    public string $body = '';

    #[ORM\Column(length: 20, options: ['default' => 'open'])]
    public string $status = 'open';

    #[ORM\ManyToOne(targetEntity: ContentEntry::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?ContentEntry $entry = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?User $author = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?Comment $parent = null;

    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
    public Collection $children;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function setUuid(): void { $this->uuid ??= Uuid::v4(); }

    #[ORM\PreUpdate]
    public function setUpdatedAt(): void { $this->updatedAt = new \DateTimeImmutable(); }
}
