<?php

declare(strict_types=1);

namespace App\Service\Plugin;

use App\Entity\Project;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;

class PluginManager
{
    public const MAX_MANIFEST_BYTES = 65536; // 64 KB
    public const MAX_REGISTERED_PLUGINS = 100;
    public const MAX_ACTIVE_PLUGINS_GLOBAL = 50;
    public const MAX_SETTINGS_DEPTH = 5;
    public const MAX_SETTINGS_KEYS = 50;
    public const MAX_PERMISSIONS_COUNT = 20;
    public const MAX_HOOKS_COUNT = 30;

    public const PROJECT_TIER_LIMITS = [
        'free'       => 3,
        'starter'    => 10,
        'pro'        => 25,
        'enterprise' => 100,
    ];
    public const DEFAULT_PROJECT_PLUGIN_LIMIT = 5;

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

    public const DANGEROUS_EXTENSIONS = [
        'sh', 'bash', 'exe', 'bin', 'cgi', 'so', 'dll', 'phar', 'env', 'htaccess',
        'bat', 'cmd', 'ps1', 'vbs', 'com', 'scr'
    ];

    public const ALLOWED_ENTRYPOINT_EXTENSIONS = [
        'js', 'mjs', 'json', 'css', 'php'
    ];

    public const RESERVED_PLUGIN_IDS = [
        'system', 'core', 'admin', 'api', 'config', 'root', 'null', 'undefined', 'jambo'
    ];

