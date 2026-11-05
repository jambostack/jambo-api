<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AuditService;
use App\Service\Plugin\PluginManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/admin/plugins', name: 'api_admin_plugins_')]
class PluginController extends AbstractController
{
    private const SECURITY_HEADERS = [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        'Pragma' => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function __construct(
        private readonly PluginManager $pluginManager,
        private readonly ?AuditService $auditService = null,
        private readonly ?RateLimiterFactory $tokenLimiter = null,
        private readonly ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters, 'messages');
        }
        return $default ?? $id;
    }

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    private function denyNonAdmin(): ?JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->secureJson(['error' => $this->trans('plugin.auth_required', [], 'Authentication required.')], 401);
        }

        $roles = $user->getRoles();
        if (!in_array('ROLE_SUPER_ADMIN', $roles, true) && !in_array('ROLE_ADMIN', $roles, true)) {
            return $this->secureJson(['error' => $this->trans('plugin.access_denied', [], 'Access denied: administrator privileges required.')], 403);
        }

        return null;
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        if ($error = $this->denyNonAdmin()) {
            return $error;
        }

        $plugins = $this->pluginManager->listPlugins();
        return $this->secureJson(['data' => $plugins]);
    }

    #[Route('/{pluginId}', name: 'show', methods: ['GET'], requirements: ['pluginId' => '[a-z0-9\-_]{2,64}'])]
    public function show(string $pluginId): JsonResponse
    {
        if ($error = $this->denyNonAdmin()) {
            return $error;
        }

        $plugin = $this->pluginManager->getPlugin($pluginId);
        if ($plugin === null) {
            return $this->secureJson(['error' => $this->trans('plugin.not_found', [], 'Plugin not found.')], 404);
        }

        return $this->secureJson(['data' => $plugin]);
    }

    #[Route('/validate', name: 'validate', methods: ['POST'])]
    public function validate(Request $request): JsonResponse
    {
        if ($error = $this->denyNonAdmin()) {
            return $error;
        }

        $content = $request->getContent();
        if (strlen($content) > PluginManager::MAX_MANIFEST_BYTES) {
            return $this->secureJson(['error' => $this->trans('plugin.manifest_too_large', [], 'Plugin manifest exceeds size limit (64KB).')], 413);
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return $this->secureJson(['error' => $this->trans('plugin.invalid_json', [], 'Plugin manifest must be a valid JSON object.')], 400);
        }

        $validation = $this->pluginManager->validateManifest($data);
        if (!$validation['valid']) {
            return $this->secureJson(['error' => $validation['error']], 422);
        }

        return $this->secureJson([
            'valid'    => true,
            'manifest' => $validation['manifest'],
        ]);
    }

    #[Route('', name: 'register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        if ($error = $this->denyNonAdmin()) {
            return $error;
        }

        if ($this->tokenLimiter !== null) {
            $identifier = $this->getUser()?->getUserIdentifier() ?: 'anonymous';
            if (false === $this->tokenLimiter->create($identifier)->consume(1)->isAccepted()) {
                return $this->secureJson(['error' => $this->trans('plugin.rate_limit_exceeded', [], 'Too many plugin requests. Please retry later.')], 429);
            }
        }

        $content = $request->getContent();
        if (strlen($content) > PluginManager::MAX_MANIFEST_BYTES) {
            return $this->secureJson(['error' => $this->trans('plugin.manifest_too_large', [], 'Plugin manifest exceeds size limit (64KB).')], 413);
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return $this->secureJson(['error' => $this->trans('plugin.invalid_json', [], 'Plugin manifest must be a valid JSON object.')], 400);
        }

        $validation = $this->pluginManager->validateManifest($data);
        if (!$validation['valid']) {
            return $this->secureJson(['error' => $validation['error']], 422);
        }

        $manifest = $validation['manifest'];
        $success = $this->pluginManager->registerPlugin($manifest);
        if (!$success) {
            return $this->secureJson(['error' => $this->trans('plugin.save_failed', [], 'Failed to save plugin manifest.')], 500);
        }

        $this->auditService?->log(
            toolName: 'plugin_register',
            project: null,
            input: ['plugin_id' => $manifest['id'], 'version' => $manifest['version']],
            output: ['status' => 'registered'],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'admin_api',
        );

        return $this->secureJson(['data' => $manifest], 201);
    }

    #[Route('/{pluginId}/toggle', name: 'toggle', methods: ['PATCH'], requirements: ['pluginId' => '[a-z0-9\-_]{2,64}'])]
    public function toggle(string $pluginId, Request $request): JsonResponse
    {
        if ($error = $this->denyNonAdmin()) {
            return $error;
        }

        $plugin = $this->pluginManager->getPlugin($pluginId);
        if ($plugin === null) {
            return $this->secureJson(['error' => $this->trans('plugin.not_found', [], 'Plugin not found.')], 404);
        }

        $body = json_decode($request->getContent(), true);
        $enabled = is_array($body) && isset($body['enabled']) ? (bool) $body['enabled'] : null;

        $updated = $this->pluginManager->togglePlugin($pluginId, $enabled);
        if ($updated === null) {
            return $this->secureJson(['error' => $this->trans('plugin.toggle_failed', [], 'Failed to toggle plugin state.')], 500);
        }

        $this->auditService?->log(
            toolName: 'plugin_toggle',
            project: null,
            input: ['plugin_id' => $pluginId, 'enabled' => $updated['enabled']],
            output: ['status' => 'toggled'],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'admin_api',
        );

        return $this->secureJson(['data' => $updated]);
    }

    #[Route('/{pluginId}', name: 'delete', methods: ['DELETE'], requirements: ['pluginId' => '[a-z0-9\-_]{2,64}'])]
    public function delete(string $pluginId): JsonResponse
    {
        if ($error = $this->denyNonAdmin()) {
            return $error;
        }

        $plugin = $this->pluginManager->getPlugin($pluginId);
        if ($plugin === null) {
            return $this->secureJson(['error' => $this->trans('plugin.not_found', [], 'Plugin not found.')], 404);
        }

        $deleted = $this->pluginManager->deletePlugin($pluginId);
        if (!$deleted) {
            return $this->secureJson(['error' => $this->trans('plugin.delete_failed', [], 'Failed to delete plugin.')], 500);
        }

        $this->auditService?->log(
            toolName: 'plugin_delete',
            project: null,
            input: ['plugin_id' => $pluginId],
            output: ['status' => 'deleted'],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'admin_api',
        );

        return $this->secureJson(['status' => 'deleted', 'id' => $pluginId]);
    }
}
