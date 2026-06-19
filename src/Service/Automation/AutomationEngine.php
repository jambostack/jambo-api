<?php

namespace App\Service\Automation;

use App\Entity\Automation;
use App\Entity\AutomationRun;
use App\Entity\ContentEntry;
use App\Entity\Project;
use App\Repository\AutomationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Moteur d'évaluation et d'exécution des automatisations.
 *
 * Responsabilités :
 *  - Évaluer les conditions d'une automatisation
 *  - Résoudre les templates dans les configs d'action
 *  - Dispatcher l'exécution asynchrone via Messenger
 */
class AutomationEngine
{
    public function __construct(
        private readonly AutomationRepository $automationRepo,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {}

    /**
     * Évalue les conditions d'une automatisation contre un payload.
     */
    public function evaluateConditions(?array $conditions, array $payload): bool
    {
        if ($conditions === null || $conditions === []) {
            return true; // pas de condition = toujours vrai
        }

        foreach ($conditions as $cond) {
            $field    = $cond['field']    ?? '';
            $operator = $cond['operator'] ?? 'eq';
            $value    = $cond['value']    ?? null;

            $actual = $this->getNestedValue($payload, $field);

            if (!$this->evaluateSingle($actual, $operator, $value)) {
                return false; // AND : une seule condition fausse = échec
            }
        }

        return true;
    }

    /**
     * Résout les templates {{ path.to.value }} dans une chaîne ou un tableau.
     */
    public function resolveTemplates(mixed $config, array $payload): mixed
    {
        if (is_string($config)) {
            return $this->resolveString($config, $payload);
        }

        if (is_array($config)) {
            $resolved = [];
            foreach ($config as $key => $value) {
                $resolved[$key] = $this->resolveTemplates($value, $payload);
            }
            return $resolved;
        }

        return $config;
    }

    /**
     * Dispatch une automatisation pour exécution asynchrone.
     */
    public function dispatchAsync(Automation $automation, array $triggerPayload): void
    {
        // Évalue d'abord les conditions (synchrone, rapide)
        $conditionResults = null;
        $conditionsPass = $this->evaluateConditions($automation->conditions, $triggerPayload);

        // Debug : résultats par condition
        if ($automation->debugMode && $automation->conditions !== null) {
            $conditionResults = [];
            foreach ($automation->conditions as $cond) {
                $cond['_actual'] = $this->getNestedValue($triggerPayload, $cond['field'] ?? '');
                $cond['_result'] = $this->evaluateSingle($cond['_actual'], $cond['operator'] ?? 'eq', $cond['value'] ?? null);
                $conditionResults[] = $cond;
            }
        }

        if (!$conditionsPass) {
            // Log l'échec des conditions (debug) mais n'exécute pas l'action
            return;
        }

        // Résout les templates
        $actionInput = $this->resolveTemplates($automation->actionConfig, $triggerPayload);

        // Crée le Run et dispatch sur Messenger
        $run = new AutomationRun();
        $run->automation = $automation;
        $run->status = 'running';

        if ($automation->debugMode) {
            $run->triggerPayload = $triggerPayload;
            $run->conditionResults = $conditionResults;
            $run->actionInput = $actionInput;
        }

        $this->em->persist($run);
        $this->em->flush();

        // Dispatch asynchrone
        $this->bus->dispatch(new ExecuteAutomationMessage(
            automationId: $automation->id,
            runId: $run->id,
            actionType: $automation->actionType,
            actionInput: $actionInput,
            projectUuid: $automation->project?->uuid?->toRfc4122() ?? '',
            debugMode: $automation->debugMode,
        ));

        // Met à jour lastRunAt
        $automation->lastRunAt = new \DateTimeImmutable();
        $this->em->flush();
    }

    /**
     * Dispatch les automatisations déclenchées par un événement contenu.
     */
    public function dispatchForContentEvent(string $eventName, Project $project, ContentEntry $entry, string $previousStatus = ''): void
    {
        // Construit le payload standard
        $payload = $this->buildPayload($eventName, $project, $entry, $previousStatus);

        // Récupère les automatisations actives pour ce trigger
        $automations = $this->automationRepo->findActiveByTrigger($project, $eventName);

        foreach ($automations as $automation) {
            // Filtre par collection si configuré
            $collectionSlugs = $automation->triggerConfig['collection_slugs'] ?? [];
            if (!empty($collectionSlugs)) {
                $entryCollectionSlug = $entry->collection?->slug ?? '';
                if (!in_array($entryCollectionSlug, $collectionSlugs, true)) {
                    continue;
                }
            }

            $this->dispatchAsync($automation, $payload);
        }
    }

    // ─── Private ─────────────────────────────────────────────────────────

    private function buildPayload(string $eventName, Project $project, ContentEntry $entry, string $previousStatus): array
    {
        return [
            'trigger'      => $eventName,
            'project_uuid' => $project->uuid?->toRfc4122(),
            'timestamp'    => time(),
            'entry'        => [
                'id'              => $entry->id,
                'uuid'            => $entry->uuid?->toRfc4122(),
                'title'           => $entry->name ?? 'Sans titre',
                'slug'            => $entry->slug,
                'status'          => $entry->status,
                'previous_status' => $previousStatus,
                'collection_slug' => $entry->collection?->slug ?? '',
                'collection_id'   => $entry->collection?->id,
                'created_at'      => $entry->createdAt?->format('c'),
                'updated_at'      => $entry->updatedAt?->format('c'),
            ],
        ];
    }

    public function evaluateSingle(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            'eq'       => $actual == $expected,
            'neq'      => $actual != $expected,
            'in'       => is_array($expected) && in_array($actual, $expected, true),
            'contains' => is_string($actual) && is_string($expected) && str_contains($actual, $expected),
            'gt'       => is_numeric($actual) && is_numeric($expected) && $actual > $expected,
            'gte'      => is_numeric($actual) && is_numeric($expected) && $actual >= $expected,
            'lt'       => is_numeric($actual) && is_numeric($expected) && $actual < $expected,
            'lte'      => is_numeric($actual) && is_numeric($expected) && $actual <= $expected,
            'empty'    => $actual === null || $actual === '' || $actual === [] || $actual === false,
            'notEmpty' => !($actual === null || $actual === '' || $actual === [] || $actual === false),
            default    => false,
        };
    }

    public function getNestedValue(array $data, string $path): mixed
    {
        $keys = explode('.', $path);
        $current = $data;
        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }
        return $current;
    }

    private function resolveString(string $template, array $payload): string
    {
        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function ($matches) use ($payload) {
            $value = $this->getNestedValue($payload, $matches[1]);
            return $value !== null ? (string) $value : '';
        }, $template);
    }
}
