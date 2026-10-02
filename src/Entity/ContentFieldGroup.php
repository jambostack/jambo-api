<?php

namespace App\Entity;

use App\Repository\ContentFieldGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection as DoctrineCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ContentFieldGroupRepository::class)]
#[ORM\Table(name: 'content_field_groups')]
#[ORM\HasLifecycleCallbacks]
class ContentFieldGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    public ?Uuid $uuid = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Project $project;

    #[ORM\ManyToOne(targetEntity: Collection::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Collection $collection;

    #[ORM\ManyToOne(targetEntity: ContentEntry::class, inversedBy: 'fieldGroups')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ContentEntry $contentEntry;

    /** Le champ parent représentant le répétiteur ou groupe */
    #[ORM\ManyToOne(targetEntity: Field::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Field $field;

    #[ORM\Column(options: ['default' => 0])]
    public int $sortOrder = 0;

    /** @var DoctrineCollection<int, ContentFieldValue> */
    #[ORM\OneToMany(targetEntity: ContentFieldValue::class, mappedBy: 'groupInstance', cascade: ['persist', 'remove'], orphanRemoval: true)]
    public DoctrineCollection $values;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->uuid = Uuid::v4();
        $this->values = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function initializeUuid(): void
    {
        if ($this->uuid === null) {
            $this->uuid = Uuid::v4();
        }
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function addValue(ContentFieldValue $value): self
    {
        if (!$this->values->contains($value)) {
            $this->values->add($value);
            $value->groupInstance = $this;
        }
        return $this;
    }

    public function removeValue(ContentFieldValue $value): self
    {
        if ($this->values->removeElement($value)) {
            if ($value->groupInstance === $this) {
                $value->groupInstance = null;
            }
        }
        return $this;
    }
}
