<?php

namespace App\Entity;

use App\Repository\FormSubmissionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: FormSubmissionRepository::class)]
#[ORM\HasLifecycleCallbacks]
class FormSubmission
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    public ?Uuid $uuid = null;

    #[ORM\ManyToOne(targetEntity: Form::class)]
    #[ORM\JoinColumn(nullable: false)]
    public ?Form $form = null;

    #[ORM\Column(type: 'json')]
    public array $data = [];

    #[ORM\Column(type: 'json')]
    public array $metadata = [];

    #[ORM\Column(nullable: true)]
    public ?int $step = null;

    #[ORM\Column]
    public bool $isComplete = false;

    #[ORM\Column]
    public bool $isSpam = false;

    #[ORM\Column]
    public bool $isRead = false;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $abVariant = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $readAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function setUuidValue(): void
    {
        if ($this->uuid === null) $this->uuid = Uuid::v4();
    }
}
