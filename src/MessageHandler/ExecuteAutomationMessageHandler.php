<?php

namespace App\MessageHandler;

use App\Entity\Automation;
use App\Entity\AutomationRun;
use App\Entity\ContentEntry;
use App\Message\ExecuteAutomationMessage;
use App\Repository\AutomationRepository;
use App\Repository\AutomationRunRepository;
use App\Repository\CollectionRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\ProjectRepository;
use App\Service\EavFieldHelperService;
use App\Service\ProjectMailerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ExecuteAutomationMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AutomationRepository $automationRepo,
        private readonly AutomationRunRepository $runRepo,
        private readonly ProjectRepository $projectRepo,
        private readonly CollectionRepository $collectionRepo,
        private readonly ContentEntryRepository $entryRepo,
        private readonly ProjectMailerService $mailerService,
        private readonly EavFieldHelperService $eavHelper,
    ) {}

    public function __invoke(ExecuteAutomationMessage $message): void
    {
        $run = $this->runRepo->find($message->runId);
        if ($run === null) return;

        $startTime = microtime(true);

        try {
            match ($message->actionType) {
                'send_email'       => $this->executeSendEmail($message),
                'call_webhook'     => $this->executeCallWebhook($message),
                'create_entry'     => $this->executeCreateEntry($message),
                'update_entry'     => $this->executeUpdateEntry($message),
                'send_notification' => $this->executeSendNotification($message),
                default            => throw new \RuntimeException("Unknown action type: {$message->actionType}"),
            };

            $run->status = 'success';
        } catch (\Throwable $e) {
            $run->status = 'failed';
            $run->errorMessage = $e->getMessage();
        }

        $run->finishedAt = new \DateTimeImmutable();
        $run->durationMs = (int) ((microtime(true) - $startTime) * 1000);

        if ($message->debugMode) {
            $run->actionOutput = ['status' => $run->status];
        }

        $this->em->flush();
    }

    // ─── Actions ────────────────────────────────────────────────────────

    private function executeSendEmail(ExecuteAutomationMessage $msg): void
    {
        $config = $msg->actionInput;
        $project = $this->projectRepo->findOneBy(['uuid' => $msg->projectUuid]);

        $this->mailerService->send(
            project: $project,
            to: $config['to'] ?? '',
            subject: $config['subject'] ?? 'Automatisation Jambo',
            body: $config['body'] ?? '',
            htmlBody: $config['body'] ?? '',
        );
    }

    private function executeCallWebhook(ExecuteAutomationMessage $msg): void
    {
        $config = $msg->actionInput;
        $url    = $config['url'] ?? '';
        $method = strtoupper($config['method'] ?? 'POST');
        $headers = $config['headers'] ?? [];
        $body    = $config['body'] ?? '';

        $client = HttpClient::create(['timeout' => 10]);
        $client->request($method, $url, [
            'headers' => $headers,
            'body'    => $body,
        ]);
    }

    private function executeCreateEntry(ExecuteAutomationMessage $msg): void
    {
        $config = $msg->actionInput;
        $collectionSlug = $config['collection_slug'] ?? '';
        $project = $this->projectRepo->findOneBy(['uuid' => $msg->projectUuid]);
        $collection = $this->collectionRepo->findOneBy(['slug' => $collectionSlug, 'project' => $project]);

        if (!$collection) {
            throw new \RuntimeException("Collection '$collectionSlug' not found");
        }

        $entry = new ContentEntry();
        $entry->collection = $collection;
        $entry->project    = $project;
        $entry->locale     = $project->defaultLocale;
        $entry->status     = $collection->getDefaultStatus();

        $this->em->persist($entry);

        // Appliquer les valeurs des champs
        $fields = $config['fields'] ?? [];
        foreach ($fields as $slug => $value) {
            $field = $collection->getFieldBySlug($slug);
            if ($field) {
                $this->eavHelper->saveFieldValue($entry, $field, $value);
            }
        }

        $this->em->flush();
    }

    private function executeUpdateEntry(ExecuteAutomationMessage $msg): void
    {
        $config = $msg->actionInput;
        $entryUuid = $config['entry_uuid'] ?? '';

        $entry = $this->entryRepo->findOneBy(['uuid' => $entryUuid]);
        if (!$entry) {
            throw new \RuntimeException("Entry '$entryUuid' not found");
        }

        $fields = $config['fields'] ?? [];
        foreach ($fields as $slug => $value) {
            $field = $entry->collection?->getFieldBySlug($slug);
            if ($field) {
                $this->eavHelper->saveFieldValue($entry, $field, $value);
            }
        }

        if (!empty($config['status'])) {
            $entry->status = $config['status'];
        }

        $entry->updatedAt = new \DateTimeImmutable();
        $this->em->flush();
    }

    private function executeSendNotification(ExecuteAutomationMessage $msg): void
    {
        $config = $msg->actionInput;
        $title = $config['title'] ?? 'Automatisation';
        $body  = $config['body']  ?? '';

        // Crée une notification pour l'utilisateur qui a déclenché l'automatisation
        // (ou tous les admins du projet)
        $project = $this->projectRepo->findOneBy(['uuid' => $msg->projectUuid]);

        // Notification simple : on log juste, le système de notification
        // existant est déjà branché sur les événements de contenu.
        // Pour v1.13a, on va créer une notification basique.
        $notification = new \App\Entity\Notification();
        $notification->title = $title;
        $notification->body = $body;
        $notification->type = 'automation';

        $this->em->persist($notification);
        $this->em->flush();
    }
}
