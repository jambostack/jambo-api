<?php

namespace App\Entity;

use App\Repository\ContentFieldValueRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection as DoctrineCollection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types;

#[ORM\Entity(repositoryClass: ContentFieldValueRepository::class)]
class ContentFieldValue
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ContentEntry::class, inversedBy: 'fieldValues')]
    #[ORM\JoinColumn(nullable: false)]
    public ?ContentEntry $contentEntry = null {
        get => $this->contentEntry;
        set { $this->contentEntry = $value; }
    }

    #[ORM\ManyToOne(targetEntity: Field::class)]
    #[ORM\JoinColumn(nullable: false)]
    public ?Field $field = null {
        get => $this->field;
        set { $this->field = $value; }
    }

    #[ORM\Column(length: 50)]
    public string $fieldType {
        get => $this->fieldType;
        set { $this->fieldType = $value; }
    }

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $textValue = null {
        get => $this->textValue;
        set { $this->textValue = $value; }
    }

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 6, nullable: true)]
    public ?string $numberValue = null {
        get => $this->numberValue;
        set { $this->numberValue = $value; }
    }

    #[ORM\Column(nullable: true)]
    public ?bool $booleanValue = null {
        get => $this->booleanValue;
        set { $this->booleanValue = $value; }
    }

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    public ?\DateTimeInterface $dateValue = null {
        get => $this->dateValue;
        set { $this->dateValue = $value; }
    }

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    public ?\DateTimeInterface $datetimeValue = null {
        get => $this->datetimeValue;
        set { $this->datetimeValue = $value; }
    }

    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $jsonValue = null {
        get => $this->jsonValue;
        set { $this->jsonValue = $value; }
    }

    #[ORM\OneToMany(targetEntity: ContentMediaRelation::class, mappedBy: 'contentFieldValue', cascade: ['persist', 'remove'])]
    public DoctrineCollection $mediaRelations;

    #[ORM\OneToMany(targetEntity: ContentRelationFieldRelation::class, mappedBy: 'contentFieldValue', cascade: ['persist', 'remove'])]
    public DoctrineCollection $valueRelations;

    public function __construct()
    {
        $this->mediaRelations = new ArrayCollection();
        $this->valueRelations = new ArrayCollection();
    }
}
