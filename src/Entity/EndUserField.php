<?php

namespace App\Entity;

use App\Repository\EndUserFieldRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EndUserFieldRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_end_user_field_project_slug', columns: ['project_id', 'slug'])]
class EndUserField
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Project $project;

    #[ORM\Column(length: 255)]
    public string $name;

    #[ORM\Column(length: 255)]
    public string $slug;

    #[ORM\Column(length: 50)]
    public string $type;

    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $options = null;

    #[ORM\Column(name: '`order`')]
    public int $order = 0;

    #[ORM\Column]
    public bool $isRequired = false;

    /** Champs protégés par le système d'auth (email, password, name, status). */
    #[ORM\Column]
    public bool $isSystem = false;
}
