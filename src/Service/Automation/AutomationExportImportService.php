<?php

declare(strict_types=1);

namespace App\Service\Automation;

use App\Entity\Automation;
use App\Entity\Project;
use App\Repository\AutomationRepository;
use App\Service\Flow\FlowValidator;
use App\Service\Flow\NodeRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Service d'export/import sécurisé d'automatisations avec vérification d'intégrité des graphes.
 */
class AutomationExportImportService
{
    public const SCHEMA_VERSION = '1.0';
    public const MAX_PAYLOAD_SIZE = 2097152; // 2 Mo max
    public const MAX_BUNDLE_ITEMS = 50;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AutomationRepository $automationRepo,
        private readonly FlowValidator $flowValidator,
        private readonly NodeRegistry $nodeRegistry,
    ) {}

    /**
     * Exporte une automatisation unique avec métadonnées d'intégrité et empreinte SHA-256.
     *
     * @return array<string, mixed>
     */
    public function exportAutomation(Automation $automation): array
    {
        $automationData = [
            'name'       => $automation->name,
            'is_active'  => $automation->isActive,
            'debug_mode' => $automation->debugMode,
            'flow_graph' => $automation->flowGraph ?? ['nodes' => [], 'edges' => []],
        ];

        $canonicalJson = json_encode($automationData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $checksum = 'sha256:' . hash('sha256', $canonicalJson);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'type'           => 'single_automation',
            'exported_at'    => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'checksum'       => $checksum,
            'automation'     => $automationData,
        ];
    }

    /**
     * Exporte toutes les automatisations d'un projet dans un bundle structuré.
     *
     * @return array<string, mixed>
     */
    public function exportProjectAutomations(Project $project): array
    {
        $automations = $this->automationRepo->findByProject($project);
        $items = [];
        foreach ($automations as $automation) {
            $items[] = [
                'name'       => $automation->name,
                'is_active'  => $automation->isActive,
                'debug_mode' => $automation->debugMode,
                'flow_graph' => $automation->flowGraph ?? ['nodes' => [], 'edges' => []],
            ];
        }

        $canonicalJson = json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $checksum = 'sha256:' . hash('sha256', $canonicalJson);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'type'           => 'project_automations_bundle',
            'project_name'   => $project->name,
            'count'          => count($items),
            'exported_at'    => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'checksum'       => $checksum,
            'automations'    => $items,
        ];
    }

    /**
     * Importe et sécurise une automatisation dans le projet cible.
     *
     * @param array<string, mixed> $payload
     * @throws \InvalidArgumentException en cas d'erreur de schéma, d'intégrité ou de validation du graphe
     */
    public function importAutomation(Project $project, array $payload): Automation
    {
        $schemaVersion = $payload['schema_version'] ?? null;
        if ($schemaVersion !== self::SCHEMA_VERSION) {
            throw new \InvalidArgumentException(sprintf('Unsupported schema version "%s". Expected "%s".', (string) $schemaVersion, self::SCHEMA_VERSION));
        }

        $autoData = $payload['automation'] ?? null;
        if (!is_array($autoData)) {
            throw new \InvalidArgumentException('Payload missing "automation" object.');
        }

        // Vérification de la somme de contrôle si fournie
        $checksum = $payload['checksum'] ?? null;
        if (is_string($checksum) && str_starts_with($checksum, 'sha256:')) {
            $expectedHash = substr($checksum, 7);
            $canonicalJson = json_encode($autoData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $actualHash = hash('sha256', $canonicalJson);
            if (!hash_equals($expectedHash, $actualHash)) {
                throw new \InvalidArgumentException('Checksum mismatch: payload data has been altered or corrupted.');
            }
        }

        $name = trim((string) ($autoData['name'] ?? 'Imported Automation'));
        if ($name === '') {
            $name = 'Imported Automation';
        }
        if (strlen($name) > 255) {
            $name = substr($name, 0, 255);
        }

        $flowGraph = $autoData['flow_graph'] ?? null;
        if (!is_array($flowGraph)) {
            throw new \InvalidArgumentException('flow_graph must be a valid JSON object with nodes and edges.');
        }

        // Validation stricte du graphe de flux
        $validation = $this->flowValidator->validate($flowGraph, $this->nodeRegistry);
        if (!$validation['valid']) {
            throw new \InvalidArgumentException('Invalid flow graph: ' . implode('; ', $validation['errors']));
        }

        $automation = new Automation();
        $automation->uuid       = Uuid::v4();
        $automation->project    = $project;
        $automation->name       = $name;
        $automation->flowGraph  = $flowGraph;
        $automation->isActive   = (bool) ($autoData['is_active'] ?? false); // Par défaut inactif après import par mesure de sécurité
        $automation->debugMode  = (bool) ($autoData['debug_mode'] ?? false);
        $automation->lastRunAt  = null;

        $this->em->persist($automation);
        $this->em->flush();

        return $automation;
    }

    /**
     * Importe un bundle d'automatisations.
     *
     * @param array<string, mixed> $payload
     * @return list<Automation>
     */
    public function importProjectAutomations(Project $project, array $payload): array
    {
        $schemaVersion = $payload['schema_version'] ?? null;
        if ($schemaVersion !== self::SCHEMA_VERSION) {
            throw new \InvalidArgumentException(sprintf('Unsupported schema version "%s". Expected "%s".', (string) $schemaVersion, self::SCHEMA_VERSION));
        }

        $items = $payload['automations'] ?? null;
        if (!is_array($items)) {
            throw new \InvalidArgumentException('Payload missing "automations" array.');
        }

        if (count($items) > self::MAX_BUNDLE_ITEMS) {
            throw new \InvalidArgumentException(sprintf('Bundle contains too many automations (max %d, received %d).', self::MAX_BUNDLE_ITEMS, count($items)));
        }

        $checksum = $payload['checksum'] ?? null;
        if (is_string($checksum) && str_starts_with($checksum, 'sha256:')) {
            $expectedHash = substr($checksum, 7);
            $canonicalJson = json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $actualHash = hash('sha256', $canonicalJson);
            if (!hash_equals($expectedHash, $actualHash)) {
                throw new \InvalidArgumentException('Checksum mismatch: payload bundle has been altered or corrupted.');
            }
        }

        $imported = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $singlePayload = [
                'schema_version' => self::SCHEMA_VERSION,
                'automation'     => $item,
            ];
            $imported[] = $this->importAutomation($project, $singlePayload);
        }

        return $imported;
    }
}
