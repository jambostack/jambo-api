<?php

namespace App\Entity;

use App\Repository\StudioChatMessageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: StudioChatMessageRepository::class)]
#[ORM\Table(name: 'studio_chat_message')]
class StudioChatMessage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    public Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Project $project;

    #[ORM\Column(length: 20)]
    public string $role = 'user'; // 'user' | 'assistant' | 'system'

    #[ORM\Column(type: 'text')]
    public string $content = '';

    /** @var array|null Schema JSON attached to assistant messages */
    #[ORM\Column(name: 'schema_data', type: 'json', nullable: true)]
    public ?array $schema = null;

    /** @var array|null Entries JSON attached to /data or /all responses */
    #[ORM\Column(name: 'entries_data', type: 'json', nullable: true)]
    public ?array $entries = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->uuid = Uuid::v4();
        $this->createdAt = new \DateTimeImmutable();
    }
}
