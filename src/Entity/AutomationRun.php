<?php

namespace App\Entity;

use App\Repository\AutomationRunRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AutomationRunRepository::class)]
class AutomationRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Automation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?Automation $automation = null;

    /** running | success | failed */
    #[ORM\Column(length: 20)]
    public string $status = 'running';

    /** Payload complet du trigger (debug seulement) */
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $triggerPayload = null;

    /** Résultats par condition (debug) */
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $conditionResults = null;

    /** Payload après résolution templates (debug) */
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $actionInput = null;

    /** Réponse/résultat de l'action (debug) */
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $actionOutput = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $errorMessage = null;

    #[ORM\Column]
    public \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(nullable: true)]
    public ?int $durationMs = null;

    public function __construct()
    {
        $this->startedAt = new \DateTimeImmutable();
    }
}
