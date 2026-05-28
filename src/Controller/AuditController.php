<?php

namespace App\Controller;

use App\Entity\Project;
use App\Repository\AuditLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class AuditController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private AuditLogRepository $auditRepo,
    ) {}

    #[Route('/api/projects/{uuid}/audit-logs', name: 'audit_logs', methods: ['GET'])]
    public function list(string $uuid, Request $request): JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if (!$project) return $this->json(['error' => 'Projet introuvable'], 404);

        $this->denyAccessUnlessGranted('project.view', $project);

        $limit = min((int) $request->query->get('limit', 100), 500);
        $offset = (int) $request->query->get('offset', 0);

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
    public function errors(): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN');

        $logs = array_map(fn($l) => [
            'uuid' => $l->uuid->toRfc4122(),
            'toolName' => $l->toolName,
            'errorMessage' => $l->errorMessage,
            'source' => $l->source,
            'createdAt' => $l->createdAt->format(\DateTimeInterface::ATOM),
        ], $this->auditRepo->findErrors(50));

        return $this->json($logs);
    }
}
