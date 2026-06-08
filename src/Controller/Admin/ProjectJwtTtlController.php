<?php

namespace App\Controller\Admin;

use App\Entity\Project;
use App\Service\EndUserJwtService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
class ProjectJwtTtlController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/api/admin/projects/{uuid}/jwt-ttl', name: 'admin_project_jwt_ttl_get', methods: ['GET'])]
    public function getJwtTtl(string $uuid): JsonResponse
    {
        $project = $this->findProject($uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        return new JsonResponse([
            'jwt_access_ttl'  => $project->jwtAccessTtl,
            'jwt_refresh_ttl' => $project->jwtRefreshTtl,
            'defaults' => [
                'access_ttl'  => EndUserJwtService::DEFAULT_ACCESS_TTL,
                'refresh_ttl' => EndUserJwtService::DEFAULT_REFRESH_TTL,
                'max_ttl'     => EndUserJwtService::MAX_TTL,
            ],
        ]);
    }

    #[Route('/api/admin/projects/{uuid}/jwt-ttl', name: 'admin_project_jwt_ttl_update', methods: ['PATCH'])]
    public function updateJwtTtl(string $uuid, Request $request): JsonResponse
    {
        $project = $this->findProject($uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        try {
            $data = $request->toArray();
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'Invalid JSON'], 400);
        }

        $accessVal  = $data['jwt_access_ttl'] ?? null;
        $refreshVal = $data['jwt_refresh_ttl'] ?? null;

        if ($accessVal !== null) {
            $result = $this->validateAndApplyTtl($accessVal, 'access');
            if ($result instanceof JsonResponse) {
                return $result;
            }
            $project->jwtAccessTtl = $result;
        }
        if ($refreshVal !== null) {
            $result = $this->validateAndApplyTtl($refreshVal, 'refresh');
            if ($result instanceof JsonResponse) {
                return $result;
            }
            $project->jwtRefreshTtl = $result;
        }

        // Cross-check: refresh TTL must be >= access TTL
        $finalAccess  = EndUserJwtService::resolveTtl($project->jwtAccessTtl, EndUserJwtService::DEFAULT_ACCESS_TTL);
        $finalRefresh = EndUserJwtService::resolveTtl($project->jwtRefreshTtl, EndUserJwtService::DEFAULT_REFRESH_TTL);
        if ($finalRefresh < $finalAccess) {
            return new JsonResponse([
                'error' => sprintf(
                    'jwt_refresh_ttl (%d s) must be >= jwt_access_ttl (%d s).',
                    $finalRefresh, $finalAccess,
                ),
            ], 422);
        }

        $this->em->flush();

        return $this->getJwtTtl($uuid);
    }

    private function findProject(string $uuid): Project|JsonResponse
    {
        $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        if ($project === null) {
            return new JsonResponse(['error' => 'Project not found'], 404);
        }
        $this->denyAccessUnlessGranted('project.manage', $project);
        return $project;
    }

    private function validateAndApplyTtl(mixed $val, string $label): JsonResponse|int|null
    {
        if ($val === null || $val === '' || $val === '0' || $val === 0) {
            return null;
        }

        $ttl = (int) $val;
        if ($ttl === 0) {
            return null;
        }

        $labelName = $label === 'access' ? 'access' : 'refresh';

        if ($ttl < 60) {
            return new JsonResponse([
                'error' => sprintf('jwt_%s_ttl must be at least 60 seconds.', $labelName),
            ], 422);
        }

        if ($ttl > EndUserJwtService::MAX_TTL) {
            return new JsonResponse([
                'error' => sprintf(
                    'jwt_%s_ttl must not exceed %d seconds (1 year).',
                    $labelName, EndUserJwtService::MAX_TTL,
                ),
            ], 422);
        }

        return $ttl;
    }
}
