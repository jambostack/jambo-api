<?php

namespace App\Entity;

use App\Repository\AssetMetadataRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AssetMetadataRepository::class)]
class AssetMetadata
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\OneToOne(targetEntity: Media::class, inversedBy: 'metadata')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Media $media = null;

    #[ORM\Column(nullable: true)]
    public ?int $width = null;

    #[ORM\Column(nullable: true)]
    public ?int $height = null;
}
