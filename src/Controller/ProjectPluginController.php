<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Project;
use App\Entity\User;
use App\Repository\ProjectRepository;
use App\Service\AuditService;
use App\Service\Plugin\PluginManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/projects/{projectUuid}/plugins', name: 'api_project_plugins_')]
class ProjectPluginController extends AbstractController
{
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const MAX_CONFIG_PAYLOAD_BYTES = 65536; // 64 KB

    private const SECURITY_HEADERS = [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        'Pragma' => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
    ];

    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly PluginManager $pluginManager,
        private readonly EntityManagerInterface $em,
        private readonly ?AuditService $auditService = null,
        #[Autowire(service: 'limiter.project_limiter')]
        private readonly ?RateLimiterFactory $projectLimiter = null,
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

    private function checkRateLimit(Request $request): ?JsonResponse
    {
        if ($this->projectLimiter !== null) {
            $user = $this->getUser();
            $identifier = $user instanceof User && $user->id ? ('user_' . $user->id) : ($request->getClientIp() ?: '127.0.0.1');
            if (false === $this->projectLimiter->create($identifier)->consume(1)->isAccepted()) {
                return $this->secureJson([
                    'error' => $this->trans('plugin.rate_limit_exceeded', [], 'Too many plugin requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            }
        }
        return null;
    }

    private function resolveProject(string $projectUuid): Project|JsonResponse
    {
        if (!preg_match(self::UUID_REGEX, $projectUuid)) {
            return $this->secureJson([
                'error' => $this->trans('plugin.project_not_found', [], 'Project not found'),
                'code'  => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('plugin.project_not_found', [], 'Project not found'),
                'code'  => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        if (!$project) {
            return $this->secureJson([
                'error' => $this->trans('plugin.project_not_found', [], 'Project not found'),
                'code'  => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        return $project;
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(string $projectUuid, ?Request $request = null): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->secureJson(['error' => $this->trans('plugin.auth_required', [], 'Authentication required.')], 401);
        }

        if ($request !== null && ($error = $this->checkRateLimit($request))) {
            return $error;
        }

        $project = $this->resolveProject($projectUuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $this->denyAccessUnlessGranted('project.view', $project);

        $allPlugins = $this->pluginManager->listPlugins();
        $activePlugins = $this->pluginManager->getProjectActivePlugins($project);
        $limit = $this->pluginManager->getProjectPluginLimit($project);

        $data = array_map(function (array $p) use ($project, $activePlugins) {
            $id = $p['id'];
            $isActive = in_array($id, $activePlugins, true);
            return [
                'id'               => $id,
                'name'             => $p['name'] ?? $id,
                'version'          => $p['version'] ?? '1.0.0',
                'description'      => $p['description'] ?? '',
                'author'           => $p['author'] ?? null,
                'permissions'      => $p['permissions'] ?? [],
                'hooks'            => $p['hooks'] ?? [],
                'globally_enabled' => (bool) ($p['enabled'] ?? true),
                'active'           => $isActive,
                'settings'         => $isActive ? $this->pluginManager->getProjectPluginSettings($project, $id) : [],
            ];
        }, $allPlugins);

        return $this->secureJson([
            'data'  => $data,
            'quota' => [
                'limit'     => $limit,
                'used'      => count($activePlugins),
                'remaining' => max(0, $limit - count($activePlugins)),
            ],
        ]);
    }

    #[Route('/{pluginId}/toggle', name: 'toggle', methods: ['PATCH'], requirements: ['pluginId' => '[a-z0-9\-_]{2,64}'])]
    public function toggle(string $projectUuid, string $pluginId, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->secureJson(['error' => $this->trans('plugin.auth_required', [], 'Authentication required.')], 401);
        }

        if ($error = $this->checkRateLimit($request)) {
            return $error;
        }

        $project = $this->resolveProject($projectUuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $this->denyAccessUnlessGranted('project.manage', $project);

        $plugin = $this->pluginManager->getPlugin($pluginId);
        if ($plugin === null) {
            return $this->secureJson(['error' => $this->trans('plugin.not_found', [], 'Plugin not found.')], 404);
        }

        $body = json_decode($request->getContent(), true);
        $desiredState = is_array($body) && isset($body['enabled']) ? (bool) $body['enabled'] : null;

        $currentlyActive = $this->pluginManager->isPluginActiveForProject($project, $pluginId);
        $targetActive = $desiredState ?? !$currentlyActive;

        if ($targetActive) {
            $res = $this->pluginManager->activatePluginForProject($project, $pluginId);
            if (!$res['success']) {
                return $this->secureJson([
                    'error' => $res['error'],
                    'code'  => 'QUOTA_EXCEEDED',
                ], 422);
            }
        } else {
            $res = $this->pluginManager->deactivatePluginForProject($project, $pluginId);
        }

        $this->em->flush();

        $activePlugins = $this->pluginManager->getProjectActivePlugins($project);
        $limit = $this->pluginManager->getProjectPluginLimit($project);

        $this->auditService?->log(
            toolName: 'project_plugin_toggle',
            project: $project,
            input: ['plugin_id' => $pluginId, 'active' => $targetActive],
            output: ['status' => $targetActive ? 'activated' : 'deactivated'],
            status: 'success',
            createdBy: $user->getUserIdentifier(),
            source: 'project_plugin_controller',
        );

        return $this->secureJson([
            'id'     => $pluginId,
            'active' => $targetActive,
            'quota'  => [
                'limit'     => $limit,
                'used'      => count($activePlugins),
                'remaining' => max(0, $limit - count($activePlugins)),
            ],
        ]);
    }

    #[Route('/{pluginId}/settings', name: 'get_settings', methods: ['GET'], requirements: ['pluginId' => '[a-z0-9\-_]{2,64}'])]
    public function getSettings(string $projectUuid, string $pluginId, ?Request $request = null): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->secureJson(['error' => $this->trans('plugin.auth_required', [], 'Authentication required.')], 401);
        }

        if ($request !== null && ($error = $this->checkRateLimit($request))) {
            return $error;
        }

        $project = $this->resolveProject($projectUuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $this->denyAccessUnlessGranted('project.view', $project);

        $plugin = $this->pluginManager->getPlugin($pluginId);
        if ($plugin === null) {
            return $this->secureJson(['error' => $this->trans('plugin.not_found', [], 'Plugin not found.')], 404);
        }

        $settings = $this->pluginManager->getProjectPluginSettings($project, $pluginId);
        return $this->secureJson([
            'id'       => $pluginId,
            'settings' => $settings,
        ]);
    }

    #[Route('/{pluginId}/settings', name: 'update_settings', methods: ['PUT'], requirements: ['pluginId' => '[a-z0-9\-_]{2,64}'])]
    public function updateSettings(string $projectUuid, string $pluginId, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->secureJson(['error' => $this->trans('plugin.auth_required', [], 'Authentication required.')], 401);
        }

        if ($error = $this->checkRateLimit($request)) {
            return $error;
        }

        $project = $this->resolveProject($projectUuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $this->denyAccessUnlessGranted('project.manage', $project);

        $plugin = $this->pluginManager->getPlugin($pluginId);
        if ($plugin === null) {
            return $this->secureJson(['error' => $this->trans('plugin.not_found', [], 'Plugin not found.')], 404);
        }

        $content = $request->getContent();
        if (strlen($content) > self::MAX_CONFIG_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('plugin.manifest_too_large', [], 'Plugin manifest exceeds size limit (64KB).'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        $body = json_decode($content, true);
        if (!is_array($body)) {
            return $this->secureJson([
                'error' => $this->trans('plugin.invalid_json', [], 'Plugin manifest must be a valid JSON object.'),
                'code'  => 'INVALID_JSON',
            ], 400);
        }

        $config = $body['settings'] ?? $body;
        if (!is_array($config)) {
            return $this->secureJson([
                'error' => $this->trans('plugin.settings_must_be_array', [], 'Plugin settings must be an array or object.'),
                'code'  => 'SETTINGS_MUST_BE_ARRAY',
            ], 422);
        }

        $res = $this->pluginManager->setProjectPluginSettings($project, $pluginId, $config);
        if (!$res['success']) {
            return $this->secureJson([
                'error' => $res['error'],
                'code'  => 'INVALID_SETTINGS',
            ], 422);
        }

        $this->em->flush();

        $this->auditService?->log(
            toolName: 'project_plugin_settings_update',
            project: $project,
            input: ['plugin_id' => $pluginId],
            output: ['status' => 'updated'],
            status: 'success',
            createdBy: $user->getUserIdentifier(),
            source: 'project_plugin_controller',
        );

        return $this->secureJson([
            'id'       => $pluginId,
            'settings' => $res['settings'],
        ]);
    }
}
