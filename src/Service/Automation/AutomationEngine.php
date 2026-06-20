<?php

namespace App\Service\Automation;

use App\Entity\Automation;
use App\Entity\AutomationRun;
use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowInterpreter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Moteur d'exécution des automatisations.
 *
 * Point d'entrée unique pour exécuter un flowGraph, que ce soit
 * depuis un événement Doctrine (Subscriber), un webhook (Controller),
 * une commande cron, ou un message Messenger asynchrone (Handler).
 *
 * Gère la persistance des AutomationRun.
 */
class AutomationEngine
{
    public function __construct(
        private readonly FlowInterpreter $interpreter,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Exécute une automatisation avec un payload de déclenchement.
     *
     * @param AutomationRun|null $existingRun Si fourni, ce Run est réutilisé
     *        (cas de l'exécution asynchrone via Messenger). Sinon, un nouveau Run est créé.
     */
    public function execute(Automation $automation, array $triggerPayload, ?AutomationRun $existingRun = null): AutomationRun
    {
        $graph = $automation->flowGraph;
        if (!$graph || empty($graph['nodes'])) {
            throw new \RuntimeException('Automation has no flow graph');
        }

        $startTime = microtime(true);

        $run = $existingRun ?? new AutomationRun();
        if ($existingRun === null) {
            $run->automation = $automation;
            $run->status = 'running';
        }

        if ($automation->debugMode) {
            $run->triggerPayload = $triggerPayload;
        }

        if ($existingRun === null) {
            $this->em->persist($run);
        }
        $this->em->flush();

        $ctx = new FlowContext(
            automationId: $automation->id,
            projectUuid: $automation->project?->uuid?->toRfc4122() ?? '',
            debugMode: $automation->debugMode,
        );

        try {
            $result = $this->interpreter->executeFlow($graph, $triggerPayload, $ctx);

            $run->status = $result->status === 'failed' ? 'failed' : 'success';
            $run->finishedAt = new \DateTimeImmutable();
            $run->durationMs = $result->totalDurationMs;

            if ($automation->debugMode) {
                $run->actionOutput = [
                    'stepLog' => $result->stepLog,
                    'variables' => $ctx->variables,
                ];
            }

            if ($result->error) {
                $run->errorMessage = $result->error;
            }
        } catch (\Throwable $e) {
            $run->status = 'failed';
            $run->finishedAt = new \DateTimeImmutable();
            $run->durationMs = (int) ((microtime(true) - $startTime) * 1000);
            $run->errorMessage = $e->getMessage();

            if ($automation->debugMode) {
                $run->actionOutput = ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()];
            }
        }

        $automation->lastRunAt = new \DateTimeImmutable();
        $this->em->flush();

        return $run;
    }
}
