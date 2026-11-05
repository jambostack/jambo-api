<?php

namespace App\MessageHandler;

use App\Message\ExecuteAutomationMessage;
use App\Repository\AutomationRepository;
use App\Repository\AutomationRunRepository;
use App\Service\Automation\AutomationEngine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Handler asynchrone pour ExecuteAutomationMessage.
 *
 * Délègue à AutomationEngine::execute() qui gère
 * l'exécution du flowGraph et la persistance du Run.
 * Comporte une protection anti-réexécution idempotente et vérification d'expiration TTL.
 */
#[AsMessageHandler]
class ExecuteAutomationMessageHandler
{
    public function __construct(
        private readonly AutomationRepository $automationRepo,
        private readonly AutomationRunRepository $runRepo,
        private readonly AutomationEngine $engine,
        private readonly EntityManagerInterface $em,
        private readonly ?TranslatorInterface $translator = null,
    ) {}

    public function __invoke(ExecuteAutomationMessage $message): void
    {
        $run = $this->runRepo->find($message->runId);
        if ($run === null) {
            return;
        }

        // Idempotence & état : ne pas réexécuter si annulé, terminé avec succès ou en échec
        if ($run->status === 'cancelled' || $run->status === 'success' || $run->status === 'failed') {
            return;
        }

        // Vérification de délai d'expiration (TTL)
        if ($message->dispatchedAt > 0 && (time() - $message->dispatchedAt) > $message->ttlSeconds) {
            $run->status = 'failed';
            $run->errorMessage = $this->trans('automation.error.message_expired', 'Automation message expired in queue.');
            $run->finishedAt = new \DateTimeImmutable();
            $this->em->flush();
            return;
        }

        $automation = $this->automationRepo->find($message->automationId);
        if ($automation === null || !$automation->isActive) {
            $run->status = 'failed';
            $run->errorMessage = $this->trans('automation.error.inactive_or_not_found', 'Automation not found or inactive');
            $run->finishedAt = new \DateTimeImmutable();
            $this->em->flush();
            return;
        }

        // Vérification stricte d'isolation multi-tenant :
        if ($run->automation?->id !== $automation->id) {
            $run->status = 'failed';
            $run->errorMessage = $this->trans('automation.error.run_mismatch', 'Automation run does not belong to the target automation.');
            $run->finishedAt = new \DateTimeImmutable();
            $this->em->flush();
            return;
        }

        $project = $automation->project;
        if ($project === null) {
            $run->status = 'failed';
            $run->errorMessage = $this->trans('automation.error.missing_project', 'Automation has no associated project.');
            $run->finishedAt = new \DateTimeImmutable();
            $this->em->flush();
            return;
        }

        if ($message->projectUuid !== '' && $project->uuid?->toRfc4122() !== $message->projectUuid) {
            $run->status = 'failed';
            $run->errorMessage = $this->trans('automation.error.tenant_mismatch', 'Cross-tenant execution forbidden: message project UUID mismatch.');
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

    private function trans(string $id, string $default, array $parameters = []): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters, 'messages');
        }
        return $default;
    }
}

