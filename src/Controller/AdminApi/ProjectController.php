<?php

namespace App\Controller\AdminApi;

use App\Exception\SchemaException;
use App\Repository\ProjectRepository;
use App\Security\ProjectVoter;
use App\Service\SchemaProvisioner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin-api/projects', name: 'admin_api_project_')]
class ProjectController extends AbstractController
{
    use AdminApiControllerTrait;

    public function __construct(
        private ProjectRepository $projects,
        private SchemaProvisioner $provisioner,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $projects = $this->projects->findByMember($this->getUser());
        return $this->json(['data' => array_map($this->serialize(...), $projects)]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->requireScope($request, 'projects:write');
        try {
            $project = $this->provisioner->createProject($this->getUser(), $request->toArray());
        } catch (SchemaException $e) {
            return $this->json(['error' => $e->getMessage()], $e->statusCode());
        }
        return $this->json(['data' => $this->serialize($project)], 201);
    }

    #[Route('/{uuid}', name: 'show', methods: ['GET'])]
    public function show(string $uuid): JsonResponse
    {
        $project = $this->projects->findOneBy(['uuid' => $uuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }
        // VIEW = appartenance active au projet (bypass super-admin), aligné sur le studio.
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);
        return $this->json(['data' => $this->serialize($project)]);
    }

    #[Route('/{uuid}', name: 'update', methods: ['PATCH', 'PUT'])]
    public function update(string $uuid, Request $request): JsonResponse
    {
        $this->requireScope($request, 'projects:write');
        $project = $this->projects->findOneBy(['uuid' => $uuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);
        $this->provisioner->updateProject($project, $request->toArray());
        return $this->json(['data' => $this->serialize($project)]);
    }

    #[Route('/{uuid}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $uuid, Request $request): JsonResponse
    {
        $this->requireScope($request, 'projects:write');
        $project = $this->projects->findOneBy(['uuid' => $uuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);
        $this->provisioner->deleteProject($project);
        return new JsonResponse(null, 204);
    }

    private function serialize($p): array
    {
        return [
            'uuid' => $p->uuid->toString(),
            'name' => $p->name,
            'defaultLocale' => $p->defaultLocale,
            'locales' => $p->locales,
            'publicApi' => $p->publicApi,
        ];
    }
}
