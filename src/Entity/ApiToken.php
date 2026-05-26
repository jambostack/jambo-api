<?php

namespace App\Entity;

use App\Repository\ApiTokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
#[ORM\HasLifecycleCallbacks]
class ApiToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 255)]
    public string $name = '';

    /** Hashed token stored in DB; plaintext only shown once at creation */
    #[ORM\Column(length: 64, unique: true)]
    public string $tokenHash = '';

    #[ORM\Column(options: ['default' => 1])]
    public int $tokenVersion = 1;

    /** Token abilities: read, write, delete (empty = read-only) */
    #[ORM\Column(type: 'json')]
    public array $abilities = ['read'];

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Project $project = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt < new \DateTimeImmutable();
    }

    public function can(string $ability): bool
    {
        return in_array($ability, $this->abilities, true);
    }

    public static function generatePlainToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hashToken(string $plainToken, string $appSecret): string
    {
        return hash_hmac('sha256', $plainToken, $appSecret);
    }

    public static function hashTokenLegacy(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
