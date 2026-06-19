<?php

namespace App\Service;

/**
 * Publie des événements temps réel.
 *
 * Écrit dans des fichiers JSONL (var/realtime/{projectUuid}.jsonl).
 * Le RealtimeController les lit et les stream aux clients SSE.
 *
 * Fonctionne sur tout serveur (Apache/CGI, Nginx, etc.) sans dépendance externe.
 *
 * Topics par convention :
 *   project/{uuid}/content        — modifications de contenu
 *   project/{uuid}/media          — uploads, suppressions média
 *   project/{uuid}/status         — changements de statut workflow
 */
class MercurePublisher
{
    public function __construct(
        private readonly string $projectDir,
    ) {}

    /**
     * Publie un événement. L'écrit dans le fichier JSONL du projet.
     */
    private function publish(string $projectUuid, array $data): void
    {
        $dir = $this->projectDir . '/var/realtime';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $path = $dir . '/' . $projectUuid . '.jsonl';
        $line = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
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
