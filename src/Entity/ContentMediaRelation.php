<?php

namespace App\Entity;

use App\Repository\ContentMediaRelationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContentMediaRelationRepository::class)]
class ContentMediaRelation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ContentFieldValue::class, inversedBy: 'mediaRelations')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?ContentFieldValue $contentFieldValue = null;

    #[ORM\ManyToOne(targetEntity: Media::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Media $media = null;

    #[ORM\Column]
    public int $position = 0;
}
