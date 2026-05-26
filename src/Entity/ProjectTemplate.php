<?php

namespace App\Entity;

use App\Repository\ProjectTemplateRepository;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types;

#[ORM\Entity(repositoryClass: ProjectTemplateRepository::class)]
#[ORM\HasLifecycleCallbacks]
class ProjectTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 255)]
    public string $name = '';

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $description = null;

    /**
     * Full project structure serialized as JSON:
     * { defaultLocale, locales, collections: [{ name, slug, fields: [...] }] }
     */
    #[ORM\Column(type: Types::JSON)]
    public array $structure = [];

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}
