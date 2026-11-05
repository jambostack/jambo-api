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
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin-api/projects', name: 'admin_api_project_')]
class ProjectController extends AbstractController
{
    use AdminApiControllerTrait;

    public function __construct(
        private ProjectRepository $projects,
        private SchemaProvisioner $provisioner,
        private ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $id, array $parameters = []): string
    {
        return $this->translator?->trans($id, $parameters) ?? $id;
    }

    private function validateUuid(string $uuid): ?JsonResponse
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid)) {
            return $this->json(['error' => $this->trans('admin_api.project.invalid_uuid')], 422);
        }
        return null;
    }

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
            $body = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('admin_api.project.invalid_json')], 400);
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return $this->json(['error' => $this->trans('admin_api.project.name_required')], 422);
        }
        if (mb_strlen($name) > 255) {
            return $this->json(['error' => $this->trans('admin_api.project.name_too_long')], 422);
        }

        try {
            $project = $this->provisioner->createProject($this->getUser(), $body);
        } catch (SchemaException $e) {
            return $this->json(['error' => $e->getMessage()], $e->statusCode());
        }
        return $this->json(['data' => $this->serialize($project)], 201);
    }

    #[Route('/{uuid}', name: 'show', methods: ['GET'])]
    public function show(string $uuid): JsonResponse
    {
        if ($err = $this->validateUuid($uuid)) {
            return $err;
        }

        try {
            $project = $this->projects->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('admin_api.project.not_found')], 404);
        }
        if (!$project) {
            return $this->json(['error' => $this->trans('admin_api.project.not_found')], 404);
        }
        // VIEW = appartenance active au projet (bypass super-admin), aligné sur le studio.
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);
        return $this->json(['data' => $this->serialize($project)]);
    }

    #[Route('/{uuid}', name: 'update', methods: ['PATCH', 'PUT'])]
    public function update(string $uuid, Request $request): JsonResponse
    {
        if ($err = $this->validateUuid($uuid)) {
            return $err;
        }

        $this->requireScope($request, 'projects:write');
        try {
            $project = $this->projects->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('admin_api.project.not_found')], 404);
        }
        if (!$project) {
            return $this->json(['error' => $this->trans('admin_api.project.not_found')], 404);
        }
        $this->denyAccessUnlessGranted(ProjectVoter::MANAGE, $project);
        try {
            $body = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('admin_api.project.invalid_json')], 400);
        }

        if (isset($body['name'])) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                return $this->json(['error' => $this->trans('admin_api.project.name_required')], 422);
            }
            if (mb_strlen($name) > 255) {
                return $this->json(['error' => $this->trans('admin_api.project.name_too_long')], 422);
            }
        }

        $this->provisioner->updateProject($project, $body);
        return $this->json(['data' => $this->serialize($project)]);
    }

    #[Route('/{uuid}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $uuid, Request $request): JsonResponse
    {
        if ($err = $this->validateUuid($uuid)) {
            return $err;
        }

        $this->requireScope($request, 'projects:write');
        try {
            $project = $this->projects->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('admin_api.project.not_found')], 404);
        }
        if (!$project) {
            return $this->json(['error' => $this->trans('admin_api.project.not_found')], 404);
        }
        $this->denyAccessUnlessGranted(ProjectVoter::MANAGE, $project);
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
