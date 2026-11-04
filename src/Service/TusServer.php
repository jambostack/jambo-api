<?php

namespace App\Service;

use App\Exception\TusChunkOutOfBoundsException;
use App\Exception\TusConflictException;
use App\Exception\TusOffsetMismatchException;
use Symfony\Component\Uid\Uuid;

/**
 * Serveur TUS minimal — implémente le protocole resumable upload directement.
 *
 * Stocke les chunks et métadonnées dans var/tus/.
 * Gère le verrouillage exclusif (flock), les limites strictes de chunks,
 * et le calcul atomique des quotas pour les uploads en cours.
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
            'finalizing'     => false,
        ];

        file_put_contents($this->infoPath($projectUuid, $uploadId), json_encode($info, JSON_UNESCAPED_SLASHES), LOCK_EX);
        touch($this->filePath($projectUuid, $uploadId));

        return $uploadId;
    }

    /**
     * Exécute une opération avec verrouillage exclusif sur un upload.
     *
     * @template T
     * @param callable(): T $callback
     * @return T|null Retourne null si non-bloquant et verrou indisponible
     */
    public function withLock(string $projectUuid, string $uploadId, callable $callback, bool $blocking = true): mixed
    {
        $dir = $this->uploadDir($projectUuid);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $lockPath = $this->lockPath($projectUuid, $uploadId);
        $fp = fopen($lockPath, 'c+');
        if ($fp === false) {
            throw new \RuntimeException('Cannot open lock file for upload ' . $uploadId);
        }

        $flags = LOCK_EX;
        if (!$blocking) {
            $flags |= LOCK_NB;
        }

        if (!flock($fp, $flags)) {
            fclose($fp);
            return null;
        }

        try {
            return $callback();
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Écrit un chunk à l'offset donné de manière thread-safe et vérifie les limites de chunk.
     *
     * @param resource $stream Flux binaire du chunk
     */
    public function patch(string $projectUuid, string $uploadId, int $offset, $stream): int
    {
        return $this->withLock($projectUuid, $uploadId, function () use ($projectUuid, $uploadId, $offset, $stream): int {
            $info = $this->getInfo($projectUuid, $uploadId);
            if ($info === null) {
                throw new \RuntimeException('Upload not found');
            }

            if ($info['finalized'] ?? false) {
                throw new TusConflictException('Upload already finalized');
            }

            if ($info['finalizing'] ?? false) {
                throw new TusConflictException('Upload is currently being finalized');
            }

            $currentOffset = (int) ($info['offset'] ?? 0);
            $size = (int) ($info['size'] ?? 0);

            if ($offset !== $currentOffset) {
                throw new TusOffsetMismatchException($currentOffset, $offset);
            }

            $remaining = $size - $offset;
            if ($remaining <= 0) {
                throw new TusConflictException('Upload already complete');
            }

            $targetPath = $this->filePath($projectUuid, $uploadId);
            $fp = fopen($targetPath, 'cb+');
            if ($fp === false) {
                throw new \RuntimeException('Cannot open upload file');
            }

            fseek($fp, $offset);

            // Copier au maximum remaining + 1 octets pour détecter tout dépassement de chunk
            $bytesCopied = stream_copy_to_stream($stream, $fp, $remaining + 1);
            if ($bytesCopied === false) {
                fclose($fp);
                throw new \RuntimeException('Failed to stream data to upload file');
            }

            if ($bytesCopied > $remaining) {
                ftruncate($fp, $offset);
                fclose($fp);
                throw new TusChunkOutOfBoundsException('Chunk exceeds declared Upload-Length');
            }

            // Vérifier s'il reste des octets dans le stream non consommé
            if (is_resource($stream) && !feof($stream)) {
                $extra = fread($stream, 1);
                if ($extra !== false && strlen($extra) > 0) {
                    ftruncate($fp, $offset);
                    fclose($fp);
                    throw new TusChunkOutOfBoundsException('Chunk exceeds declared Upload-Length');
                }
            }

            fflush($fp);
            fclose($fp);

            clearstatcache(true, $targetPath);
            $newOffset = $offset + $bytesCopied;
            $info['offset'] = $newOffset;
            $info['updated_at'] = time();

            file_put_contents($this->infoPath($projectUuid, $uploadId), json_encode($info, JSON_UNESCAPED_SLASHES), LOCK_EX);

            return $newOffset;
        });
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
        $this->withLock($projectUuid, $uploadId, function () use ($projectUuid, $uploadId) {
            @unlink($this->filePath($projectUuid, $uploadId));
            @unlink($this->infoPath($projectUuid, $uploadId));
        });
        @unlink($this->lockPath($projectUuid, $uploadId));
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
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    /**
     * L'upload est-il terminé (offset >= uploadLength) ?
     */
    public function isComplete(string $projectUuid, string $uploadId): bool
    {
        $info = $this->getInfo($projectUuid, $uploadId);
        if ($info === null) {
            return false;
        }
        $size = (int) ($info['size'] ?? 0);
        return $size > 0 && ((int) ($info['offset'] ?? 0) >= $size);
    }

    /**
     * Marque l'upload comme en cours de finalisation (empêche double finalize & concurrent patch).
     */
    public function markFinalizing(string $projectUuid, string $uploadId): void
    {
        $this->withLock($projectUuid, $uploadId, function () use ($projectUuid, $uploadId): void {
            $info = $this->getInfo($projectUuid, $uploadId);
            if ($info === null) {
                throw new \RuntimeException('Upload not found');
            }
            if ($info['finalized'] ?? false) {
                throw new TusConflictException('Upload already finalized');
            }
            if ($info['finalizing'] ?? false) {
                throw new TusConflictException('Upload is already being finalized');
            }
            $info['finalizing'] = true;
            file_put_contents($this->infoPath($projectUuid, $uploadId), json_encode($info, JSON_UNESCAPED_SLASHES), LOCK_EX);
        });
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
        $this->withLock($projectUuid, $uploadId, function () use ($projectUuid, $uploadId): void {
            $info = $this->getInfo($projectUuid, $uploadId);
            if ($info !== null) {
                $info['finalized'] = true;
                $info['finalizing'] = false;
                file_put_contents($this->infoPath($projectUuid, $uploadId), json_encode($info, JSON_UNESCAPED_SLASHES), LOCK_EX);
            }
        });
    }

    /**
     * Nettoie les fichiers temporaires après finalisation.
     */
    public function cleanup(string $projectUuid, string $uploadId): void
    {
        @unlink($this->infoPath($projectUuid, $uploadId));
        @unlink($this->lockPath($projectUuid, $uploadId));
    }

    /**
     * Calcule le volume total (en octets) des uploads en cours non finalisés pour un projet.
     */
    public function getPendingUploadsSize(string $projectUuid, ?string $excludeUploadId = null): int
    {
        $dir = $this->uploadDir($projectUuid);
        if (!is_dir($dir)) {
            return 0;
        }

        $total = 0;
        $maxAge = time() - 86400;
        $files = scandir($dir);
        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            if (!str_ends_with($file, '.info')) {
                continue;
            }
            $uploadId = basename($file, '.info');
            if ($uploadId === $excludeUploadId) {
                continue;
            }

            $infoPath = $dir . '/' . $file;
            if (@filemtime($infoPath) < $maxAge) {
                continue;
            }

            $content = @file_get_contents($infoPath);
            if ($content === false || $content === '') {
                continue;
            }

            $data = json_decode($content, true);
            if (is_array($data) && !($data['finalized'] ?? false)) {
                $total += (int) ($data['size'] ?? 0);
            }
        }

        return $total;
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

    private function assertValidUploadId(string $uploadId): void
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uploadId)) {
            throw new \InvalidArgumentException('Invalid upload ID format');
        }
    }

    private function infoPath(string $projectUuid, string $uploadId): string
    {
        $this->assertValidUploadId($uploadId);
        return $this->uploadDir($projectUuid) . '/' . $uploadId . '.info';
    }

    private function filePath(string $projectUuid, string $uploadId): string
    {
        $this->assertValidUploadId($uploadId);
        return $this->uploadDir($projectUuid) . '/' . $uploadId;
    }

    private function lockPath(string $projectUuid, string $uploadId): string
    {
        $this->assertValidUploadId($uploadId);
        return $this->uploadDir($projectUuid) . '/' . $uploadId . '.lock';
    }

    // ─── Nettoyage ──────────────────────────────────────────────────────

    /**
     * Supprime les uploads abandonnés depuis plus de 24h avec lock non bloquant.
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
                $mtime = @filemtime($infoPath);
                if ($mtime !== false && $mtime < $maxAge) {
                    $uploadId = basename($file, '.info');
                    // Lock non-bloquant pour ne pas supprimer un upload en cours
                    $cleaned = $this->withLock($project, $uploadId, function () use ($project, $uploadId): bool {
                        @unlink($this->filePath($project, $uploadId));
                        @unlink($this->infoPath($project, $uploadId));
                        return true;
                    }, false);

                    if ($cleaned === true) {
                        @unlink($this->lockPath($project, $uploadId));
                        $count++;
                    }
                }
            }
        }
        return $count;
    }
}

