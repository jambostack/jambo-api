<?php

namespace App\Entity;

use App\Repository\WebhookLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\DBAL\Types\Types;

#[ORM\Entity(repositoryClass: WebhookLogRepository::class)]
class WebhookLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Webhook::class, inversedBy: 'logs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Webhook $webhook = null;

    #[ORM\Column(length: 50)]
    public string $event = '';

    /** HTTP status code returned by the target URL */
    #[ORM\Column(nullable: true)]
    public ?int $statusCode = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $requestPayload = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $responseBody = null;

    /** succeeded | failed */
    #[ORM\Column(length: 20)]
    public string $status = 'pending';

    #[ORM\Column(nullable: true)]
    public ?string $errorMessage = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}
