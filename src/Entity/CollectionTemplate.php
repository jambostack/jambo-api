<?php

namespace App\Entity;

use App\Repository\CollectionTemplateRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection as DoctrineCollection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CollectionTemplateRepository::class)]
#[ORM\HasLifecycleCallbacks]
class CollectionTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 255)]
    public string $name = '';

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $description = null;

    #[ORM\Column]
    public bool $isSingleton = false;

    #[ORM\OneToMany(targetEntity: CollectionTemplateField::class, mappedBy: 'collectionTemplate', cascade: ['persist', 'remove'])]
    public DoctrineCollection $fields;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->fields = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }
}
