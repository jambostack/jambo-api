<?php

namespace App\Entity;

use App\Repository\ColumnSettingRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ColumnSettingRepository::class)]
#[ORM\UniqueConstraint(columns: ['user_id', 'collection_id'])]
class ColumnSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Collection::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Collection $collection = null;

    /** Ordered list of visible field slugs */
    #[ORM\Column(type: 'json')]
    public array $visibleColumns = [];
}
