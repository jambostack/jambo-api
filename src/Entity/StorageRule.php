<?php

namespace App\Entity;

use App\Repository\StorageRuleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StorageRuleRepository::class)]
#[ORM\Table(name: 'storage_rule')]
class StorageRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'storageRules')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Project $project;

    #[ORM\ManyToOne(targetEntity: ProjectStorageProfile::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ProjectStorageProfile $storageProfile;

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $mimeTypePattern = null; // ex: "image/*", "video/*"

    #[ORM\Column(length: 20, nullable: true)]
    public ?string $extension = null; // ex: "pdf", "mp4"

    #[ORM\Column(nullable: true)]
    public ?int $maxSize = null; // bytes

    #[ORM\Column]
    public int $priority = 0;

    /** Vérifie si cette règle matche un fichier donné. */
    public function matches(string $mimeType, string $filename, int $size): bool
    {
        if ($this->mimeTypePattern !== null && $this->mimeTypePattern !== '') {
            $regex = '#^' . str_replace('\*', '.*', preg_quote($this->mimeTypePattern, '#')) . '$#';
            if (!preg_match($regex, $mimeType)) {
                return false;
            }
        }

        if ($this->extension !== null && $this->extension !== '') {
            $actualExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if ($actualExt !== strtolower($this->extension)) {
                return false;
            }
        }

        if ($this->maxSize !== null && $size > $this->maxSize) {
            return false;
        }

        return true;
    }
}
