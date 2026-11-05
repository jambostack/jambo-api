<?php

namespace App\Controller;

use App\Entity\Automation;
use App\Repository\AutomationRepository;
use App\Repository\AutomationRunRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Service\Automation\AutomationEngine;
use App\Service\Automation\AutomationExportImportService;
use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowInterpreter;
use App\Service\Flow\FlowValidator;
use App\Service\Flow\NodeOutput;
use App\Service\Flow\NodeRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/projects/{projectUuid}/automations', name: 'api_automation_')]
class AutomationController extends AbstractController
{
    private readonly FlowValidator $validator;
    private readonly AutomationExportImportService $exportImport;

    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-store',
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
    ) {
        $this->validator = $flowValidator ?? new FlowValidator();
        $this->exportImport = $exportImportService ?? new AutomationExportImportService($em, $automationRepo, $this->validator, $nodeRegistry);
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

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if (!$project) return $this->secureJson(['error' => $this->trans('automation.admin.project_not_found', [], 'Project not found')], 404);
        $this->denyProjectAccess($project, $request);

        $automations = $this->automationRepo->findByProject($project);

        return $this->secureJson([
            'data' => array_map(fn (Automation $a) => $this->serialize($a), $automations),
        ]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if (!$project) return $this->secureJson(['error' => $this->trans('automation.admin.project_not_found', [], 'Project not found')], 404);
        $this->denyProjectAccess($project, $request, 'project.manage');

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('automation.admin.invalid_json', [], 'Invalid JSON')], 400);
        }

        $flowGraph = $data['flow_graph'] ?? null;
        if (!empty($flowGraph)) {
            $validation = $this->validator->validate($flowGraph, $this->nodeRegistry);
            if (!$validation['valid']) {
                return $this->secureJson(['error' => $this->trans('automation.admin.invalid_flow_graph', [], 'Invalid flow graph'), 'details' => $validation['errors']], 422);
            }
        }

        $rawName = isset($data['name']) ? trim((string) $data['name']) : '';
        if (mb_strlen($rawName) > 255) {
            return $this->secureJson(['error' => $this->trans('automation.admin.name_too_long', [], 'Automation name cannot exceed 255 characters.')], 422);
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

        return $this->secureJson(['data' => $this->serialize($automation)], 201);
    }

    #[Route('/export-all', name: 'export_all', methods: ['GET'], priority: 10)]
    public function exportAll(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if (!$project) return $this->secureJson(['error' => $this->trans('automation.admin.project_not_found', [], 'Project not found')], 404);
        $this->denyProjectAccess($project, $request);

        $bundle = $this->exportImport->exportProjectAutomations($project);
        return $this->secureJson($bundle);
    }

    #[Route('/import', name: 'import', methods: ['POST'], priority: 10)]
    public function import(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if (!$project) return $this->secureJson(['error' => $this->trans('automation.admin.project_not_found', [], 'Project not found')], 404);
        $this->denyProjectAccess($project, $request, 'project.manage');

        $content = $request->getContent();
        if (strlen($content) > AutomationExportImportService::MAX_PAYLOAD_SIZE) {
            return $this->secureJson(['error' => $this->trans('automation.admin.payload_too_large', [], 'Payload exceeds maximum size limit (2MB)')], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('automation.admin.invalid_json', [], 'Invalid JSON body')], 400);
        }

        try {
            if (isset($data['automations'])) {
                $imported = $this->exportImport->importProjectAutomations($project, $data);
                return $this->secureJson([
                    'message' => $this->trans('automation.admin.imported_multiple_success', ['%count%' => count($imported)], sprintf('%d automation(s) imported successfully.', count($imported))),
                    'data'    => array_map(fn (Automation $a) => $this->serialize($a), $imported),
                ], 201);
            }

            $automation = $this->exportImport->importAutomation($project, $data);
            return $this->secureJson([
                'message' => $this->trans('automation.admin.imported_single_success', [], 'Automation imported successfully.'),
                'data'    => $this->serialize($automation),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return $this->secureJson(['error' => $e->getMessage()], 422);
        }
    }

    #[Route('/{id}/export', name: 'export_single', methods: ['GET'], priority: 10, requirements: ['id' => '\d+'])]
    public function export(string $projectUuid, int $id, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if (!$project) return $this->secureJson(['error' => $this->trans('automation.admin.project_not_found', [], 'Project not found')], 404);
        $this->denyProjectAccess($project, $request);

        $automation = $this->automationRepo->findOneBy(['id' => $id, 'project' => $project]);
        if (!$automation) return $this->secureJson(['error' => $this->trans('automation.admin.not_found', [], 'Automation not found')], 404);

        $export = $this->exportImport->exportAutomation($automation);
        return $this->secureJson($export);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(string $projectUuid, int $id, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if (!$project) return $this->secureJson(['error' => $this->trans('automation.admin.project_not_found', [], 'Project not found')], 404);
        $this->denyProjectAccess($project, $request);

        $automation = $this->automationRepo->findOneBy(['id' => $id, 'project' => $project]);
        if (!$automation) return $this->secureJson(['error' => $this->trans('automation.admin.not_found', [], 'Automation not found')], 404);

        return $this->secureJson(['data' => $this->serialize($automation)]);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'], requirements: ['id' => '\d+'])]
    public function update(string $projectUuid, int $id, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if (!$project) return $this->secureJson(['error' => $this->trans('automation.admin.project_not_found', [], 'Project not found')], 404);
        $this->denyProjectAccess($project, $request, 'project.manage');

        $automation = $this->automationRepo->findOneBy(['id' => $id, 'project' => $project]);
        if (!$automation) return $this->secureJson(['error' => $this->trans('automation.admin.not_found', [], 'Automation not found')], 404);

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('automation.admin.invalid_json', [], 'Invalid JSON')], 400);
        }

        if (array_key_exists('name', $data)) {
            $rawName = trim((string) $data['name']);
            if (mb_strlen($rawName) > 255) {
                return $this->secureJson(['error' => $this->trans('automation.admin.name_too_long', [], 'Automation name cannot exceed 255 characters.')], 422);
            }
            $automation->name = $rawName !== '' ? $rawName : $this->trans('automation.admin.default_name', [], 'Sans nom');
        }
        if (isset($data['flow_graph'])) {
            $flowGraph = $data['flow_graph'];
            if (!empty($flowGraph)) {
                $validation = $this->validator->validate($flowGraph, $this->nodeRegistry);
                if (!$validation['valid']) {
                    return $this->secureJson(['error' => $this->trans('automation.admin.invalid_flow_graph', [], 'Invalid flow graph'), 'details' => $validation['errors']], 422);
                }
            }
            $automation->flowGraph  = $flowGraph;
        }
        if (isset($data['is_active']))   $automation->isActive   = (bool) $data['is_active'];
        if (isset($data['debug_mode']))  $automation->debugMode  = (bool) $data['debug_mode'];

        $this->em->flush();

        return $this->secureJson(['data' => $this->serialize($automation)]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(string $projectUuid, int $id, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if (!$project) return $this->secureJson(['error' => $this->trans('automation.admin.project_not_found', [], 'Project not found')], 404);
        $this->denyProjectAccess($project, $request, 'project.manage');

        $automation = $this->automationRepo->findOneBy(['id' => $id, 'project' => $project]);
        if (!$automation) return $this->secureJson(['error' => $this->trans('automation.admin.not_found', [], 'Automation not found')], 404);

        $this->em->remove($automation);
        $this->em->flush();

        return $this->secureJson(null, 204);
    }

    /** Dry-run : exécute le flow en mode debug avec un payload simulé */
    #[Route('/{id}/test', name: 'test', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function test(string $projectUuid, int $id, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if (!$project) return $this->secureJson(['error' => $this->trans('automation.admin.project_not_found', [], 'Project not found')], 404);
        $this->denyProjectAccess($project, $request, 'project.manage');

        $automation = $this->automationRepo->findOneBy(['id' => $id, 'project' => $project]);
        if (!$automation) return $this->secureJson(['error' => $this->trans('automation.admin.not_found', [], 'Automation not found')], 404);

        // Payload simulé
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
            return $this->secureJson(['error' => $this->trans('automation.admin.no_flow_graph', [], 'Automation has no flow graph')], 400);
        }

        $ctx = new FlowContext(
            automationId: $automation->id,
            projectUuid: $projectUuid,
            debugMode: true,
        );

        $result = $this->flowInterpreter->executeFlow($graph, $payload, $ctx);

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
        $project = $this->resolveProject($projectUuid);
        if (!$project) return $this->secureJson(['error' => $this->trans('automation.admin.project_not_found', [], 'Project not found')], 404);
        $this->denyProjectAccess($project, $request, 'project.manage');

        $automation = $this->automationRepo->findOneBy(['id' => $id, 'project' => $project]);
        if (!$automation) return $this->secureJson(['error' => $this->trans('automation.admin.not_found', [], 'Automation not found')], 404);

        if (!$automation->isActive) return $this->secureJson(['error' => $this->trans('automation.admin.not_active', [], 'Automation is not active')], 400);

        $payload = [
            'project_uuid' => $projectUuid,
            'timestamp'    => time(),
        ];

        try {
            $run = $this->engine->execute($automation, $payload);

            return $this->secureJson([
                'success' => $run->status !== 'failed',
                'status' => $run->status,
                'run_id' => $run->id,
                'duration_ms' => $run->durationMs,
                'error' => $run->errorMessage,
                'action_output' => $run->actionOutput,
            ]);
        } catch (\Throwable) {
            return $this->secureJson([
                'success' => false,
                'error' => $this->trans('automation.admin.execution_failed', [], 'Execution failed'),
            ], 500);
        }
    }

    /** Dry-run : exécute le flow avec un payload fourni par l'utilisateur */
    #[Route('/{id}/dry-run', name: 'dry_run', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function dryRun(string $projectUuid, int $id, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if (!$project) return $this->secureJson(['error' => $this->trans('automation.admin.project_not_found', [], 'Project not found')], 404);
        $this->denyProjectAccess($project, $request, 'project.manage');

        $automation = $this->automationRepo->findOneBy(['id' => $id, 'project' => $project]);
        if (!$automation) return $this->secureJson(['error' => $this->trans('automation.admin.not_found', [], 'Automation not found')], 404);

        $graph = $automation->flowGraph;
        if (!$graph) return $this->secureJson(['error' => $this->trans('automation.admin.no_flow_graph', [], 'Automation has no flow graph')], 400);

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('automation.admin.invalid_json', [], 'Invalid JSON')], 400);
        }

        $payload = $data['payload'] ?? [];
        if (!is_array($payload)) {
            return $this->secureJson(['error' => $this->trans('automation.admin.payload_must_be_object', [], 'Payload must be a valid JSON object or array.')], 422);
        }

        $ctx = new FlowContext(
            automationId: $automation->id,
            projectUuid: $projectUuid,
            debugMode: true,
        );

        $result = $this->flowInterpreter->executeFlow($graph, $payload, $ctx);

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
        $project = $this->resolveProject($projectUuid);
        if (!$project) return $this->secureJson(['error' => $this->trans('automation.admin.project_not_found', [], 'Project not found')], 404);
        $this->denyProjectAccess($project, $request, 'project.manage');

        $automation = $this->automationRepo->findOneBy(['id' => $id, 'project' => $project]);
        if (!$automation) return $this->secureJson(['error' => $this->trans('automation.admin.not_found', [], 'Automation not found')], 404);

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('automation.admin.invalid_json', [], 'Invalid JSON')], 400);
        }

        $nodeId   = (string) ($data['node_id'] ?? '');
        $nodeType = (string) ($data['node_type'] ?? '');
        $config   = is_array($data['config'] ?? null) ? $data['config'] : [];
        $input    = is_array($data['input'] ?? null) ? $data['input'] : [];

        if ($nodeType === '') {
            return $this->secureJson(['error' => $this->trans('automation.admin.node_type_required', [], 'Node type is required.')], 422);
        }

        $handler = $this->nodeRegistry->resolve($nodeType);
        if (!$handler) return $this->secureJson(['error' => $this->trans('automation.admin.node_type_not_found', ['%type%' => $nodeType], "Node type '{$nodeType}' not found")], 404);

        $ctx = new FlowContext($automation->id, $projectUuid, true);
        $ctx->variables['_node_config'] = $config;

        $nodeInput = ['test' => new NodeOutput(data: $input)];
        $output = $handler->execute($nodeInput, $ctx);

        return $this->secureJson([
            'success' => !isset($output->data['error']),
            'output'  => $output->data,
        ]);
    }

    #[Route('/{id}/runs', name: 'runs', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function runs(string $projectUuid, int $id, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if (!$project) return $this->secureJson(['error' => $this->trans('automation.admin.project_not_found', [], 'Project not found')], 404);
        $this->denyProjectAccess($project, $request);

        $automation = $this->automationRepo->findOneBy(['id' => $id, 'project' => $project]);
        if (!$automation) return $this->secureJson(['error' => $this->trans('automation.admin.not_found', [], 'Automation not found')], 404);

        $page    = max(1, (int) $request->query->get('page', 1));
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

    // ─── Private ─────────────────────────────────────────────────────────

    private function resolveProject(string $projectUuid): ?\App\Entity\Project
    {
        try {
            return $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function denyProjectAccess(\App\Entity\Project $project, ?Request $request = null, string $attribute = 'project.view'): void
    {
        $user = $this->getUser();
        if ($user) {
            if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
                return;
            }
            $this->denyAccessUnlessGranted($attribute, $project);
            return;
        }

        if ($request !== null && $request->attributes->get('_project') === $project) {
            return;
        }

        throw $this->createAccessDeniedException($this->trans('automation.admin.auth_required', [], 'Authentication required'));
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
