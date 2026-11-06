<?php

namespace App\Service;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Publie des événements temps réel.
 *
 * Canal primaire : hub Mercure (SSE) si configuré.
 * Canal fallback : fichiers JSONL (var/realtime/{projectUuid}.jsonl)
 *   — utilisé UNIQUEMENT quand le hub est null ou en erreur.
 *
 * Topics par convention :
 *   projects/{uuid}               — tous les événements du projet
 *   projects/{uuid}/content       — modifications de contenu
 *   projects/{uuid}/media         — uploads, suppressions média
 *   projects/{uuid}/status        — changements de statut workflow
 */
class MercurePublisher
{
    public const MAX_REALTIME_FILE_BYTES = 5242880; // 5 MB

    public function __construct(
        private readonly string $projectDir,
        private ?HubInterface $hub = null,
        private ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters, 'messages');
        }
        return $default ?? $id;
    }

    /**
     * Publie un événement sur le hub Mercure (primaire).
     * Si le hub est null ou en erreur, écrit dans le fichier JSONL (fallback).
     *
     * @param string[] $extraTopics
     */
    private function publish(string $projectUuid, array $data, array $extraTopics = []): void
    {
        $defaultTopic = $this->resolveTopic($projectUuid, $data['event'] ?? 'unknown');
        if (!isset($data['topic'])) {
            $data['topic'] = $defaultTopic;
        }

        $payload = json_encode($data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $publishedToHub = false;

        // 1. Canal primaire : hub Mercure SSE
        if ($this->hub !== null) {
            try {
                $allTopics = array_values(array_unique(array_merge([$defaultTopic, "projects/{$projectUuid}"], $extraTopics)));
                $update = new Update(
                    topics: $allTopics,
                    data: $payload,
                    private: true,
                );
                $this->hub->publish($update);
                $publishedToHub = true;
            } catch (\Throwable) {
                // Hub inaccessible — on continue en fallback JSONL
            }
        }

        // 2. Fallback JSONL UNIQUEMENT si hub non dispo ou en erreur
        if (!$publishedToHub) {
            $this->appendToJsonl($projectUuid, $payload);
        }
    }

    /**
     * Écrit une ligne JSON dans le fichier de fallback.
     */
    private function appendToJsonl(string $projectUuid, string $payload): void
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $projectUuid)) {
            return;
        }

        $dir = $this->projectDir . '/var/realtime';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $path = $dir . '/' . $projectUuid . '.jsonl';

        // Rotate or truncate if file exceeds maximum size limit
        if (file_exists($path) && (filesize($path) ?: 0) > self::MAX_REALTIME_FILE_BYTES) {
            $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines) && count($lines) > 500) {
                $retained = array_slice($lines, -250);
                file_put_contents($path, implode("\n", $retained) . "\n", LOCK_EX);
            } else {
                @unlink($path);
            }
        }

        file_put_contents($path, $payload . "\n", FILE_APPEND | LOCK_EX);
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
    public function contentChanged(string $projectUuid, string $action, array $entry, ?string $title = null, ?string $collectionSlug = null): void
    {
        $defaultTitle = $this->trans('realtime.untitled', [], 'Untitled');
        $resolvedTitle = $title ?? ($entry['name'] ?? $entry['title'] ?? $defaultTitle);
        $slug = $collectionSlug ?? ($entry['collection'] ?? null);

        $extraTopics = [];
        if (is_string($slug) && $slug !== '') {
            $extraTopics[] = "projects/{$projectUuid}/collections/{$slug}";
            $extraTopics[] = "projects/{$projectUuid}/content/{$slug}";
        }

        $this->publish($projectUuid, [
            'event' => "entry.{$action}",
            'topic' => "projects/{$projectUuid}/content",
            'data'  => [
                'action'     => $action,
                'entry'      => $entry,
                'title'      => $resolvedTitle,
                'collection' => $slug,
                'time'       => time(),
            ],
        ], $extraTopics);
    }

    /** Un upload média est terminé */
    public function mediaUploaded(string $projectUuid, array $media): void
    {
        $defaultTitle = $this->trans('realtime.file', [], 'File');
        $this->publish($projectUuid, [
            'event' => 'media.uploaded',
            'topic' => "projects/{$projectUuid}/media",
            'data'  => [
                'media' => $media,
                'title' => $media['original_filename'] ?? $defaultTitle,
                'time'  => time(),
            ],
        ]);
    }

    /** Un média a été supprimé */
    public function mediaDeleted(string $projectUuid, string $filename): void
    {
        $this->publish($projectUuid, [
            'event' => 'media.deleted',
            'topic' => "projects/{$projectUuid}/media",
            'data'  => [
                'filename' => $filename,
                'title'    => $filename,
                'time'     => time(),
            ],
        ]);
    }

    /** Un statut workflow a changé */
    public function statusChanged(string $projectUuid, string $entryTitle, string $fromStatus, string $toStatus, ?string $collectionSlug = null): void
    {
        $extraTopics = [];
        if (is_string($collectionSlug) && $collectionSlug !== '') {
            $extraTopics[] = "projects/{$projectUuid}/collections/{$collectionSlug}";
        }

        $this->publish($projectUuid, [
            'event' => 'status.changed',
            'topic' => "projects/{$projectUuid}/status",
            'data'  => [
                'entry'      => $entryTitle,
                'from'       => $fromStatus,
                'to'         => $toStatus,
                'collection' => $collectionSlug,
                'title'      => "{$entryTitle} → {$toStatus}",
                'time'       => time(),
            ],
        ], $extraTopics);
    }
}
