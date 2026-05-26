<?php

namespace App\Entity;

use App\Repository\ContentRelationFieldRelationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContentRelationFieldRelationRepository::class)]
class ContentRelationFieldRelation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ContentFieldValue::class, inversedBy: 'valueRelations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?ContentFieldValue $contentFieldValue = null;

    #[ORM\ManyToOne(targetEntity: ContentEntry::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?ContentEntry $relatedEntry = null;

    #[ORM\Column]
    public int $position = 0;
}
