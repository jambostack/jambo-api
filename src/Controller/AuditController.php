<?php

namespace App\Controller;

use App\Entity\Project;
use App\Repository\AuditLogRepository;
use App\Service\ApiTokenChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

class AuditController extends AbstractController
{
    public const MAX_PAGE_LIMIT = 100;
    public const MAX_OFFSET = 10000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogRepository $auditRepo,
        private readonly ?ApiTokenChecker $tokenChecker = null,
        private readonly ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters);
        }
        if ($default !== null) {
            return strtr($default, $parameters);
        }
        return $id;
    }

    #[Route('/api/projects/{uuid}/audit-logs', name: 'audit_logs', methods: ['GET'])]
    public function list(string $uuid, Request $request): JsonResponse
    {
        if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $uuid)) {
            return $this->json(['error' => $this->trans('audit.project_not_found', [], 'Project not found.')], 404);
        }

        try {
            $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('audit.project_not_found', [], 'Project not found.')], 404);
        }
        if (!$project) {
            return $this->json(['error' => $this->trans('audit.project_not_found', [], 'Project not found.')], 404);
        }

        // Cloisonnement strict multi-tenant
        $attrProject = $request->attributes->get('_project');
        if ($attrProject instanceof Project && $attrProject->uuid?->toRfc4122() !== $project->uuid?->toRfc4122()) {
            return $this->json(['error' => $this->trans('audit.token_project_mismatch', [], 'Forbidden: token belongs to another project.')], 403);
        }

        $token = $this->tokenChecker?->resolve($request);
        if ($token !== null) {
            if ($token->project->uuid?->toRfc4122() !== $project->uuid?->toRfc4122()) {
                return $this->json(['error' => $this->trans('audit.token_project_mismatch', [], 'Forbidden: token belongs to another project.')], 403);
            }
            if (!$token->can('read') && !$token->can('*') && !$token->can('write')) {
                return $this->json(['error' => $this->trans('audit.token_missing_read_ability', [], 'Forbidden: token missing read ability.')], 403);
            }
        } else {
            if ($request->attributes->get('_project') !== $project) {
                $this->denyAccessUnlessGranted('project.view', $project);
            }
        }

        // Pagination bornée pour prévenir l'épuisement mémoire et le déni de service
        $limit = min(max(1, (int) $request->query->get('limit', 20)), self::MAX_PAGE_LIMIT);
        $offset = min(max(0, (int) $request->query->get('offset', 0)), self::MAX_OFFSET);

        $logs = array_map(fn($l) => [
            'uuid' => $l->uuid->toRfc4122(),
            'toolName' => $l->toolName,
            'status' => $l->status,
            'errorMessage' => $l->errorMessage,
            'createdBy' => $l->createdBy,
            'source' => $l->source,
            'durationMs' => $l->durationMs,
            'createdAt' => $l->createdAt->format(\DateTimeInterface::ATOM),
            'input' => $l->input,
            'output' => $l->output,
        ], $this->auditRepo->findByProject($project, $limit, $offset));

        $totalToday = $this->auditRepo->countByProjectToday($project);

        return $this->json(['logs' => $logs, 'total_today' => $totalToday]);
    }

    #[Route('/api/admin/audit-logs/errors', name: 'audit_errors', methods: ['GET'])]
    public function errors(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $limit = min(max(1, (int) $request->query->get('limit', 50)), self::MAX_PAGE_LIMIT);

        $logs = array_map(fn($l) => [
            'uuid' => $l->uuid->toRfc4122(),
            'toolName' => $l->toolName,
            'errorMessage' => $l->errorMessage,
            'source' => $l->source,
            'createdAt' => $l->createdAt->format(\DateTimeInterface::ATOM),
        ], $this->auditRepo->findErrors($limit));

        return $this->json($logs);
    }
}
