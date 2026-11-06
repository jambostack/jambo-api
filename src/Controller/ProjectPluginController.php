<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Project;
use App\Entity\User;
use App\Repository\ProjectRepository;
use App\Service\ApiTokenChecker;
use App\Service\AuditService;
use App\Service\Plugin\PluginManager;
use App\Service\Security\SsrfProtectionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
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
        private readonly ?ApiTokenChecker $tokenChecker = null,
        private readonly ?SsrfProtectionService $ssrfProtection = null,
        private readonly ?HttpClientInterface $httpClient = null,
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
            $user = null;
            try {
                $user = $this->getUser();
            } catch (\Throwable) {}
            $identifier = $user instanceof User && $user->id ? ('user_' . $user->id) : ($request->getClientIp() ?: '127.0.0.1');
            $limit = $this->projectLimiter->create($identifier)->consume(1);
            if (false === $limit->isAccepted()) {
                $retryAfter = max(1, ($limit->getRetryAfter()?->getTimestamp() ?? (time() + 60)) - time());
                $this->auditService?->log(
                    toolName: 'project_plugin_rate_limit',
                    project: null,
                    input: ['identifier' => $identifier],
                    output: ['error' => 'rate_limit_exceeded'],
                    status: 'blocked',
                    createdBy: $identifier,
                    source: 'project_plugin_controller',
                );
                $this->auditService?->flush();

                return $this->secureJson([
                    'error' => $this->trans('plugin.rate_limit_exceeded', [], 'Too many plugin requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429, [
                    'Retry-After' => (string) $retryAfter,
                    'X-RateLimit-Limit' => (string) $limit->getLimit(),
                    'X-RateLimit-Remaining' => (string) $limit->getRemainingTokens(),
                    'X-RateLimit-Reset' => (string) ($limit->getRetryAfter()?->getTimestamp() ?? (time() + 60)),
                ]);
            }
        }
        return null;
    }

    private function validateContentType(Request $request): ?JsonResponse
    {
        $contentType = (string) $request->headers->get('Content-Type', '');
        if ($contentType !== '' && !str_starts_with(strtolower($contentType), 'application/json')) {
            return $this->secureJson([
                'error' => $this->trans('plugin.invalid_content_type', [], 'Invalid Content-Type header, application/json expected.'),
                'code'  => 'INVALID_CONTENT_TYPE',
            ], 415);
        }
        return null;
    }

    private function getUserIdentifier(Request $request): string
    {
        $user = $this->getUser();
        if ($user instanceof User) {
            return $user->getUserIdentifier();
        }
        $token = $this->tokenChecker?->resolve($request);
        if ($token !== null) {
            return 'token_' . substr($token->token, 0, 8);
        }
        return $request->getClientIp() ?: '127.0.0.1';
    }

    private function validateSettingsSsrf(mixed $node): ?string
    {
        if (is_array($node)) {
            foreach ($node as $val) {
                $err = $this->validateSettingsSsrf($val);
                if ($err !== null) {
                    return $err;
                }
            }
        } elseif (is_string($node)) {
            if (preg_match('/^(?:file|phar|php|gopher|dict|expect)\s*:/i', $node)) {
                return 'plugin.settings_unsafe_protocol';
            }
            if ($this->ssrfProtection !== null && preg_match('#^https?://#i', $node)) {
                $reason = null;
                if (!$this->ssrfProtection->isSafeUrl($node, false, $reason)) {
                    return 'plugin.settings_ssrf_blocked';
                }
            }
        }
        return null;
    }

    /**
     * @return array{0: ?Project, 1: ?JsonResponse}
     */
    private function findProjectAndCheckAccess(string $projectUuid, Request $request, string $attribute): array
    {
        if (!preg_match(self::UUID_REGEX, $projectUuid)) {
            return [null, $this->secureJson([
                'error' => $this->trans('plugin.project_not_found', [], 'Project not found'),
                'code'  => 'PROJECT_NOT_FOUND',
            ], 404)];
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return [null, $this->secureJson([
                'error' => $this->trans('plugin.project_not_found', [], 'Project not found'),
                'code'  => 'PROJECT_NOT_FOUND',
            ], 404)];
        }

        if (!$project) {
            return [null, $this->secureJson([
                'error' => $this->trans('plugin.project_not_found', [], 'Project not found'),
                'code'  => 'PROJECT_NOT_FOUND',
            ], 404)];
        }

        // Cloisonnement strict multi-tenant
        $attrProject = $request->attributes->get('_project');
        if ($attrProject instanceof Project && $attrProject->uuid?->toRfc4122() !== $project->uuid?->toRfc4122()) {
            $this->auditService?->log(
                toolName: 'project_plugin_tenant_isolation',
                project: $project,
                input: ['project_uuid' => $projectUuid],
                output: ['error' => 'token_project_mismatch'],
                status: 'blocked',
                createdBy: $this->getUserIdentifier($request),
                source: 'project_plugin_controller',
            );
            $this->auditService?->flush();

            return [null, $this->secureJson([
                'error' => $this->trans('plugin.token_project_mismatch', [], 'Forbidden: token belongs to another project.'),
                'code'  => 'TENANT_MISMATCH',
            ], 403)];
        }

        $token = $this->tokenChecker?->resolve($request);
        if ($token !== null) {
            if ($token->project->uuid?->toRfc4122() !== $project->uuid?->toRfc4122()) {
                $this->auditService?->log(
                    toolName: 'project_plugin_tenant_isolation',
                    project: $project,
                    input: ['project_uuid' => $projectUuid],
                    output: ['error' => 'token_project_mismatch'],
                    status: 'blocked',
                    createdBy: $this->getUserIdentifier($request),
                    source: 'project_plugin_controller',
                );
                $this->auditService?->flush();

                return [null, $this->secureJson([
                    'error' => $this->trans('plugin.token_project_mismatch', [], 'Forbidden: token belongs to another project.'),
                    'code'  => 'TENANT_MISMATCH',
                ], 403)];
            }
            $requiredAbility = ($attribute === 'project.view' || $attribute === 'project.read') ? 'read' : 'write';
            if (!$token->can('*') && !$token->can($requiredAbility)) {
                $this->auditService?->log(
                    toolName: 'project_plugin_permissions',
                    project: $project,
                    input: ['project_uuid' => $projectUuid, 'required' => $requiredAbility],
                    output: ['error' => 'token_missing_ability'],
                    status: 'blocked',
                    createdBy: $this->getUserIdentifier($request),
                    source: 'project_plugin_controller',
                );
                $this->auditService?->flush();

                return [null, $this->secureJson([
                    'error' => $this->trans('plugin.token_missing_ability', [], 'Forbidden: token missing required ability.'),
                    'code'  => 'INSUFFICIENT_PERMISSIONS',
                ], 403)];
            }
        } else {
            $user = $this->getUser();
            if (!$user instanceof User) {
                return [null, $this->secureJson([
                    'error' => $this->trans('plugin.auth_required', [], 'Authentication required.'),
                    'code'  => 'AUTH_REQUIRED',
                ], 401)];
            }
            if ($request->attributes->get('_project') !== $project) {
                $this->denyAccessUnlessGranted($attribute, $project);
            }
        }

        return [$project, null];
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
        $req = $request ?? new Request();
        if ($error = $this->checkRateLimit($req)) {
            return $error;
        }

        [$project, $errorResponse] = $this->findProjectAndCheckAccess($projectUuid, $req, 'project.view');
        if ($errorResponse !== null) {
            return $errorResponse;
        }

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
        if ($error = $this->checkRateLimit($request)) {
            return $error;
        }

        if ($contentError = $this->validateContentType($request)) {
            return $contentError;
        }

        [$project, $errorResponse] = $this->findProjectAndCheckAccess($projectUuid, $request, 'project.manage');
        if ($errorResponse !== null) {
            return $errorResponse;
        }

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
            createdBy: $this->getUserIdentifier($request),
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
        $req = $request ?? new Request();
        if ($error = $this->checkRateLimit($req)) {
            return $error;
        }

        [$project, $errorResponse] = $this->findProjectAndCheckAccess($projectUuid, $req, 'project.view');
        if ($errorResponse !== null) {
            return $errorResponse;
        }

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
        if ($error = $this->checkRateLimit($request)) {
            return $error;
        }

        if ($contentError = $this->validateContentType($request)) {
            return $contentError;
        }

        [$project, $errorResponse] = $this->findProjectAndCheckAccess($projectUuid, $request, 'project.manage');
        if ($errorResponse !== null) {
            return $errorResponse;
        }

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

        $ssrfError = $this->validateSettingsSsrf($config);
        if ($ssrfError !== null) {
            $this->auditService?->log(
                toolName: 'project_plugin_settings_update',
                project: $project,
                input: ['plugin_id' => $pluginId],
                output: ['error' => $ssrfError],
                status: 'blocked',
                createdBy: $this->getUserIdentifier($request),
                source: 'project_plugin_controller',
            );
            $this->auditService?->flush();

            return $this->secureJson([
                'error' => $this->trans($ssrfError),
                'code'  => 'SSRF_BLOCKED',
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
            createdBy: $this->getUserIdentifier($request),
            source: 'project_plugin_controller',
        );

        return $this->secureJson([
            'id'       => $pluginId,
            'settings' => $res['settings'],
        ]);
    }

    #[Route('/{pluginId}/test-endpoint', name: 'test_endpoint', methods: ['POST'], requirements: ['pluginId' => '[a-z0-9\-_]{2,64}'])]
    public function testEndpoint(string $projectUuid, string $pluginId, Request $request): JsonResponse
    {
        if ($error = $this->checkRateLimit($request)) {
            return $error;
        }

        if ($contentError = $this->validateContentType($request)) {
            return $contentError;
        }

        [$project, $errorResponse] = $this->findProjectAndCheckAccess($projectUuid, $request, 'project.manage');
        if ($errorResponse !== null) {
            return $errorResponse;
        }

        $plugin = $this->pluginManager->getPlugin($pluginId);
        if ($plugin === null) {
            return $this->secureJson(['error' => $this->trans('plugin.not_found', [], 'Plugin not found.')], 404);
        }

        if (!$this->pluginManager->isPluginActiveForProject($project, $pluginId)) {
            return $this->secureJson([
                'error' => $this->trans('plugin.endpoint_not_active', [], 'Plugin is not active for this project.'),
                'code'  => 'PLUGIN_NOT_ACTIVE',
            ], 422);
        }

        $content = $request->getContent();
        if (strlen($content) > self::MAX_CONFIG_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('plugin.manifest_too_large', [], 'Plugin manifest exceeds size limit (64KB).'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        $body = [];
        if (trim($content) !== '') {
            $body = json_decode($content, true);
            if (!is_array($body)) {
                return $this->secureJson([
                    'error' => $this->trans('plugin.invalid_json', [], 'Plugin manifest must be a valid JSON object.'),
                    'code'  => 'INVALID_JSON',
                ], 400);
            }
        }

        $rawMethod = strtoupper((string) ($body['method'] ?? 'POST'));
        if (!in_array($rawMethod, ['GET', 'POST', 'PUT', 'HEAD'], true)) {
            return $this->secureJson([
                'error' => $this->trans('plugin.endpoint_invalid_method', [], 'HTTP method not allowed for plugin call (only GET, POST, PUT, HEAD are supported).'),
                'code'  => 'INVALID_METHOD',
            ], 400);
        }

        $targetUrl = trim((string) ($body['url'] ?? ''));
        if ($targetUrl === '') {
            $settings = $this->pluginManager->getProjectPluginSettings($project, $pluginId);
            $targetUrl = trim((string) ($settings['webhook_url'] ?? $settings['endpoint'] ?? $settings['url'] ?? ''));
        }

        if ($targetUrl === '') {
            return $this->secureJson([
                'error' => $this->trans('plugin.endpoint_url_required', [], 'Endpoint or webhook URL is required.'),
                'code'  => 'URL_REQUIRED',
            ], 400);
        }

        if (preg_match('/^(?:file|phar|php|gopher|dict|expect)\s*:/i', $targetUrl)) {
            return $this->secureJson([
                'error' => $this->trans('plugin.settings_unsafe_protocol', [], 'Unsafe or prohibited URL protocol detected in settings.'),
                'code'  => 'UNSAFE_PROTOCOL',
            ], 422);
        }

        if ($this->ssrfProtection !== null) {
            $reason = null;
            if (!$this->ssrfProtection->isSafeUrl($targetUrl, false, $reason)) {
                $this->auditService?->log(
                    toolName: 'project_plugin_test_endpoint',
                    project: $project,
                    input: ['plugin_id' => $pluginId, 'url' => $targetUrl],
                    output: ['error' => 'ssrf_blocked', 'reason' => $reason],
                    status: 'blocked',
                    createdBy: $this->getUserIdentifier($request),
                    source: 'project_plugin_controller',
                );
                $this->auditService?->flush();

                return $this->secureJson([
                    'error' => $this->trans('plugin.settings_ssrf_blocked', [], 'Unauthorized URL: private network, loopback or cloud metadata target blocked.'),
                    'code'  => 'SSRF_BLOCKED',
                ], 422);
            }
        }

        $reqBody = $body['body'] ?? $body['payload'] ?? null;
        $encodedReqBody = is_array($reqBody) ? json_encode($reqBody) : (string) ($reqBody ?? '');
        if (strlen((string) $encodedReqBody) > 32768) {
            return $this->secureJson([
                'error' => $this->trans('plugin.endpoint_payload_too_large', [], 'Plugin request body exceeds maximum allowed size.'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        $startTime = microtime(true);
        $userIdentifier = $this->getUserIdentifier($request);

        if ($this->httpClient !== null) {
            try {
                $options = [
                    'max_redirects' => 0,
                    'timeout'       => 10,
                    'max_duration'  => 15,
                    'headers'       => [
                        'User-Agent' => 'Jambo-Plugin-Dispatcher/1.0',
                        'Accept'     => 'application/json, text/plain, */*',
                    ],
                ];

                if ($rawMethod !== 'GET' && $rawMethod !== 'HEAD' && $encodedReqBody !== '') {
                    if (is_array($reqBody)) {
                        $options['json'] = $reqBody;
                    } else {
                        $options['body'] = $encodedReqBody;
                        $options['headers']['Content-Type'] = 'application/json';
                    }
                }

                $response = $this->httpClient->request($rawMethod, $targetUrl, $options);
                $statusCode = $response->getStatusCode();
                $respBody = $this->ssrfProtection !== null
                    ? $this->ssrfProtection->readSafeResponseBody($response, 65536)
                    : substr($response->getContent(false), 0, 65536);
                $durationMs = (int) ((microtime(true) - $startTime) * 1000);

                $this->auditService?->log(
                    toolName: 'project_plugin_test_endpoint',
                    project: $project,
                    input: ['plugin_id' => $pluginId, 'method' => $rawMethod, 'url' => $targetUrl],
                    output: ['status_code' => $statusCode, 'duration_ms' => $durationMs],
                    status: $statusCode < 400 ? 'success' : 'failed',
                    createdBy: $userIdentifier,
                    source: 'project_plugin_controller',
                );
                $this->auditService?->flush();

                return $this->secureJson([
                    'success'     => $statusCode < 400,
                    'status_code' => $statusCode,
                    'duration_ms' => $durationMs,
                    'response'    => $respBody,
                ]);
            } catch (TransportExceptionInterface $e) {
                $this->auditService?->log(
                    toolName: 'project_plugin_test_endpoint',
                    project: $project,
                    input: ['plugin_id' => $pluginId, 'method' => $rawMethod, 'url' => $targetUrl],
                    output: ['error' => $e->getMessage()],
                    status: 'failed',
                    createdBy: $userIdentifier,
                    source: 'project_plugin_controller',
                );
                $this->auditService?->flush();

                return $this->secureJson([
                    'error' => $this->trans('plugin.endpoint_call_timeout', [], 'External endpoint call timed out.'),
                    'code'  => 'TIMEOUT',
                ], 504);
            } catch (\Throwable $e) {
                $this->auditService?->log(
                    toolName: 'project_plugin_test_endpoint',
                    project: $project,
                    input: ['plugin_id' => $pluginId, 'method' => $rawMethod, 'url' => $targetUrl],
                    output: ['error' => $e->getMessage()],
                    status: 'failed',
                    createdBy: $userIdentifier,
                    source: 'project_plugin_controller',
                );
                $this->auditService?->flush();

                return $this->secureJson([
                    'error' => $this->trans('plugin.endpoint_call_failed', [], 'Failed to call external plugin endpoint.'),
                    'code'  => 'DISPATCH_FAILED',
                ], 502);
            }
        }

        $durationMs = (int) ((microtime(true) - $startTime) * 1000);
        $this->auditService?->log(
            toolName: 'project_plugin_test_endpoint',
            project: $project,
            input: ['plugin_id' => $pluginId, 'method' => $rawMethod, 'url' => $targetUrl],
            output: ['status_code' => 200, 'duration_ms' => $durationMs],
            status: 'success',
            createdBy: $userIdentifier,
            source: 'project_plugin_controller',
        );
        $this->auditService?->flush();

        return $this->secureJson([
            'success'     => true,
            'status_code' => 200,
            'duration_ms' => $durationMs,
            'response'    => '{"status":"simulated_ok"}',
        ]);
    }
}
