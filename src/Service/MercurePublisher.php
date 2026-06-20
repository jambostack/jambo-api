<?php

namespace App\Service;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Publie des événements temps réel.
 *
 * Canal primaire : hub Mercure (SSE) si configuré.
 * Canal fallback : fichiers JSONL (var/realtime/{projectUuid}.jsonl) pour tout serveur sans hub.
 *
 * Topics par convention :
 *   projects/{uuid}               — tous les événements du projet
 *   projects/{uuid}/content       — modifications de contenu
 *   projects/{uuid}/media         — uploads, suppressions média
 *   projects/{uuid}/status        — changements de statut workflow
 */
class MercurePublisher
{
    public function __construct(
        private readonly string $projectDir,
        private ?HubInterface $hub = null,
    ) {}

    /**
     * Publie un événement sur le hub Mercure (primaire) et dans le fichier JSONL (fallback).
     */
    private function publish(string $projectUuid, array $data): void
    {
        // Canal primaire : hub Mercure SSE
        if ($this->hub !== null) {
            try {
                $topic = $this->resolveTopic($projectUuid, $data['event'] ?? 'unknown');
                $payload = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $update = new Update(
                    topics: [$topic, "projects/{$projectUuid}"],
                    payload: $payload,
                    private: true,
                );
                $this->hub->publish($update);
            } catch (\Throwable $e) {
                // Hub inaccessible — on continue en fallback JSONL silencieusement
            }
        }

        // Canal fallback : fichier JSONL (compatible tout serveur)
        $dir = $this->projectDir . '/var/realtime';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $path = $dir . '/' . $projectUuid . '.jsonl';
        $line = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

        file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Dérive le topic Mercure à partir du préfixe d'événement.
     */
    private function resolveTopic(string $projectUuid, string $event): string
    {
        return match (true) {
            str_starts_with($event, 'entry.')  => "projects/{$projectUuid}/content",
            str_starts_with($event, 'media.')  => "projects/{$projectUuid}/media",
            str_starts_with($event, 'status.') => "projects/{$projectUuid}/status",
            default                             => "projects/{$projectUuid}",
        };
    }

    // ─── Helpers métier ──────────────────────────────────────────────────

    /** Un contenu a été créé, mis à jour ou supprimé */
    public function contentChanged(string $projectUuid, string $action, array $entry, ?string $title = null): void
    {
        $this->publish($projectUuid, [
            'event' => "entry.{$action}",
            'data'  => [
                'action' => $action,
                'entry'  => $entry,
                'title'  => $title ?? ($entry['name'] ?? 'Sans titre'),
                'time'   => time(),
            ],
        ]);
    }

    /** Un upload média est terminé */
    public function mediaUploaded(string $projectUuid, array $media): void
    {
        $this->publish($projectUuid, [
            'event' => 'media.uploaded',
            'data'  => [
                'media' => $media,
                'title' => $media['original_filename'] ?? 'Fichier',
                'time'  => time(),
            ],
        ]);
    }

    /** Un média a été supprimé */
    public function mediaDeleted(string $projectUuid, string $filename): void
    {
        $this->publish($projectUuid, [
            'event' => 'media.deleted',
            'data'  => [
                'filename' => $filename,
                'title'    => $filename,
                'time'     => time(),
            ],
        ]);
    }

    /** Un statut workflow a changé */
    public function statusChanged(string $projectUuid, string $entryTitle, string $fromStatus, string $toStatus): void
    {
        $this->publish($projectUuid, [
            'event' => 'status.changed',
            'data'  => [
                'entry' => $entryTitle,
                'from'  => $fromStatus,
                'to'    => $toStatus,
                'title' => "{$entryTitle} → {$toStatus}",
                'time'  => time(),
            ],
        ]);
    }
}
