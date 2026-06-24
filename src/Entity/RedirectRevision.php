<?php

namespace App\Entity;

use App\Repository\RedirectRevisionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RedirectRevisionRepository::class)]
class RedirectRevision
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Redirect::class)]
    #[ORM\JoinColumn(nullable: false)]
    public ?Redirect $redirect = null;

    #[ORM\Column(length: 512)]
    public string $fromPath = '';

    #[ORM\Column(length: 512)]
    public string $toPath = '';

    #[ORM\Column]
    public int $httpCode = 301;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    public ?User $changedBy = null;

    #[ORM\Column]
    public \DateTimeImmutable $changedAt;

    public function __construct()
    {
        $this->changedAt = new \DateTimeImmutable();
    }
}
