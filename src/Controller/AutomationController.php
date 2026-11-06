<?php

namespace App\Controller;

use App\Entity\Automation;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\AutomationRepository;
use App\Repository\AutomationRunRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Service\ApiTokenChecker;
use App\Service\AuditService;
use App\Service\Automation\AutomationEngine;
use App\Service\Automation\AutomationExportImportService;
use App\Service\Automation\AutomationQuotaService;
use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowInterpreter;
use App\Service\Flow\FlowValidator;
use App\Service\Flow\NodeOutput;
use App\Service\Flow\NodeRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/projects/{projectUuid}/automations', name: 'api_automation_')]
class AutomationController extends AbstractController
{
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    public const MAX_PAYLOAD_BYTES = 524288; // 512 KB
    public const MAX_NODES_COUNT = 100;
    public const MAX_EDGES_COUNT = 200;

    private readonly FlowValidator $validator;
    private readonly AutomationExportImportService $exportImport;
    private readonly AutomationQuotaService $quotaService;

    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-store, no-cache, must-revalidate, private',
        'Pragma'                 => 'no-cache',
        'Referrer-Policy'        => 'no-referrer',
        'X-Robots-Tag'           => 'noindex, nofollow, noarchive, nosnippet',
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none';",
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectRepository $projectRepository,
        private readonly AutomationRepository $automationRepo,
        private readonly AutomationRunRepository $runRepo,
        private readonly ProjectMemberRepository $memberRepo,
        private readonly FlowInterpreter $flowInterpreter,
        private readonly NodeRegistry $nodeRegistry,
        private readonly AutomationEngine $engine,
        ?FlowValidator $flowValidator = null,
        ?AutomationExportImportService $exportImportService = null,
        private readonly ?TranslatorInterface $translator = null,
        #[Autowire(service: 'limiter.automation_limiter')]
        private readonly ?RateLimiterFactory $automationLimiter = null,
        #[Autowire(service: 'limiter.automation_run_limiter')]
        private readonly ?RateLimiterFactory $automationRunLimiter = null,
        private readonly ?AuditService $auditService = null,
        ?AutomationQuotaService $quotaService = null,
        private readonly ?ApiTokenChecker $tokenChecker = null,
        private readonly ?RequestStack $requestStack = null,
    ) {
        $this->validator = $flowValidator ?? new FlowValidator();
        $this->exportImport = $exportImportService ?? new AutomationExportImportService($em, $automationRepo, $this->validator, $nodeRegistry, $this->translator);
        $this->quotaService = $quotaService ?? new AutomationQuotaService($this->runRepo);
    }

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters);
        }
        return $default ?? $id;
    }

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    private function resolveIdentifier(Request $request): string
    {
        try {
            $user = $this->getUser();
            if ($user !== null) {
                return 'user_' . $user->getUserIdentifier();
            }
        } catch (\Throwable) {}

        if ($this->tokenChecker !== null) {
            try {
                $token = $this->tokenChecker->resolve($request);
                if ($token !== null && $token->project?->uuid !== null) {
                    return 'token_project_' . $token->project->uuid->toRfc4122();
                }
            } catch (\Throwable) {}
        }

        return $request->getClientIp() ?: '127.0.0.1';
    }

    private function getUserSafeIdentifier(?Request $request = null): string
    {
        try {
            $user = $this->getUser();
            if ($user !== null) {
                return $user->getUserIdentifier();
            }
        } catch (\Throwable) {}

        if ($request !== null) {
            return $this->resolveIdentifier($request);
        }

        return 'system';
    }

    private function checkRateLimit(Request $request): ?JsonResponse
    {
        if ($this->automationLimiter === null) {
            return null;
        }
        $identifier = $this->resolveIdentifier($request);

        if (false === $this->automationLimiter->create($identifier)->consume(1)->isAccepted()) {
            $this->auditService?->log(
                toolName: 'automation_rate_limit_exceeded',
                project: null,
                input: ['identifier' => $identifier],
                output: null,
                status: 'failure',
                errorMessage: 'Automation rate limit exceeded',
                createdBy: $identifier,
                source: 'automation_management'
            );
            $this->auditService?->flush();

            return $this->secureJson([
                'error' => $this->trans('automation.admin.rate_limit_exceeded', [], 'Too many automation requests. Please retry later.'),
                'code'  => 'RATE_LIMIT_EXCEEDED',
            ], 429);
        }
        return null;
    }

    private function checkExecutionRateLimit(Request $request): ?JsonResponse
    {
        $limiter = $this->automationRunLimiter ?? $this->automationLimiter;
        if ($limiter === null) {
            return null;
        }
        $identifier = $this->resolveIdentifier($request);

        if (false === $limiter->create('exec_' . $identifier)->consume(1)->isAccepted()) {
            $this->auditService?->log(
                toolName: 'automation_execution_rate_limit_exceeded',
                project: null,
                input: ['identifier' => $identifier],
                output: null,
                status: 'failure',
                errorMessage: 'Automation execution rate limit exceeded',
                createdBy: $identifier,
                source: 'automation_execution'
            );
            $this->auditService?->flush();

            return $this->secureJson([
                'error' => $this->trans('automation.admin.execution_rate_limit_exceeded', [], 'Too many automation execution requests. Please retry later.'),
                'code'  => 'RATE_LIMIT_EXCEEDED',
            ], 429);
        }
        return null;
    }

    private function validatePayloadSize(Request $request): ?JsonResponse
    {
        $contentLength = $request->headers->get('Content-Length');
        if ($contentLength !== null && is_numeric($contentLength) && (int) $contentLength > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('automation.admin.payload_too_large', [], 'Payload too large (max 512KB)'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('automation.admin.payload_too_large', [], 'Payload too large (max 512KB)'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        return null;
    }

    private function logAccessDenied(?Project $project, string $projectUuid, string $permission, string $reason, Request $request): void
    {
        $identifier = $this->resolveIdentifier($request);
        $this->auditService?->log(
            toolName: 'automation_access_denied',
            project: $project,
            input: ['project_uuid' => $projectUuid, 'permission' => $permission, 'reason' => $reason],
            output: null,
            status: 'failure',
            errorMessage: 'Access denied: ' . $reason,
            createdBy: $identifier,
            source: 'automation_management'
        );
        $this->auditService?->flush();
    }

    private function resolveProject(string $projectUuid, Request $request, string $requiredPermission = 'project.view'): Project|JsonResponse
    {
        if (!preg_match(self::UUID_REGEX, $projectUuid)) {
            return $this->secureJson([
                'error' => $this->trans('automation.admin.project_not_found', [], 'Project not found'),
                'code'  => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('automation.admin.project_not_found', [], 'Project not found'),
                'code'  => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        if ($project === null) {
            return $this->secureJson([
                'error' => $this->trans('automation.admin.project_not_found', [], 'Project not found'),
                'code'  => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        $user = null;
        try {
            $user = $this->getUser();
        } catch (\Throwable) {}

        if ($user === null) {
            if ($this->tokenChecker !== null) {
                $token = $this->tokenChecker->resolve($request);
                if ($token !== null && $token->project?->uuid?->toRfc4122() === $projectUuid) {
                    if (in_array($requiredPermission, ['project.manage', 'project.edit'], true)) {
                        if ($token->can('write') || $token->can('*') || $token->can('manage')) {
                            return $project;
                        }
                    } else {
                        if ($this->tokenChecker->isAllowedForMethod($token, $request->getMethod())) {
                            return $project;
                        }
                    }
                    $this->logAccessDenied($project, $projectUuid, $requiredPermission, 'token_insufficient_permissions', $request);
                    return $this->secureJson([
                        'error' => $this->trans('automation.admin.access_denied', [], 'Access denied to this project automations.'),
                        'code'  => 'ACCESS_DENIED',
                    ], 403);
                }
            }

            if ($request->attributes->get('_project') === $project) {
                return $project;
            }

            return $this->secureJson([
                'error' => $this->trans('automation.admin.auth_required', [], 'Authentication required to access project automations.'),
                'code'  => 'AUTH_REQUIRED',
            ], 401);
        }

        if ($user instanceof User) {
            if (!in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
                if (!$this->isGranted($requiredPermission, $project)) {
                    $this->logAccessDenied($project, $projectUuid, $requiredPermission, 'insufficient_role_permission', $request);
                    return $this->secureJson([
                        'error' => $this->trans('automation.admin.access_denied', [], 'Access denied to this project automations.'),
                        'code'  => 'ACCESS_DENIED',
                    ], 403);
                }
            }
            return $project;
        }

        return $project;
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $projectUuid, Request $request): JsonResponse
    {
        if ($rateLimit = $this->checkRateLimit($request)) {
            return $rateLimit;
        }

        $project = $this->resolveProject($projectUuid, $request, 'project.view');
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $automations = $this->automationRepo->findByProject($project);

        return $this->secureJson([
            'data' => array_map(fn (Automation $a) => $this->serialize($a), $automations),
        ]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(string $projectUuid, Request $request): JsonResponse
    {
        if ($limitRes = $this->checkRateLimit($request)) {
            return $limitRes;
        }

        if ($sizeRes = $this->validatePayloadSize($request)) {
            return $sizeRes;
        }

        $project = $this->resolveProject($projectUuid, $request, 'project.manage');
        if ($project instanceof JsonResponse) {
            return $project;
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('automation.admin.invalid_json', [], 'Invalid JSON payload.'),
                'code'  => 'INVALID_JSON',
            ], 400);
        }

        $flowGraph = $data['flow_graph'] ?? null;
        if (!empty($flowGraph)) {
            $nodes = $flowGraph['nodes'] ?? [];
            if (is_array($nodes) && count($nodes) > self::MAX_NODES_COUNT) {
                return $this->secureJson([
                    'error' => $this->trans('flow.admin.nodes_limit_exceeded', ['%max%' => self::MAX_NODES_COUNT], sprintf('Flow graph cannot contain more than %d nodes.', self::MAX_NODES_COUNT)),
                    'code'  => 'NODES_LIMIT_EXCEEDED',
                ], 422);
            }
            $edges = $flowGraph['edges'] ?? [];
            if (is_array($edges) && count($edges) > self::MAX_EDGES_COUNT) {
                return $this->secureJson([
                    'error' => $this->trans('flow.admin.edges_limit_exceeded', ['%max%' => self::MAX_EDGES_COUNT], sprintf('Flow graph cannot contain more than %d edges.', self::MAX_EDGES_COUNT)),
                    'code'  => 'EDGES_LIMIT_EXCEEDED',
                ], 422);
            }
            $validation = $this->validator->validate($flowGraph, $this->nodeRegistry);
            if (!$validation['valid']) {
                return $this->secureJson([
                    'error'   => $this->trans('automation.admin.invalid_flow_graph', [], 'Invalid flow graph'),
                    'code'    => 'INVALID_FLOW_GRAPH',
                    'details' => $validation['errors'],
                ], 422);
            }
        }

        $rawName = isset($data['name']) ? trim((string) $data['name']) : '';
        if (mb_strlen($rawName) > 255) {
            return $this->secureJson([
                'error' => $this->trans('automation.admin.name_too_long', [], 'Automation name cannot exceed 255 characters.'),
                'code'  => 'NAME_TOO_LONG',
            ], 422);
        }
        $name = $rawName !== '' ? $rawName : $this->trans('automation.admin.default_name', [], 'Sans nom');

        $automation = new Automation();
        $automation->project    = $project;
        $automation->name       = $name;
        $automation->flowGraph  = $flowGraph;
        $automation->isActive   = (bool) ($data['is_active'] ?? true);
        $automation->debugMode  = (bool) ($data['debug_mode'] ?? false);

        $this->em->persist($automation);
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'automation_create',
            project: $project,
            input: ['name' => $automation->name, 'is_active' => $automation->isActive],
            output: ['success' => true, 'id' => $automation->id, 'uuid' => $automation->uuid?->toRfc4122()],
            status: 'success',
            createdBy: $this->getUserSafeIdentifier($request),
            source: 'automation_management'
        );
        $this->auditService?->flush();

        return $this->secureJson(['data' => $this->serialize($automation)], 201);
    }

    #[Route('/export-all', name: 'export_all', methods: ['GET'], priority: 10)]
    public function exportAll(string $projectUuid, Request $request): JsonResponse
    {
        if ($rateLimit = $this->checkRateLimit($request)) {
            return $rateLimit;
        }

        $project = $this->resolveProject($projectUuid, $request, 'project.view');
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $bundle = $this->exportImport->exportProjectAutomations($project);

        $this->auditService?->log(
            toolName: 'automation_export_all',
            project: $project,
            input: ['project_uuid' => $projectUuid],
            output: ['success' => true, 'count' => count($bundle['automations'] ?? [])],
            status: 'success',
            createdBy: $this->getUserSafeIdentifier($request),
            source: 'automation_management'
        );
        $this->auditService?->flush();

        return $this->secureJson($bundle);
    }

    #[Route('/import', name: 'import', methods: ['POST'], priority: 10)]
    public function import(string $projectUuid, Request $request): JsonResponse
    {
        if ($limitRes = $this->checkRateLimit($request)) {
            return $limitRes;
        }

        $project = $this->resolveProject($projectUuid, $request, 'project.manage');
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $content = $request->getContent();
        if (strlen($content) > AutomationExportImportService::MAX_PAYLOAD_SIZE) {
            return $this->secureJson([
                'error' => $this->trans('automation.admin.payload_too_large', [], 'Payload exceeds maximum size limit (2MB)'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('automation.admin.invalid_json', [], 'Invalid JSON body'),
                'code'  => 'INVALID_JSON',
            ], 400);
        }

        try {
            if (isset($data['automations'])) {
                $imported = $this->exportImport->importProjectAutomations($project, $data);
                $this->auditService?->log(
                    toolName: 'automation_import_bundle',
                    project: $project,
                    input: ['count' => count($imported)],
                    output: ['success' => true, 'count' => count($imported)],
                    status: 'success',
                    createdBy: $this->getUserSafeIdentifier($request),
                    source: 'automation_management'
                );
                $this->auditService?->flush();
                return $this->secureJson([
                    'message' => $this->trans('automation.admin.imported_multiple_success', ['%count%' => count($imported)], sprintf('%d automation(s) imported successfully.', count($imported))),
                    'data'    => array_map(fn (Automation $a) => $this->serialize($a), $imported),
                ], 201);
            }

            $automation = $this->exportImport->importAutomation($project, $data);
            $this->auditService?->log(
                toolName: 'automation_import_single',
                project: $project,
                input: ['name' => $automation->name],
                output: ['success' => true, 'id' => $automation->id, 'uuid' => $automation->uuid?->toRfc4122()],
                status: 'success',
                createdBy: $this->getUserSafeIdentifier($request),
                source: 'automation_management'
            );
            $this->auditService?->flush();
            return $this->secureJson([
                'message' => $this->trans('automation.admin.imported_single_success', [], 'Automation imported successfully.'),
                'data'    => $this->serialize($automation),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return $this->secureJson(['error' => $e->getMessage(), 'code' => 'INVALID_PAYLOAD'], 422);
        }
    }

    #[Route('/{id}/export', name: 'export_single', methods: ['GET'], priority: 10, requirements: ['id' => '\d+'])]
    public function export(string $projectUuid, int $id, Request $request): JsonResponse
    {
        if ($rateLimit = $this->checkRateLimit($request)) {
            return $rateLimit;
        }

        $project = $this->resolveProject($projectUuid, $request, 'project.view');
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $automation = $this->automationRepo->findOneBy(['id' => $id, 'project' => $project]);
        if (!$automation) {
            return $this->secureJson(['error' => $this->trans('automation.admin.not_found', [], 'Automation not found'), 'code' => 'NOT_FOUND'], 404);
        }

        $export = $this->exportImport->exportAutomation($automation);

        $this->auditService?->log(
            toolName: 'automation_export_single',
            project: $project,
            input: ['id' => $id, 'name' => $automation->name],
            output: ['success' => true, 'uuid' => $automation->uuid?->toRfc4122()],
            status: 'success',
            createdBy: $this->getUserSafeIdentifier($request),
            source: 'automation_management'
        );
        $this->auditService?->flush();

        return $this->secureJson($export);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(string $projectUuid, int $id, Request $request): JsonResponse
    {
        if ($rateLimit = $this->checkRateLimit($request)) {
            return $rateLimit;
        }

        $project = $this->resolveProject($projectUuid, $request, 'project.view');
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $automation = $this->automationRepo->findOneBy(['id' => $id, 'project' => $project]);
        if (!$automation) {
            return $this->secureJson(['error' => $this->trans('automation.admin.not_found', [], 'Automation not found'), 'code' => 'NOT_FOUND'], 404);
        }

        return $this->secureJson(['data' => $this->serialize($automation)]);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'], requirements: ['id' => '\d+'])]
    public function update(string $projectUuid, int $id, Request $request): JsonResponse
    {
        if ($limitRes = $this->checkRateLimit($request)) {
            return $limitRes;
        }

        if ($sizeRes = $this->validatePayloadSize($request)) {
            return $sizeRes;
        }

        $project = $this->resolveProject($projectUuid, $request, 'project.manage');
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $automation = $this->automationRepo->findOneBy(['id' => $id, 'project' => $project]);
        if (!$automation) {
            return $this->secureJson(['error' => $this->trans('automation.admin.not_found', [], 'Automation not found'), 'code' => 'NOT_FOUND'], 404);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('automation.admin.invalid_json', [], 'Invalid JSON payload.'),
                'code'  => 'INVALID_JSON',
            ], 400);
        }

        if (array_key_exists('name', $data)) {
            $rawName = trim((string) $data['name']);
            if (mb_strlen($rawName) > 255) {
                return $this->secureJson([
                    'error' => $this->trans('automation.admin.name_too_long', [], 'Automation name cannot exceed 255 characters.'),
                    'code'  => 'NAME_TOO_LONG',
                ], 422);
            }
            $automation->name = $rawName !== '' ? $rawName : $this->trans('automation.admin.default_name', [], 'Sans nom');
        }
        if (isset($data['flow_graph'])) {
            $flowGraph = $data['flow_graph'];
            if (!empty($flowGraph)) {
                $nodes = $flowGraph['nodes'] ?? [];
                if (is_array($nodes) && count($nodes) > self::MAX_NODES_COUNT) {
                    return $this->secureJson([
                        'error' => $this->trans('flow.admin.nodes_limit_exceeded', ['%max%' => self::MAX_NODES_COUNT], sprintf('Flow graph cannot contain more than %d nodes.', self::MAX_NODES_COUNT)),
                        'code'  => 'NODES_LIMIT_EXCEEDED',
                    ], 422);
                }
                $edges = $flowGraph['edges'] ?? [];
                if (is_array($edges) && count($edges) > self::MAX_EDGES_COUNT) {
                    return $this->secureJson([
                        'error' => $this->trans('flow.admin.edges_limit_exceeded', ['%max%' => self::MAX_EDGES_COUNT], sprintf('Flow graph cannot contain more than %d edges.', self::MAX_EDGES_COUNT)),
                        'code'  => 'EDGES_LIMIT_EXCEEDED',
                    ], 422);
                }
                $validation = $this->validator->validate($flowGraph, $this->nodeRegistry);
                if (!$validation['valid']) {
                    return $this->secureJson([
                        'error'   => $this->trans('automation.admin.invalid_flow_graph', [], 'Invalid flow graph'),
                        'code'    => 'INVALID_FLOW_GRAPH',
                        'details' => $validation['errors'],
                    ], 422);
                }
            }
            $automation->flowGraph  = $flowGraph;
        }
        if (isset($data['is_active']))   $automation->isActive   = (bool) $data['is_active'];
        if (isset($data['debug_mode']))  $automation->debugMode  = (bool) $data['debug_mode'];

        $this->em->flush();

        $this->auditService?->log(
            toolName: 'automation_update',
            project: $project,
            input: ['id' => $automation->id, 'name' => $automation->name, 'is_active' => $automation->isActive],
            output: ['success' => true, 'id' => $automation->id],
            status: 'success',
            createdBy: $this->getUserSafeIdentifier($request),
            source: 'automation_management'
        );
        $this->auditService?->flush();

        return $this->secureJson(['data' => $this->serialize($automation)]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(string $projectUuid, int $id, Request $request): JsonResponse
    {
        if ($limitRes = $this->checkRateLimit($request)) {
            return $limitRes;
        }

        $project = $this->resolveProject($projectUuid, $request, 'project.manage');
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $automation = $this->automationRepo->findOneBy(['id' => $id, 'project' => $project]);
        if (!$automation) {
            return $this->secureJson(['error' => $this->trans('automation.admin.not_found', [], 'Automation not found'), 'code' => 'NOT_FOUND'], 404);
        }

        $autoName = $automation->name;
        $this->em->remove($automation);
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'automation_delete',
            project: $project,
            input: ['id' => $id, 'name' => $autoName],
            output: ['success' => true],
            status: 'success',
            createdBy: $this->getUserSafeIdentifier($request),
            source: 'automation_management'
        );
        $this->auditService?->flush();

        return $this->secureJson(null, 204);
    }

    /** Dry-run : exécute le flow en mode debug avec un payload simulé */
    #[Route('/{id}/test', name: 'test', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function test(string $projectUuid, int $id, Request $request): JsonResponse
    {
        if ($limitRes = $this->checkRateLimit($request) ?? $this->checkExecutionRateLimit($request)) {
            return $limitRes;
        }

        $project = $this->resolveProject($projectUuid, $request, 'project.manage');
        if ($project instanceof JsonResponse) {
            return $project;
        }

        if ($this->quotaService->isBurstLimited($project)) {
            $this->auditService?->log(
                toolName: 'automation_test_quota_exceeded',
                project: $project,
                input: ['id' => $id],
                output: null,
                status: 'failure',
                errorMessage: 'Automation test burst rate limit reached',
                createdBy: $this->getUserSafeIdentifier($request),
                source: 'automation_execution'
            );
            $this->auditService?->flush();

            return $this->secureJson([
                'success' => false,
                'error'   => $this->trans('automation.admin.execution_burst_limited', [], 'Automation execution rate limit exceeded (burst limit reached). Please wait before retrying.'),
                'code'    => 'AUTOMATION_QUOTA_EXCEEDED',
            ], 429, ['Retry-After' => '60']);
        }

        $automation = $this->automationRepo->findOneBy(['id' => $id, 'project' => $project]);
        if (!$automation) {
            return $this->secureJson(['error' => $this->trans('automation.admin.not_found', [], 'Automation not found'), 'code' => 'NOT_FOUND'], 404);
        }

        $payload = [
            'project_uuid' => $projectUuid,
            'timestamp'    => time(),
            'entry' => [
                'id' => 1, 'uuid' => '00000000-0000-0000-0000-000000000000',
                'title' => 'Entrée test', 'slug' => 'entree-test',
                'status' => 'published', 'previous_status' => 'draft',
                'collection_slug' => 'articles', 'collection_id' => 1,
                'created_at' => date('c'), 'updated_at' => date('c'),
            ],
        ];

        $graph = $automation->flowGraph;
        if (!$graph) {
            return $this->secureJson(['error' => $this->trans('automation.admin.no_flow_graph', [], 'Automation has no flow graph'), 'code' => 'NO_FLOW_GRAPH'], 400);
        }

        $ctx = new FlowContext(
            automationId: $automation->id,
            projectUuid: $projectUuid,
            debugMode: true,
        );

        $result = $this->flowInterpreter->executeFlow($graph, $payload, $ctx);

        $this->auditService?->log(
            toolName: 'automation_test',
            project: $project,
            input: ['id' => $automation->id, 'name' => $automation->name],
            output: ['status' => $result->status, 'duration_ms' => $result->totalDurationMs, 'success' => $result->status !== 'failed'],
            status: $result->status === 'failed' ? 'error' : 'success',
            createdBy: $this->getUserSafeIdentifier($request),
            source: 'automation_execution'
        );
        $this->auditService?->flush();

        return $this->secureJson([
            'success'           => $result->status !== 'failed',
            'status'            => $result->status,
            'step_log'          => $result->stepLog,
            'total_duration_ms' => $result->totalDurationMs,
            'error'             => $result->error,
        ]);
    }

    /** Exécute le flow pour de vrai (run) — persiste un AutomationRun */
    #[Route('/{id}/run', name: 'run', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function run(string $projectUuid, int $id, Request $request): JsonResponse
    {
        if ($limitRes = $this->checkRateLimit($request) ?? $this->checkExecutionRateLimit($request)) {
            return $limitRes;
        }

        $project = $this->resolveProject($projectUuid, $request, 'project.manage');
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $automation = $this->automationRepo->findOneBy(['id' => $id, 'project' => $project]);
        if (!$automation) {
            return $this->secureJson(['error' => $this->trans('automation.admin.not_found', [], 'Automation not found'), 'code' => 'NOT_FOUND'], 404);
        }

        if (!$automation->isActive) {
            return $this->secureJson(['error' => $this->trans('automation.admin.not_active', [], 'Automation is not active'), 'code' => 'AUTOMATION_INACTIVE'], 400);
        }

        $quota = $this->quotaService->checkQuota($project);
        if (!$quota['allowed']) {
            $isBurst = (bool) ($quota['isBurstLimited'] ?? false);
            $this->auditService?->log(
                toolName: 'automation_run_quota_exceeded',
                project: $project,
                input: [
                    'automation_id' => $automation->id,
                    'name' => $automation->name,
                    'tier' => $quota['tier'],
                    'used' => $quota['used'],
                    'limit' => $quota['limit'],
                    'minute_used' => $quota['minuteUsed'] ?? null,
                    'minute_limit' => $quota['minuteLimit'] ?? null,
                ],
                output: null,
                status: 'failure',
                errorMessage: $isBurst ? 'Automation run rate limit exceeded (burst)' : 'Automation run quota exceeded',
                createdBy: $this->getUserSafeIdentifier($request),
                source: 'automation_management'
            );
            $this->auditService?->flush();

            $errorMsg = $isBurst
                ? $this->trans('automation.quota.minute_limit_reached', [
                    '%used%' => (string) ($quota['minuteUsed'] ?? 0),
                    '%limit%' => (string) ($quota['minuteLimit'] ?? 0),
                ], sprintf('Automation execution rate limit exceeded (burst limit reached: %d/%d runs per minute).', $quota['minuteUsed'] ?? 0, $quota['minuteLimit'] ?? 0))
                : $this->trans('automation.quota.exceeded', [
                    '%used%' => (string) $quota['used'],
                    '%limit%' => (string) $quota['limit'],
                    '%tier%' => (string) $quota['tier'],
                ], sprintf('Automation run quota exceeded for this project tier (%d/%d runs used today).', $quota['used'], $quota['limit']));

            return $this->secureJson([
                'success' => false,
                'error' => $errorMsg,
                'code' => 'AUTOMATION_QUOTA_EXCEEDED',
                'tier' => $quota['tier'],
                'limit' => $quota['limit'],
                'used' => $quota['used'],
                'minute_limit' => $quota['minuteLimit'] ?? null,
                'minute_used' => $quota['minuteUsed'] ?? null,
                'reset_in_seconds' => $quota['resetInSeconds'],
            ], 429, [
                'Retry-After' => (string) $quota['resetInSeconds'],
            ]);
        }

        $payload = [
            'project_uuid' => $projectUuid,
            'timestamp'    => time(),
        ];

        try {
            $run = $this->engine->execute($automation, $payload);

            $this->auditService?->log(
                toolName: 'automation_run',
                project: $project,
                input: ['id' => $automation->id, 'name' => $automation->name],
                output: ['success' => $run->status !== 'failed', 'run_id' => $run->id, 'status' => $run->status],
                status: $run->status === 'failed' ? 'error' : 'success',
                createdBy: $this->getUserSafeIdentifier($request),
                source: 'automation_management'
            );
            $this->auditService?->flush();

            return $this->secureJson([
                'success' => $run->status !== 'failed',
                'status' => $run->status,
                'run_id' => $run->id,
                'duration_ms' => $run->durationMs,
                'error' => $run->errorMessage,
                'action_output' => $run->actionOutput,
            ]);
        } catch (\Throwable $e) {
            $this->auditService?->log(
                toolName: 'automation_run_exception',
                project: $project,
                input: ['id' => $automation->id, 'name' => $automation->name],
                output: null,
                status: 'failure',
                errorMessage: 'Automation run threw exception: ' . $e->getMessage(),
                createdBy: $this->getUserSafeIdentifier($request),
                source: 'automation_execution'
            );
            $this->auditService?->flush();

            return $this->secureJson([
                'success' => false,
                'error' => $this->trans('automation.admin.execution_failed', [], 'Execution failed'),
                'code'  => 'EXECUTION_FAILED',
            ], 500);
        }
    }

    /** Dry-run : exécute le flow avec un payload fourni par l'utilisateur */
    #[Route('/{id}/dry-run', name: 'dry_run', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function dryRun(string $projectUuid, int $id, Request $request): JsonResponse
    {
        if ($limitRes = $this->checkRateLimit($request) ?? $this->checkExecutionRateLimit($request)) {
            return $limitRes;
        }

        if ($sizeRes = $this->validatePayloadSize($request)) {
            return $sizeRes;
        }

        $project = $this->resolveProject($projectUuid, $request, 'project.manage');
        if ($project instanceof JsonResponse) {
            return $project;
        }

        if ($this->quotaService->isBurstLimited($project)) {
            $this->auditService?->log(
                toolName: 'automation_dry_run_quota_exceeded',
                project: $project,
                input: ['id' => $id],
                output: null,
                status: 'failure',
                errorMessage: 'Automation dry-run burst rate limit reached',
                createdBy: $this->getUserSafeIdentifier($request),
                source: 'automation_execution'
            );
            $this->auditService?->flush();

            return $this->secureJson([
                'success' => false,
                'error'   => $this->trans('automation.admin.execution_burst_limited', [], 'Automation execution rate limit exceeded (burst limit reached). Please wait before retrying.'),
                'code'    => 'AUTOMATION_QUOTA_EXCEEDED',
            ], 429, ['Retry-After' => '60']);
        }

        $automation = $this->automationRepo->findOneBy(['id' => $id, 'project' => $project]);
        if (!$automation) {
            return $this->secureJson(['error' => $this->trans('automation.admin.not_found', [], 'Automation not found'), 'code' => 'NOT_FOUND'], 404);
        }

        $graph = $automation->flowGraph;
        if (!$graph) {
            return $this->secureJson(['error' => $this->trans('automation.admin.no_flow_graph', [], 'Automation has no flow graph'), 'code' => 'NO_FLOW_GRAPH'], 400);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('automation.admin.invalid_json', [], 'Invalid JSON payload.'), 'code' => 'INVALID_JSON'], 400);
        }

        $payload = $data['payload'] ?? [];
        if (!is_array($payload)) {
            return $this->secureJson(['error' => $this->trans('automation.admin.payload_must_be_object', [], 'Payload must be a valid JSON object or array.'), 'code' => 'INVALID_PAYLOAD'], 422);
        }

        $ctx = new FlowContext(
            automationId: $automation->id,
            projectUuid: $projectUuid,
            debugMode: true,
        );

        $result = $this->flowInterpreter->executeFlow($graph, $payload, $ctx);

        $this->auditService?->log(
            toolName: 'automation_dry_run',
            project: $project,
            input: ['id' => $automation->id, 'name' => $automation->name],
            output: ['status' => $result->status, 'duration_ms' => $result->totalDurationMs, 'success' => $result->status !== 'failed'],
            status: $result->status === 'failed' ? 'error' : 'success',
            createdBy: $this->getUserSafeIdentifier($request),
            source: 'automation_execution'
        );
        $this->auditService?->flush();

        return $this->secureJson([
            'success'           => $result->status !== 'failed',
            'status'            => $result->status,
            'step_log'          => $result->stepLog,
            'total_duration_ms' => $result->totalDurationMs,
            'error'             => $result->error,
        ]);
    }

    /** Teste un node isolé */
    #[Route('/{id}/test-node', name: 'test_node', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function testNode(string $projectUuid, int $id, Request $request): JsonResponse
    {
        if ($limitRes = $this->checkRateLimit($request) ?? $this->checkExecutionRateLimit($request)) {
            return $limitRes;
        }

        if ($sizeRes = $this->validatePayloadSize($request)) {
            return $sizeRes;
        }

        $project = $this->resolveProject($projectUuid, $request, 'project.manage');
        if ($project instanceof JsonResponse) {
            return $project;
        }

        if ($this->quotaService->isBurstLimited($project)) {
            return $this->secureJson([
                'success' => false,
                'error'   => $this->trans('automation.admin.execution_burst_limited', [], 'Automation execution rate limit exceeded (burst limit reached). Please wait before retrying.'),
                'code'    => 'AUTOMATION_QUOTA_EXCEEDED',
            ], 429, ['Retry-After' => '60']);
        }

        $automation = $this->automationRepo->findOneBy(['id' => $id, 'project' => $project]);
        if (!$automation) {
            return $this->secureJson(['error' => $this->trans('automation.admin.not_found', [], 'Automation not found'), 'code' => 'NOT_FOUND'], 404);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('automation.admin.invalid_json', [], 'Invalid JSON payload.'), 'code' => 'INVALID_JSON'], 400);
        }

        $nodeId   = trim((string) ($data['node_id'] ?? ''));
        $nodeType = trim((string) ($data['node_type'] ?? ''));
        $config   = is_array($data['config'] ?? null) ? $data['config'] : [];
        $input    = is_array($data['input'] ?? null) ? $data['input'] : [];

        if ($nodeType === '') {
            return $this->secureJson(['error' => $this->trans('automation.admin.node_type_required', [], 'Node type is required.'), 'code' => 'NODE_TYPE_REQUIRED'], 422);
        }

        if (strlen($nodeType) > 64 || !preg_match('/^[a-zA-Z0-9_.-]+$/', $nodeType)) {
            return $this->secureJson(['error' => $this->trans('automation.admin.invalid_node_type', [], 'Invalid node type format.'), 'code' => 'INVALID_NODE_TYPE'], 422);
        }

        if ($nodeId !== '' && (strlen($nodeId) > 64 || !preg_match('/^[a-zA-Z0-9_\-]+$/', $nodeId))) {
            return $this->secureJson(['error' => $this->trans('automation.admin.invalid_node_id', [], 'Invalid node id format.'), 'code' => 'INVALID_NODE_ID'], 422);
        }

        $encodedConfig = json_encode($config);
        if ($encodedConfig !== false && strlen($encodedConfig) > 65536) {
            return $this->secureJson(['error' => $this->trans('automation.admin.invalid_node_config', [], 'Node configuration exceeds 64KB limit.'), 'code' => 'CONFIG_TOO_LARGE'], 422);
        }

        $encodedInput = json_encode($input);
        if ($encodedInput !== false && strlen($encodedInput) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->trans('automation.admin.invalid_node_input', [], 'Node input payload exceeds limit.'), 'code' => 'INPUT_TOO_LARGE'], 422);
        }

        $handler = $this->nodeRegistry->resolve($nodeType);
        if (!$handler) {
            return $this->secureJson(['error' => $this->trans('automation.admin.node_type_not_found', ['%type%' => $nodeType], "Node type '{$nodeType}' not found"), 'code' => 'NODE_TYPE_NOT_FOUND'], 404);
        }

        $ctx = new FlowContext($automation->id, $projectUuid, true);
        $ctx->variables['_node_config'] = $config;

        $nodeInput = ['test' => new NodeOutput(data: $input)];
        $output = $handler->execute($nodeInput, $ctx);

        $this->auditService?->log(
            toolName: 'automation_test_node',
            project: $project,
            input: ['id' => $automation->id, 'node_type' => $nodeType, 'node_id' => $nodeId],
            output: ['success' => !isset($output->data['error'])],
            status: isset($output->data['error']) ? 'error' : 'success',
            createdBy: $this->getUserSafeIdentifier($request),
            source: 'automation_execution'
        );
        $this->auditService?->flush();

        return $this->secureJson([
            'success' => !isset($output->data['error']),
            'output'  => $output->data,
        ]);
    }

    #[Route('/{id}/runs', name: 'runs', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function runs(string $projectUuid, int $id, Request $request): JsonResponse
    {
        if ($rateLimit = $this->checkRateLimit($request)) {
            return $rateLimit;
        }

        $project = $this->resolveProject($projectUuid, $request, 'project.view');
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $automation = $this->automationRepo->findOneBy(['id' => $id, 'project' => $project]);
        if (!$automation) {
            return $this->secureJson(['error' => $this->trans('automation.admin.not_found', [], 'Automation not found'), 'code' => 'NOT_FOUND'], 404);
        }

        $page    = max(1, min(10000, (int) $request->query->get('page', 1)));
        $perPage = min(100, max(1, (int) $request->query->get('per_page', 20)));

        $runs  = $this->runRepo->findByAutomationPaginated($automation, $page, $perPage);
        $total = $this->runRepo->countByAutomation($automation);

        return $this->secureJson([
            'data'         => array_map(fn ($r) => [
                'id'             => $r->id,
                'status'         => $r->status,
                'error_message'  => $r->errorMessage,
                'trigger_payload' => $r->triggerPayload,
                'condition_results' => $r->conditionResults,
                'action_input'   => $r->actionInput,
                'action_output'  => $r->actionOutput,
                'started_at'     => $r->startedAt->format('c'),
                'finished_at'    => $r->finishedAt?->format('c'),
                'duration_ms'    => $r->durationMs,
            ], $runs),
            'total'        => $total,
            'current_page' => $page,
            'last_page'    => max(1, (int) ceil($total / $perPage)),
            'per_page'     => $perPage,
        ]);
    }

    private function serialize(Automation $a): array
    {
        return [
            'id'         => $a->id,
            'uuid'       => $a->uuid?->toRfc4122(),
            'name'       => $a->name,
            'is_active'  => $a->isActive,
            'debug_mode' => $a->debugMode,
            'flow_graph' => $a->flowGraph,
            'last_run_at' => $a->lastRunAt?->format('c'),
            'created_at' => $a->createdAt->format('c'),
            'updated_at' => $a->updatedAt->format('c'),
        ];
    }
}

if (!class_exists(AutomationFlowController::class, false)) {
    class_alias(AutomationController::class, AutomationFlowController::class);
}
