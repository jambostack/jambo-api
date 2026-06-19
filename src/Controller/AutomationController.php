<?php

namespace App\Controller;

use App\Entity\Automation;
use App\Repository\AutomationRepository;
use App\Repository\AutomationRunRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Service\Automation\AutomationEngine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/projects/{projectUuid}/automations', name: 'api_automation_')]
class AutomationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectRepository $projectRepository,
        private readonly AutomationRepository $automationRepo,
        private readonly AutomationRunRepository $runRepo,
        private readonly ProjectMemberRepository $memberRepo,
        private readonly AutomationEngine $engine,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $projectUuid): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if (!$project) return $this->json(['error' => 'Project not found'], 404);
        $this->denyProjectAccess($project);

        $automations = $this->automationRepo->findByProject($project);

        return $this->json([
            'data' => array_map(fn (Automation $a) => $this->serialize($a), $automations),
        ]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if (!$project) return $this->json(['error' => 'Project not found'], 404);
        $this->denyProjectAccess($project);

        $data = $request->toArray();

        $automation = new Automation();
        $automation->project       = $project;
        $automation->name          = $data['name'] ?? 'Sans nom';
        $automation->triggerType   = $data['trigger_type'] ?? '';
        $automation->triggerConfig = $data['trigger_config'] ?? null;
        $automation->conditions    = $data['conditions'] ?? [];
        $automation->actionType    = $data['action_type'] ?? '';
        $automation->actionConfig  = $data['action_config'] ?? null;
        $automation->isActive      = $data['is_active'] ?? true;
        $automation->debugMode     = $data['debug_mode'] ?? false;

        $this->em->persist($automation);
        $this->em->flush();

        return $this->json(['data' => $this->serialize($automation)], 201);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(string $projectUuid, int $id): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if (!$project) return $this->json(['error' => 'Project not found'], 404);
        $this->denyProjectAccess($project);

        $automation = $this->automationRepo->findOneBy(['id' => $id, 'project' => $project]);
        if (!$automation) return $this->json(['error' => 'Automation not found'], 404);

        return $this->json(['data' => $this->serialize($automation)]);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(string $projectUuid, int $id, Request $request): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if (!$project) return $this->json(['error' => 'Project not found'], 404);
        $this->denyProjectAccess($project);

        $automation = $this->automationRepo->findOneBy(['id' => $id, 'project' => $project]);
        if (!$automation) return $this->json(['error' => 'Automation not found'], 404);

        $data = $request->toArray();

        if (isset($data['name']))           $automation->name          = $data['name'];
        if (isset($data['trigger_type']))   $automation->triggerType   = $data['trigger_type'];
        if (isset($data['trigger_config'])) $automation->triggerConfig = $data['trigger_config'];
        if (isset($data['conditions']))     $automation->conditions    = $data['conditions'];
        if (isset($data['action_type']))    $automation->actionType    = $data['action_type'];
        if (isset($data['action_config']))  $automation->actionConfig  = $data['action_config'];
        if (isset($data['is_active']))      $automation->isActive      = $data['is_active'];
        if (isset($data['debug_mode']))     $automation->debugMode     = $data['debug_mode'];

        $this->em->flush();

        return $this->json(['data' => $this->serialize($automation)]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $projectUuid, int $id): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if (!$project) return $this->json(['error' => 'Project not found'], 404);
        $this->denyProjectAccess($project);

        $automation = $this->automationRepo->findOneBy(['id' => $id, 'project' => $project]);
        if (!$automation) return $this->json(['error' => 'Automation not found'], 404);

        $this->em->remove($automation);
        $this->em->flush();

        return $this->json(null, 204);
    }

    /** Dry-run : teste l'automatisation avec un payload simulé */
    #[Route('/{id}/test', name: 'test', methods: ['POST'])]
    public function test(string $projectUuid, int $id): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if (!$project) return $this->json(['error' => 'Project not found'], 404);
        $this->denyProjectAccess($project);

        $automation = $this->automationRepo->findOneBy(['id' => $id, 'project' => $project]);
        if (!$automation) return $this->json(['error' => 'Automation not found'], 404);

        // Payload simulé
        $payload = [
            'trigger'      => $automation->triggerType,
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

        $conditionsPass = $this->engine->evaluateConditions($automation->conditions, $payload);
        $resolvedConfig = $this->engine->resolveTemplates($automation->actionConfig, $payload);

        // Résultats par condition
        $conditionDetail = [];
        if ($automation->conditions) {
            foreach ($automation->conditions as $cond) {
                $actual = $this->engine->getNestedValue($payload, $cond['field'] ?? '');
                $result = $this->engine->evaluateSingle($actual, $cond['operator'] ?? 'eq', $cond['value'] ?? null);
                $conditionDetail[] = ['field' => $cond['field'], 'operator' => $cond['operator'], 'value' => $cond['value'], 'actual' => $actual, 'pass' => $result];
            }
        }

        return $this->json([
            'conditions_pass'  => $conditionsPass,
            'condition_detail' => $conditionDetail,
            'action_config'    => $resolvedConfig,
        ]);
    }

    #[Route('/{id}/runs', name: 'runs', methods: ['GET'])]
    public function runs(string $projectUuid, int $id, Request $request): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if (!$project) return $this->json(['error' => 'Project not found'], 404);
        $this->denyProjectAccess($project);

        $automation = $this->automationRepo->findOneBy(['id' => $id, 'project' => $project]);
        if (!$automation) return $this->json(['error' => 'Automation not found'], 404);

        $page    = max(1, (int) $request->query->get('page', 1));
        $perPage = min(100, max(1, (int) $request->query->get('per_page', 20)));

        $runs  = $this->runRepo->findByAutomationPaginated($automation, $page, $perPage);
        $total = $this->runRepo->countByAutomation($automation);

        return $this->json([
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

    private function denyProjectAccess(\App\Entity\Project $project): void
    {
        $user = $this->getUser();
        if (!$user) throw $this->createAccessDeniedException('Authentication required');
        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) return;
        if ($this->memberRepo->findActiveByUserAndProject($user, $project) === null) {
            throw $this->createAccessDeniedException();
        }
    }

    private function serialize(Automation $a): array
    {
        return [
            'id'             => $a->id,
            'uuid'          => $a->uuid?->toRfc4122(),
            'name'          => $a->name,
            'is_active'     => $a->isActive,
            'debug_mode'    => $a->debugMode,
            'trigger_type'  => $a->triggerType,
            'trigger_config' => $a->triggerConfig,
            'conditions'    => $a->conditions,
            'action_type'   => $a->actionType,
            'action_config' => $a->actionConfig,
            'last_run_at'   => $a->lastRunAt?->format('c'),
            'created_at'    => $a->createdAt->format('c'),
            'updated_at'    => $a->updatedAt->format('c'),
        ];
    }
}
