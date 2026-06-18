<?php

namespace App\Entity;

use App\Repository\FieldRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FieldRepository::class)]
class Field
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 255)]
    public string $name {
        get => $this->name;
        set { $this->name = $value; }
    }

    #[ORM\Column(length: 255)]
    public string $slug {
        get => $this->slug;
        set { $this->slug = $value; }
    }

    #[ORM\Column(length: 50)]
    public string $type {
        get => $this->type;
        set { $this->type = $value; }
    }

    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $options = null {
        get => $this->options;
        set { $this->options = $value; }
    }

    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $validationRules = null {
        get => $this->validationRules;
        set { $this->validationRules = $value; }
    }

    #[ORM\Column(name: '`order`')]
    public int $order = 0 {
        get => $this->order;
        set { $this->order = $value; }
    }

    #[ORM\Column]
    public bool $isRequired = false {
        get => $this->isRequired;
        set { $this->isRequired = $value; }
    }

    #[ORM\ManyToOne(targetEntity: Collection::class, inversedBy: 'fields')]
    #[ORM\JoinColumn(nullable: false)]
    public ?Collection $collection = null {
        get => $this->collection;
        set { $this->collection = $value; }
    }

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $deletedAt = null;

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
