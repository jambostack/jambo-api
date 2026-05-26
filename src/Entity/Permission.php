<?php

namespace App\Entity;

use App\Repository\PermissionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PermissionRepository::class)]
#[ORM\Table(name: '`permission`')]
class Permission
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    /** Machine name, e.g. content.create, content.delete, project.manage */
    #[ORM\Column(length: 100, unique: true)]
    public string $name = '';

    /** Human-friendly display name */
    #[ORM\Column(length: 255)]
    public string $label = '';

    /** Grouping key, e.g. "content", "media", "settings" */
    #[ORM\Column(name: '`group`', length: 50)]
    public string $group = 'general';
}
