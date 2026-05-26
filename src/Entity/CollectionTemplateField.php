<?php

namespace App\Entity;

use App\Repository\CollectionTemplateFieldRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CollectionTemplateFieldRepository::class)]
class CollectionTemplateField
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CollectionTemplate::class, inversedBy: 'fields')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?CollectionTemplate $collectionTemplate = null;

    #[ORM\Column(length: 255)]
    public string $name = '';

    #[ORM\Column(length: 255)]
    public string $slug = '';

    #[ORM\Column(length: 50)]
    public string $type = 'text';

    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $options = null;

    #[ORM\Column(name: '`order`')]
    public int $order = 0;

    #[ORM\Column]
    public bool $isRequired = false;
}
