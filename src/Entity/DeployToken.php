<?php
// src/Entity/DeployToken.php
namespace App\Entity;

use App\Repository\DeployTokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DeployTokenRepository::class)]
#[ORM\Table(name: 'deploy_token')]
#[ORM\UniqueConstraint(name: 'uniq_deploy_token_user_provider', columns: ['user_id', 'provider'])]
class DeployToken
{
    public const PROVIDER_VERCEL  = 'vercel';
    public const PROVIDER_NETLIFY = 'netlify';
    public const PROVIDER_RAILWAY = 'railway';
    public const PROVIDERS = [self::PROVIDER_VERCEL, self::PROVIDER_NETLIFY, self::PROVIDER_RAILWAY];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public User $user;

    #[ORM\Column(length: 20)]
    public string $provider = '';

    /** AES-256-GCM encrypted token: base64(iv + tag + ciphertext) */
    #[ORM\Column(type: 'text')]
    public string $encryptedToken = '';

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt < new \DateTimeImmutable();
    }
}