    public const FORBIDDEN_KEYS = [
        '__proto__', 'constructor', 'prototype'
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

        if (in_array(strtolower($id), self::RESERVED_PLUGIN_IDS, true)) {
            return ['valid' => false, 'error' => $this->trans('plugin.reserved_id', ['%id%' => $id], sprintf('Plugin ID "%s" is reserved and cannot be used.', $id))];
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
        if (count($permissions) > self::MAX_PERMISSIONS_COUNT) {
            return ['valid' => false, 'error' => $this->trans('plugin.too_many_permissions', ['%max%' => (string) self::MAX_PERMISSIONS_COUNT], sprintf('Too many permissions requested (max %d).', self::MAX_PERMISSIONS_COUNT))];
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
        if (count($hooks) > self::MAX_HOOKS_COUNT) {
            return ['valid' => false, 'error' => $this->trans('plugin.too_many_hooks', ['%max%' => (string) self::MAX_HOOKS_COUNT], sprintf('Too many hooks registered (max %d).', self::MAX_HOOKS_COUNT))];
        }

        $sanitizedHooks = [];
        foreach ($hooks as $hook) {
            if (!is_string($hook) || !in_array($hook, self::ALLOWED_HOOKS, true)) {
                return ['valid' => false, 'error' => $this->trans('plugin.invalid_hook', ['%hook%' => (string) $hook], 'Invalid or unauthorized hook: ' . (string) $hook)];
            }
            $sanitizedHooks[] = $hook;
        }

        // Validate entrypoint (sandboxed frontend / client assets only, anti-execution)
        $entrypoint = $data['entrypoint'] ?? null;
        if ($entrypoint !== null && $entrypoint !== '') {
            if (!is_string($entrypoint) || strlen($entrypoint) > 128) {
                return ['valid' => false, 'error' => $this->trans('plugin.invalid_entrypoint', [], 'Entrypoint path is invalid or too long.')];
            }
            if (
                str_starts_with($entrypoint, '/') ||
                str_starts_with($entrypoint, '\\') ||
                str_contains($entrypoint, '..') ||
                str_contains($entrypoint, "\0") ||
                str_contains($entrypoint, '://') ||
                str_starts_with(strtolower($entrypoint), 'php:') ||
                str_starts_with(strtolower($entrypoint), 'phar:') ||
                str_starts_with(strtolower($entrypoint), 'data:') ||
                str_starts_with(strtolower($entrypoint), 'file:')
            ) {
                return ['valid' => false, 'error' => $this->trans('plugin.entrypoint_traversal', [], 'Entrypoint contains illegal path characters.')];
            }

            if (!preg_match('/^[a-zA-Z0-9_\-\.\/]+$/', $entrypoint)) {
                return ['valid' => false, 'error' => $this->trans('plugin.invalid_entrypoint', [], 'Entrypoint path contains invalid characters.')];
            }

            $ext = strtolower(pathinfo($entrypoint, PATHINFO_EXTENSION));
            if (in_array($ext, self::DANGEROUS_EXTENSIONS, true) || ($ext !== '' && !in_array($ext, self::ALLOWED_ENTRYPOINT_EXTENSIONS, true))) {
                return ['valid' => false, 'error' => $this->trans('plugin.unsafe_entrypoint', ['%ext%' => $ext ?: 'none'], sprintf('Unsafe or unauthorized entrypoint extension or path: %s.', $ext ?: 'none'))];
            }
        } else {
            $entrypoint = null;
        }

        // Validate settings schema and bounds
        $settings = $data['settings'] ?? [];
        if (!is_array($settings)) {
            return ['valid' => false, 'error' => $this->trans('plugin.settings_must_be_array', [], 'Plugin settings must be an array or object.')];
        }

        $settingsValidation = $this->validateSettingsTree($settings, 1);
        if (!$settingsValidation['valid']) {
            return $settingsValidation;
        }

        $cleanSettings = $this->sanitizeSettingsTree($settings);

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
            'settings'    => $cleanSettings,
        ];

        return ['valid' => true, 'manifest' => $clean];
    }

    /**
     * Validates recursive settings tree bounds, depth and forbidden keys.
     *
     * @return array{valid: bool, error?: string}
     */
    public function validateSettingsTree(mixed $node, int $depth = 1): array
    {
        if ($depth > self::MAX_SETTINGS_DEPTH) {
            return [
                'valid' => false,
                'error' => $this->trans('plugin.invalid_settings_depth', ['%max%' => (string) self::MAX_SETTINGS_DEPTH], sprintf('Settings depth limit exceeded (max %d levels).', self::MAX_SETTINGS_DEPTH)),
            ];
        }

        if (is_array($node)) {
            if (count($node) > self::MAX_SETTINGS_KEYS) {
                return [
                    'valid' => false,
                    'error' => $this->trans('plugin.too_many_settings_keys', ['%max%' => (string) self::MAX_SETTINGS_KEYS], sprintf('Settings contain too many keys (max %d).', self::MAX_SETTINGS_KEYS)),
                ];
            }

            foreach ($node as $key => $val) {
                if (is_string($key)) {
                    if (in_array(strtolower($key), self::FORBIDDEN_KEYS, true)) {
                        return [
                            'valid' => false,
                            'error' => $this->trans('plugin.invalid_settings_key', ['%key%' => $key], sprintf('Forbidden configuration key detected: %s.', $key)),
                        ];
                    }
                    if (strlen($key) > 64 || !preg_match('/^[a-zA-Z0-9_\-\.]+$/', $key)) {
                        return [
                            'valid' => false,
                            'error' => $this->trans('plugin.invalid_settings_key_format', [], 'Invalid configuration key format.'),
                        ];
                    }
                }

                $childRes = $this->validateSettingsTree($val, $depth + 1);
                if (!$childRes['valid']) {
                    return $childRes;
                }
            }
        } elseif (is_string($node)) {
            if (strlen($node) > 2048) {
                return [
                    'valid' => false,
                    'error' => $this->trans('plugin.settings_value_too_long', [], 'Configuration value exceeds 2048 characters.'),
                ];
            }
            if (
                preg_match('/<\s*script/i', $node) ||
                preg_match('/javascript\s*:/i', $node) ||
                preg_match('/data\s*:\s*text\/html/i', $node) ||
                preg_match('/vbscript\s*:/i', $node)
            ) {
                return [
                    'valid' => false,
                    'error' => $this->trans('plugin.invalid_settings_value', [], 'Configuration value contains prohibited script or dangerous protocol.'),
                ];
            }
        }

        return ['valid' => true];
    }

    /**
     * Sanitizes settings tree strings by stripping tags and trimming.
     */
    public function sanitizeSettingsTree(mixed $node): mixed
    {
        if (is_array($node)) {
            $clean = [];
            foreach ($node as $key => $val) {
                $clean[$key] = $this->sanitizeSettingsTree($val);
            }
            return $clean;
        }

        if (is_string($node)) {
            return trim(strip_tags($node));
        }

        return $node;
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
        $manifestPath = $targetDir . '/manifest.json';

        // Check global quota if this is a newly registered plugin
        if (!is_file($manifestPath) && count($this->listPlugins()) >= self::MAX_REGISTERED_PLUGINS) {
            return false;
        }

        if (!is_dir($targetDir)) {
            if (!@mkdir($targetDir, 0755, true)) {
                return false;
            }
        }

        $manifest['installed_at'] = $manifest['installed_at'] ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $manifest['updated_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return file_put_contents($manifestPath, $json, LOCK_EX) !== false;
    }

    /**
     * Toggles plugin enabled status globally.
     */
    public function togglePlugin(string $pluginId, ?bool $enabled = null): ?array
    {
        $plugin = $this->getPlugin($pluginId);
        if ($plugin === null) {
            return null;
        }

        $newEnabled = $enabled ?? !($plugin['enabled'] ?? true);

        // Global active quota check
        if ($newEnabled === true && !($plugin['enabled'] ?? false)) {
            $activeCount = count(array_filter($this->listPlugins(), fn ($p) => ($p['enabled'] ?? true) === true));
            if ($activeCount >= self::MAX_ACTIVE_PLUGINS_GLOBAL) {
                return null;
            }
        }

        $plugin['enabled'] = $newEnabled;
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

    // ─── Multi-Tenant & Project Plugin Quotas ──────────────────────

    /**
     * Resolves max active plugins quota for a given project/tenant.
     */
    public function getProjectPluginLimit(Project $project): int
    {
        if (isset($project->settings['max_plugins']) && is_numeric($project->settings['max_plugins'])) {
            return max(1, (int) $project->settings['max_plugins']);
        }
        $tier = strtolower((string) ($project->settings['tier'] ?? $project->settings['plan'] ?? 'free'));
        return self::PROJECT_TIER_LIMITS[$tier] ?? self::DEFAULT_PROJECT_PLUGIN_LIMIT;
    }

    /**
     * Returns list of currently active plugin IDs for the project.
     *
     * @return array<int, string>
     */
    public function getProjectActivePlugins(Project $project): array
    {
        $active = $project->settings['plugins']['active'] ?? [];
        if (!is_array($active)) {
            return [];
        }

        $valid = [];
        foreach ($active as $pluginId) {
            if (is_string($pluginId) && $this->isPluginGloballyEnabled($pluginId)) {
                $valid[] = $pluginId;
            }
        }

        return array_values(array_unique($valid));
    }

    public function isPluginGloballyEnabled(string $pluginId): bool
    {
        $plugin = $this->getPlugin($pluginId);
        return $plugin !== null && ($plugin['enabled'] ?? true) === true;
    }

    public function isPluginActiveForProject(Project $project, string $pluginId): bool
    {
        return in_array($pluginId, $this->getProjectActivePlugins($project), true);
    }

    /**
     * Activates a plugin for a project subject to tenant tier quotas.
     *
     * @return array{success: bool, active?: bool, error?: string}
     */
    public function activatePluginForProject(Project $project, string $pluginId): array
    {
        $plugin = $this->getPlugin($pluginId);
        if ($plugin === null) {
            return ['success' => false, 'error' => $this->trans('plugin.not_found', [], 'Plugin not found.')];
        }
        if (($plugin['enabled'] ?? true) === false) {
            return ['success' => false, 'error' => $this->trans('plugin.globally_disabled', [], 'Plugin is globally disabled by administrator.')];
        }

        $active = $this->getProjectActivePlugins($project);
        if (in_array($pluginId, $active, true)) {
            return ['success' => true, 'active' => true];
        }

        $limit = $this->getProjectPluginLimit($project);
        if (count($active) >= $limit) {
            return [
                'success' => false,
                'error' => $this->trans('plugin.quota.project_limit_exceeded', ['%limit%' => (string) $limit], sprintf('Maximum active plugins quota exceeded for this project tier (%d plugins max).', $limit)),
            ];
        }

        $active[] = $pluginId;
        $settings = $project->settings ?? [];
        $settings['plugins'] = $settings['plugins'] ?? [];
        $settings['plugins']['active'] = array_values(array_unique($active));
        $project->settings = $settings;

        return ['success' => true, 'active' => true];
    }

    /**
     * Deactivates a plugin for a project.
     *
     * @return array{success: bool, active: bool}
     */
    public function deactivatePluginForProject(Project $project, string $pluginId): array
    {
        $settings = $project->settings ?? [];
        $active = $settings['plugins']['active'] ?? [];
        if (is_array($active)) {
            $settings['plugins']['active'] = array_values(array_filter($active, fn ($id) => $id !== $pluginId));
            $project->settings = $settings;
        }

        return ['success' => true, 'active' => false];
    }

    /**
     * Gets project-specific plugin settings.
     */
    public function getProjectPluginSettings(Project $project, string $pluginId): array
    {
        $configs = $project->settings['plugins']['configs'] ?? [];
        return is_array($configs) && isset($configs[$pluginId]) && is_array($configs[$pluginId])
            ? $configs[$pluginId]
            : [];
    }

    /**
     * Sets project-specific plugin settings after validating schema & depth.
     *
     * @return array{success: bool, settings?: array, error?: string}
     */
    public function setProjectPluginSettings(Project $project, string $pluginId, array $config): array
    {
        $plugin = $this->getPlugin($pluginId);
        if ($plugin === null) {
            return ['success' => false, 'error' => $this->trans('plugin.not_found', [], 'Plugin not found.')];
        }

        $depthCheck = $this->validateSettingsTree($config, 1);
        if (!$depthCheck['valid']) {
            return ['success' => false, 'error' => $depthCheck['error']];
        }

        $sanitized = $this->sanitizeSettingsTree($config);
        $settings = $project->settings ?? [];
        $settings['plugins'] = $settings['plugins'] ?? [];
        $settings['plugins']['configs'] = $settings['plugins']['configs'] ?? [];
        $settings['plugins']['configs'][$pluginId] = $sanitized;
        $project->settings = $settings;

        return ['success' => true, 'settings' => $sanitized];
    }
}
