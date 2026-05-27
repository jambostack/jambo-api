<?php

namespace App\Entity;

use App\Repository\PasswordResetTokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PasswordResetTokenRepository::class)]
class PasswordResetToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Project $project;

    #[ORM\Column(length: 180)]
    public string $email = '';

    #[ORM\Column(length: 64, unique: true)]
    public string $token = '';

    #[ORM\Column]
    public \DateTimeImmutable $expiresAt;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct(Project $project, string $email)
    {
        $this->project = $project;
        $this->email = $email;
        $this->token = bin2hex(random_bytes(32));
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = new \DateTimeImmutable('+1 hour');
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }
}
