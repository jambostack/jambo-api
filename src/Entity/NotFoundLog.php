<?php

namespace App\Entity;

use App\Repository\NotFoundLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotFoundLogRepository::class)]
class NotFoundLog
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false)]
    public ?Project $project = null;

    #[ORM\Column(length: 512)]
    public string $path = '';

    #[ORM\Column(length: 512, nullable: true)]
    public ?string $referrer = null;

    #[ORM\Column(length: 512, nullable: true)]
    public ?string $userAgent = null;

    #[ORM\Column(length: 45, nullable: true)]
    public ?string $ip = null;

    #[ORM\Column]
    public int $count = 1;

    #[ORM\Column]
    public \DateTimeImmutable $firstSeenAt;

    #[ORM\Column]
    public \DateTimeImmutable $lastSeenAt;

    #[ORM\Column]
    public bool $isResolved = false;

    public function __construct()
    {
        $this->firstSeenAt = new \DateTimeImmutable();
        $this->lastSeenAt = new \DateTimeImmutable();
    }
}
