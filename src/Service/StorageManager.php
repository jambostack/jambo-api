<?php

namespace App\Service;

use App\Entity\Project;
use App\Entity\ProjectStorageProfile;
use App\Repository\ProjectStorageProfileRepository;
use App\Repository\StorageRuleRepository;
use League\Flysystem\FilesystemOperator;

class StorageManager
{
    /** @var array<string, FilesystemOperator> */
    private array $filesystemCache = [];

    /** @var array<string, ProjectStorageProfile> */
    private array $profileCache = [];

    public function __construct(
        private readonly Project $project,
        private readonly ProjectStorageProfileRepository $profileRepo,
        private readonly StorageRuleRepository $ruleRepo,
        private readonly StorageDriverFactory $factory,
    ) {}

    // ─── Résolution ─────────────────────────────────────────────────────

    /**
     * Résout le(s) filesystem(s) selon la stratégie du projet.
     * @return array<string, FilesystemOperator>  [profile_uuid => Filesystem]
     */
    public function getFilesystems(array $options = []): array
    {
        $profiles = match ($this->project->storageStrategy) {
            'default_only' => $this->resolveDefaultOnly(),
            'mirror_all'   => $this->resolveMirrorAll(),
            'rules'        => $this->resolveRules($options),
            default        => $this->resolveDefaultOnly(),
        };

        $filesystems = [];
        foreach ($profiles as $profile) {
            $uuid = $profile->uuid->toRfc4122();
            $this->profileCache[$uuid] = $profile;
            $filesystems[$uuid] = $this->getOrCreateFilesystem($profile);
        }

        return $filesystems;
    }

    public function getFilesystem(string $profileUuid): FilesystemOperator
    {
        $profile = $this->profileRepo->findOneBy(['uuid' => $profileUuid, 'project' => $this->project]);
        if ($profile === null) {
            throw new \RuntimeException("Storage profile not found: $profileUuid");
        }
        return $this->getOrCreateFilesystem($profile);
    }

    // ─── Opérations CRUD ────────────────────────────────────────────────

    /** @return array<string, string> profile_uuid => path */
    public function write(string $path, mixed $stream, array $options = []): array
    {
        $filesystems = $this->getFilesystems($options);
        $paths = [];
        $rewindable = is_resource($stream);

        foreach ($filesystems as $uuid => $fs) {
            try {
                $fs->writeStream($path, $stream);
                $paths[$uuid] = $path;
            } catch (\Throwable $e) {
                // Logger l'erreur mais ne pas bloquer les autres storages
                trigger_error("StorageManager::write() failed on $uuid: " . $e->getMessage(), E_USER_WARNING);
            }

            // Rewind pour le prochain filesystem
            if ($rewindable) {
                @rewind($stream);
                if (ftell($stream) !== 0) {
                    @fseek($stream, 0);
                }
            }
        }

        return $paths;
    }

    public function delete(?array $storagePaths): void
    {
        if ($storagePaths === null) {
            return;
        }
        foreach ($storagePaths as $uuid => $path) {
            try {
                $fs = $this->getFilesystem($uuid);
                if ($fs->fileExists($path)) {
                    $fs->delete($path);
                }
            } catch (\Throwable) {
                // continue même si un storage est injoignable
            }
        }
    }

    public function read(?array $storagePaths): mixed
    {
        if ($storagePaths === null || $storagePaths === []) {
            throw new \RuntimeException('No storage paths available.');
        }
        foreach ($storagePaths as $uuid => $path) {
            try {
                $fs = $this->getFilesystem($uuid);
                return $fs->readStream($path);
            } catch (\Throwable) {
                continue;
            }
        }
        throw new \RuntimeException('File not found on any storage.');
    }

    public function getUrl(?array $storagePaths, ?string $profileUuid = null): ?string
    {
        if ($storagePaths === null || $storagePaths === []) {
            return null;
        }

        if ($profileUuid !== null && isset($storagePaths[$profileUuid])) {
            return $this->buildUrl($profileUuid, $storagePaths[$profileUuid]);
        }

        $uuid = array_key_first($storagePaths);
        return $this->buildUrl($uuid, $storagePaths[$uuid]);
    }

    // ─── Private ─────────────────────────────────────────────────────────

    /** @return ProjectStorageProfile[] */
    private function resolveDefaultOnly(): array
    {
        $default = $this->profileRepo->findDefault($this->project);
        return $default !== null ? [$default] : [];
    }

    /** @return ProjectStorageProfile[] */
    private function resolveMirrorAll(): array
    {
        return $this->profileRepo->findActive($this->project);
    }

    /** @return ProjectStorageProfile[] */
    private function resolveRules(array $options = []): array
    {
        $rules = $this->ruleRepo->findByProject($this->project);
        $mimeType = $options['mime_type'] ?? '';
        $filename = $options['filename'] ?? '';
        $size     = $options['size'] ?? 0;

        foreach ($rules as $rule) {
            if ($rule->matches($mimeType, $filename, $size)) {
                return [$rule->storageProfile];
            }
        }

        $default = $this->profileRepo->findDefault($this->project);
        return $default !== null ? [$default] : [];
    }

    private function getOrCreateFilesystem(ProjectStorageProfile $profile): FilesystemOperator
    {
        $uuid = $profile->uuid->toRfc4122();
        if (!isset($this->filesystemCache[$uuid])) {
            $this->filesystemCache[$uuid] = $this->factory->create($profile);
        }
        return $this->filesystemCache[$uuid];
    }

    private function buildUrl(string $profileUuid, string $path): string
    {
        $profile = $this->profileCache[$profileUuid]
            ?? $this->profileRepo->findOneBy(['uuid' => $profileUuid]);

        if ($profile === null) {
            return '/uploads/media/' . ltrim($path, '/');
        }

        if ($profile->driver === 'local') {
            return '/uploads/media/' . ltrim($path, '/');
        }

        if ($profile->baseUrl !== null && $profile->baseUrl !== '') {
            return rtrim($profile->baseUrl, '/') . '/' . ltrim($path, '/');
        }

        // S3 : virtual hosted-style (compatible toutes régions sauf us-east-1 path-style)
        if ($profile->s3Endpoint !== null && $profile->s3Endpoint !== '') {
            // S3-compatible (R2, MinIO, ...) — path-style
            return rtrim($profile->s3Endpoint, '/') . '/' . $profile->s3Bucket . '/' . $path;
        }

        // AWS natif : virtual hosted-style
        return "https://{$profile->s3Bucket}.s3.{$profile->s3Region}.amazonaws.com/" . $path;
    }
}
