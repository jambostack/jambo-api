<?php

namespace App\MessageHandler;

use App\Message\ExecuteAutomationMessage;
use App\Repository\AutomationRepository;
use App\Repository\AutomationRunRepository;
use App\Service\Automation\AutomationEngine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler asynchrone pour ExecuteAutomationMessage.
 *
 * Délègue à AutomationEngine::execute() qui gère
 * l'exécution du flowGraph et la persistance du Run.
 */
#[AsMessageHandler]
class ExecuteAutomationMessageHandler
{
    public function __construct(
        private readonly AutomationRepository $automationRepo,
        private readonly AutomationRunRepository $runRepo,
        private readonly AutomationEngine $engine,
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(ExecuteAutomationMessage $message): void
    {
        $run = $this->runRepo->find($message->runId);
        if ($run === null) return;

        $automation = $this->automationRepo->find($message->automationId);
        if ($automation === null || !$automation->isActive) {
            $run->status = 'failed';
            $run->errorMessage = 'Automation not found or inactive';
            $run->finishedAt = new \DateTimeImmutable();
            $this->em->flush();
            return;
        }

        try {
            // Passe le Run existant pour éviter la création d'un doublon
            $this->engine->execute($automation, $message->triggerPayload, $run);
            $run->finishedAt = new \DateTimeImmutable();
        } catch (\Throwable $e) {
            $run->status = 'failed';
            $run->errorMessage = $e->getMessage();
            $run->finishedAt = new \DateTimeImmutable();
        }

        $this->em->flush();
    }
}
