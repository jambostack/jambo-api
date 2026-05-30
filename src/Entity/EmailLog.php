<?php

namespace App\Entity;

use App\Repository\EmailLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EmailLogRepository::class)]
#[ORM\Table(name: 'email_log')]
class EmailLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Project $project;

    #[ORM\Column(length: 255)]
    public string $to;

    #[ORM\Column(length: 255)]
    public string $subject;

    #[ORM\Column]
    public \DateTimeImmutable $sentAt;

    /** null = succès, string = message d'erreur */
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $error = null;

    public function __construct(Project $project, string $to, string $subject)
    {
        $this->project = $project;
        $this->to      = $to;
        $this->subject = $subject;
        $this->sentAt  = new \DateTimeImmutable();
    }
}
