<?php
// src/Entity/CustomDomain.php
namespace App\Entity;

use App\Repository\CustomDomainRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CustomDomainRepository::class)]
#[ORM\Table(name: 'custom_domain')]
class CustomDomain
{
    public const SSL_PENDING = 'pending';
    public const SSL_ACTIVE  = 'active';
    public const SSL_ERROR   = 'error';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    public Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: HostedApp::class, inversedBy: 'customDomains')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public HostedApp $hostedApp;

    #[ORM\Column(length: 253, unique: true)]
    public string $domain = '';

    #[ORM\Column(length: 64)]
    public string $verificationToken = '';

    #[ORM\Column]
    public bool $verified = false;

    #[ORM\Column(length: 20)]
    public string $sslStatus = self::SSL_PENDING;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $verifiedAt = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->uuid      = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
    }
}
