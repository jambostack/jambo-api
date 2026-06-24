<?php

namespace App\Entity;

use App\Repository\FormSubmissionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FormSubmissionRepository::class)]
#[ORM\HasLifecycleCallbacks]
class FormSubmission
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Form::class)]
    #[ORM\JoinColumn(nullable: false)]
    public ?Form $form = null;

    #[ORM\Column(type: 'json')]
    public array $data = [];

    #[ORM\Column(type: 'json')]
    public array $metadata = [];

    #[ORM\Column]
    public bool $isComplete = false;

    #[ORM\Column]
    public bool $isSpam = false;

    #[ORM\Column]
    public bool $isRead = false;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}
