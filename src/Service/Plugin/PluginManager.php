<?php

declare(strict_types=1);

namespace App\Service\Plugin;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

class PluginManager
{
    public const MAX_MANIFEST_BYTES = 65536; // 64 KB
    public const ALLOWED_PERMISSIONS = [
        'content:read',
        'content:write',
        'media:read',
        'media:write',
        'webhook:trigger',
        'settings:read',
        'settings:write',
        'users:read',
        'analytics:read',
    ];
    public const ALLOWED_HOOKS = [
        'content.before_create',
        'content.after_create',
        'content.before_update',
        'content.after_update',
        'content.before_delete',
        'content.after_delete',
        'media.before_upload',
        'media.after_upload',
        'user.before_login',
        'user.after_login',
    ];

    private string $pluginsDir;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
        private readonly ?TranslatorInterface $translator = null,
    ) {
        $this->pluginsDir = $projectDir . '/var/plugins';
        if (!is_dir($this->pluginsDir)) {
            @mkdir($this->pluginsDir, 0755, true);
        }
    }

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters, 'messages');
        }
        return $default ?? $id;
    }

    public function getPluginsDir(): string
    {
        return $this->pluginsDir;
    }

    /**
     * Validates a plugin manifest structure and bounds.
     *
     * @return array{valid: bool, manifest?: array, error?: string}
     */
    public function validateManifest(mixed $data): array
    {
        if (!is_array($data)) {
            return ['valid' => false, 'error' => $this->trans('plugin.invalid_json', [], 'Plugin manifest must be a valid JSON object.')];
        }

        $id = $data['id'] ?? null;
        if (!is_string($id) || !preg_match('/^[a-z0-9\-_]{2,64}$/', $id)) {
            return ['valid' => false, 'error' => $this->trans('plugin.invalid_id', [], 'Plugin ID must be 2-64 lowercase alphanumeric characters, dashes or underscores.')];
        }

        $name = $data['name'] ?? null;
        if (!is_string($name) || trim($name) === '' || strlen($name) > 100) {
            return ['valid' => false, 'error' => $this->trans('plugin.invalid_name', [], 'Plugin name is required and cannot exceed 100 characters.')];
        }

        $version = $data['version'] ?? null;
        if (!is_string($version) || !preg_match('/^v?[0-9]+\.[0-9]+\.[0-9]+(-[a-zA-Z0-9\.\-_]{1,50})?$/', $version)) {
            return ['valid' => false, 'error' => $this->trans('plugin.invalid_version', [], 'Plugin version must follow SemVer format (e.g. 1.0.0).')];
        }

        $description = $data['description'] ?? '';
        if (!is_string($description) || strlen($description) > 500) {
            return ['valid' => false, 'error' => $this->trans('plugin.description_too_long', [], 'Plugin description cannot exceed 500 characters.')];
        }

        // Validate permissions
        $permissions = $data['permissions'] ?? [];
        if (!is_array($permissions)) {
            return ['valid' => false, 'error' => $this->trans('plugin.permissions_must_be_array', [], 'Permissions must be an array.')];
        }
        if (count($permissions) > 50) {
            return ['valid' => false, 'error' => $this->trans('plugin.too_many_permissions', [], 'Too many permissions requested (max 50).')];
        }

        $sanitizedPermissions = [];
        foreach ($permissions as $perm) {
            if (!is_string($perm) || !in_array($perm, self::ALLOWED_PERMISSIONS, true)) {
                return ['valid' => false, 'error' => $this->trans('plugin.invalid_permission', ['%perm%' => (string) $perm], 'Invalid or unauthorized permission: ' . (string) $perm)];
            }
            $sanitizedPermissions[] = $perm;
        }

        // Validate hooks
        $hooks = $data['hooks'] ?? [];
        if (!is_array($hooks)) {
            return ['valid' => false, 'error' => $this->trans('plugin.hooks_must_be_array', [], 'Hooks must be an array.')];
        }
        if (count($hooks) > 50) {
            return ['valid' => false, 'error' => $this->trans('plugin.too_many_hooks', [], 'Too many hooks registered (max 50).')];
        }

        $sanitizedHooks = [];
        foreach ($hooks as $hook) {
            if (!is_string($hook) || !in_array($hook, self::ALLOWED_HOOKS, true)) {
                return ['valid' => false, 'error' => $this->trans('plugin.invalid_hook', ['%hook%' => (string) $hook], 'Invalid or unauthorized hook: ' . (string) $hook)];
            }
            $sanitizedHooks[] = $hook;
        }

        // Validate entrypoint
        $entrypoint = $data['entrypoint'] ?? null;
        if ($entrypoint !== null) {
            if (!is_string($entrypoint) || strlen($entrypoint) > 128) {
                return ['valid' => false, 'error' => $this->trans('plugin.invalid_entrypoint', [], 'Entrypoint path is invalid or too long.')];
            }
            if (
                str_starts_with($entrypoint, '/') ||
                str_starts_with($entrypoint, '\\') ||
                str_contains($entrypoint, '..') ||
                str_contains($entrypoint, "\0")
            ) {
                return ['valid' => false, 'error' => $this->trans('plugin.entrypoint_traversal', [], 'Entrypoint contains illegal path characters.')];
            }
        }

        $clean = [
            'id'          => $id,
            'name'        => trim(strip_tags($name)),
            'version'     => $version,
            'description' => trim(strip_tags($description)),
            'author'      => is_string($data['author'] ?? null) ? trim(strip_tags((string) $data['author'])) : null,
            'permissions' => array_values(array_unique($sanitizedPermissions)),
            'hooks'       => array_values(array_unique($sanitizedHooks)),
            'entrypoint'  => $entrypoint,
            'enabled'     => (bool) ($data['enabled'] ?? true),
            'settings'    => is_array($data['settings'] ?? null) ? $data['settings'] : [],
        ];

        return ['valid' => true, 'manifest' => $clean];
    }

    /**
     * Lists all registered plugins.
     *
     * @return array<int, array>
     */
    public function listPlugins(): array
    {
        $plugins = [];
        if (!is_dir($this->pluginsDir)) {
            return $plugins;
        }

        $entries = scandir($this->pluginsDir);
        if ($entries === false) {
            return $plugins;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || !preg_match('/^[a-z0-9\-_]{2,64}$/', $entry)) {
                continue;
            }

            $manifestPath = $this->pluginsDir . '/' . $entry . '/manifest.json';
            if (is_file($manifestPath)) {
                $content = @file_get_contents($manifestPath);
                if ($content !== false) {
                    $json = json_decode($content, true);
                    if (is_array($json)) {
                        $plugins[] = $json;
                    }
                }
            }
        }

        return $plugins;
    }

    /**
     * Gets a single plugin manifest.
     */
    public function getPlugin(string $pluginId): ?array
    {
        if (!preg_match('/^[a-z0-9\-_]{2,64}$/', $pluginId)) {
            return null;
        }

        $manifestPath = $this->pluginsDir . '/' . $pluginId . '/manifest.json';
        if (!is_file($manifestPath)) {
            return null;
        }

        $content = @file_get_contents($manifestPath);
        if ($content === false) {
            return null;
        }

        $json = json_decode($content, true);
        return is_array($json) ? $json : null;
    }

    /**
     * Registers or updates a plugin.
     */
    public function registerPlugin(array $manifest): bool
    {
        $pluginId = $manifest['id'] ?? '';
        if (!preg_match('/^[a-z0-9\-_]{2,64}$/', $pluginId)) {
            return false;
        }

        $targetDir = $this->pluginsDir . '/' . $pluginId;
        if (!is_dir($targetDir)) {
            if (!@mkdir($targetDir, 0755, true)) {
                return false;
            }
        }

        $manifest['installed_at'] = $manifest['installed_at'] ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $manifest['updated_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $manifestPath = $targetDir . '/manifest.json';

        return file_put_contents($manifestPath, $json, LOCK_EX) !== false;
    }

    /**
     * Toggles plugin enabled status.
     */
    public function togglePlugin(string $pluginId, ?bool $enabled = null): ?array
    {
        $plugin = $this->getPlugin($pluginId);
        if ($plugin === null) {
            return null;
        }

        $plugin['enabled'] = $enabled ?? !($plugin['enabled'] ?? true);
        $plugin['updated_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        if (!$this->registerPlugin($plugin)) {
            return null;
        }

        return $plugin;
    }

    /**
     * Deletes a plugin folder safely.
     */
    public function deletePlugin(string $pluginId): bool
    {
        if (!preg_match('/^[a-z0-9\-_]{2,64}$/', $pluginId)) {
            return false;
        }

        $targetDir = $this->pluginsDir . '/' . $pluginId;
        if (!is_dir($targetDir)) {
            return false;
        }

        // Verify confinement
        $baseReal = realpath($this->pluginsDir);
        $targetReal = realpath($targetDir);
        if ($baseReal === false || $targetReal === false || !str_starts_with($targetReal, $baseReal)) {
            return false;
        }

        $this->deleteDirectory($targetReal);
        return !is_dir($targetReal);
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = scandir($dir);
        if ($files === false) {
            return;
        }
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
