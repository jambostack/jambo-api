<?php

namespace App\Entity;

use App\Repository\SeoRevisionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SeoRevisionRepository::class)]
class SeoRevision
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ContentEntry::class)]
    #[ORM\JoinColumn(nullable: false)]
    public ?ContentEntry $entry = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $metaTitle = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $metaDescription = null;

    #[ORM\Column(length: 255)]
    public string $slug = '';

    #[ORM\Column(length: 512, nullable: true)]
    public ?string $canonicalUrl = null;

    #[ORM\Column(length: 36, nullable: true)]
    public ?string $ogImage = null;

    #[ORM\Column(nullable: true)]
    public ?int $seoScore = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    public ?User $changedBy = null;

    #[ORM\Column]
    public \DateTimeImmutable $changedAt;

    public function __construct()
    {
        $this->changedAt = new \DateTimeImmutable();
    }
}
