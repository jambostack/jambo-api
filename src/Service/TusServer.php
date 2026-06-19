<?php

namespace App\Service;

use Symfony\Component\Uid\Uuid;

/**
 * Serveur TUS minimal — implémente le protocole resumable upload directement.
 *
 * Stocke les chunks et métadonnées dans var/tus/.
 * À la fin de l'upload (offset >= uploadLength), finalise en créant l'entité
 * Media et en appelant StorageManager pour la sync multi-profil.
 */
class TusServer
{
    /**
     * @param string $projectDir Racine du projet Symfony (chemin absolu)
     */
    public function __construct(private readonly string $projectDir) {}

    // ─── API publique (protocole TUS) ─────────────────────────────────────

    /**
     * Crée un nouvel upload. Retourne l'UUID uploadId.
     *
     * @param array $metadata Clés décodées du header Upload-Metadata
     *                        (ex: ['filename' => 'photo.jpg', 'filetype' => 'image/jpeg', 'folder_id' => '3'])
     */
    public function create(string $projectUuid, int $uploadLength, array $metadata): string
    {
        $uploadId = Uuid::v4()->toRfc4122();
        $dir = $this->uploadDir($projectUuid);

        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $info = [
            'id'             => $uploadId,
            'project_uuid'   => $projectUuid,
            'size'           => $uploadLength,
            'offset'         => 0,
            'metadata'       => $metadata,
            'created_at'     => time(),
            'finalized'      => false,
        ];

        file_put_contents($this->infoPath($projectUuid, $uploadId), json_encode($info, JSON_UNESCAPED_SLASHES));
        // Crée le fichier destination vide
        touch($this->filePath($projectUuid, $uploadId));

        return $uploadId;
    }

    /**
     * Écrit un chunk à l'offset donné. Retourne le nouvel offset.
     *
     * @param resource $stream Flux binaire du chunk
     */
    public function patch(string $projectUuid, string $uploadId, int $offset, $stream): int
    {
        $info = $this->getInfo($projectUuid, $uploadId);
        if ($info === null) {
            throw new \RuntimeException('Upload not found');
        }

        $targetPath = $this->filePath($projectUuid, $uploadId);
        $fp = fopen($targetPath, 'cb+');
        if ($fp === false) {
            throw new \RuntimeException('Cannot open upload file');
        }

        fseek($fp, $offset);
        stream_copy_to_stream($stream, $fp);
        fclose($fp);

        clearstatcache(true, $targetPath);
        $newOffset = filesize($targetPath);
        $info['offset'] = $newOffset;
        file_put_contents($this->infoPath($projectUuid, $uploadId), json_encode($info, JSON_UNESCAPED_SLASHES));

        return $newOffset;
    }

    /**
     * Retourne l'offset actuel (pour HEAD).
     */
    public function getOffset(string $projectUuid, string $uploadId): ?int
    {
        $info = $this->getInfo($projectUuid, $uploadId);
        return $info['offset'] ?? null;
    }

    /**
     * Vérifie si l'upload existe.
     */
    public function exists(string $projectUuid, string $uploadId): bool
    {
        return file_exists($this->infoPath($projectUuid, $uploadId));
    }

    /**
     * Supprime un upload (annulation côté client).
     */
    public function cancel(string $projectUuid, string $uploadId): void
    {
        @unlink($this->filePath($projectUuid, $uploadId));
        @unlink($this->infoPath($projectUuid, $uploadId));
    }

    /**
     * Retourne les infos complètes de l'upload.
     */
    public function getInfo(string $projectUuid, string $uploadId): ?array
    {
        $path = $this->infoPath($projectUuid, $uploadId);
        if (!file_exists($path)) {
            return null;
        }
        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    /**
     * L'upload est-il terminé (offset >= uploadLength) ?
     */
    public function isComplete(string $projectUuid, string $uploadId): bool
    {
        $info = $this->getInfo($projectUuid, $uploadId);
        if ($info === null) return false;
        return ($info['offset'] ?? 0) >= ($info['size'] ?? 0);
    }

    /**
     * Retourne le chemin du fichier assemblé (pour finalisation).
     */
    public function getFilePath(string $projectUuid, string $uploadId): string
    {
        return $this->filePath($projectUuid, $uploadId);
    }

    /**
     * Marque l'upload comme finalisé (plus de PATCH accepté).
     */
    public function markFinalized(string $projectUuid, string $uploadId): void
    {
        $info = $this->getInfo($projectUuid, $uploadId);
        if ($info !== null) {
            $info['finalized'] = true;
            file_put_contents($this->infoPath($projectUuid, $uploadId), json_encode($info, JSON_UNESCAPED_SLASHES));
        }
    }

    /**
     * Nettoie les fichiers temporaires après finalisation.
     */
    public function cleanup(string $projectUuid, string $uploadId): void
    {
        @unlink($this->infoPath($projectUuid, $uploadId));
    }

    // ─── Private ─────────────────────────────────────────────────────────

    private function uploadDir(string $projectUuid): string
    {
        // Protection path traversal : n'accepte que les UUID valides
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $projectUuid)) {
            throw new \InvalidArgumentException('Invalid project UUID');
        }
        return $this->projectDir . '/var/tus/' . $projectUuid;
    }

    private function infoPath(string $projectUuid, string $uploadId): string
    {
        return $this->uploadDir($projectUuid) . '/' . $uploadId . '.info';
    }

    private function filePath(string $projectUuid, string $uploadId): string
    {
        return $this->uploadDir($projectUuid) . '/' . $uploadId;
    }

    // ─── Nettoyage ──────────────────────────────────────────────────────

    /**
     * Supprime les uploads abandonnés depuis plus de 24h.
     * @return int Nombre d'uploads nettoyés
     */
    public function garbageCollect(): int
    {
        $count = 0;
        $base = $this->projectDir . '/var/tus/';
        if (!is_dir($base)) return 0;

        $maxAge = time() - 86400; // 24h
        $projects = scandir($base);
        foreach ($projects as $project) {
            if ($project === '.' || $project === '..') continue;
            $projectDir = $base . $project;
            if (!is_dir($projectDir)) continue;
            $files = scandir($projectDir);
            foreach ($files as $file) {
                if (!str_ends_with($file, '.info')) continue;
                $infoPath = $projectDir . '/' . $file;
                $mtime = filemtime($infoPath);
                if ($mtime < $maxAge) {
                    $uploadId = basename($file, '.info');
                    $this->cancel($project, $uploadId);
                    $count++;
                }
            }
        }
        return $count;
    }
}
