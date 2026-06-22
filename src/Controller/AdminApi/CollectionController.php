<?php

namespace App\Controller\AdminApi;

use App\Exception\SchemaException;
use App\Repository\CollectionRepository;
use App\Repository\ProjectRepository;
use App\Security\ProjectVoter;
use App\Service\SchemaProvisioner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin-api/projects/{uuid}/collections', name: 'admin_api_collection_')]
class CollectionController extends AbstractController
{
    use AdminApiControllerTrait;

    public function __construct(
        private ProjectRepository $projects,
        private CollectionRepository $collections,
        private SchemaProvisioner $provisioner,
    ) {}

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(string $uuid, Request $request): JsonResponse
    {
        $this->requireScope($request, 'schema:write');
        $project = $this->projects->findOneBy(['uuid' => $uuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);
        try {
            $c = $this->provisioner->createCollection($project, $request->toArray());
        } catch (SchemaException $e) {
            return $this->json(['error' => $e->getMessage()], $e->statusCode());
        }
        return $this->json(['data' => ['name' => $c->name, 'slug' => $c->slug, 'isSingleton' => $c->isSingleton]], 201);
    }

    #[Route('/{slug}', name: 'update', methods: ['PATCH', 'PUT'])]
    public function update(string $uuid, string $slug, Request $request): JsonResponse
    {
        $this->requireScope($request, 'schema:write');
        $project = $this->projects->findOneBy(['uuid' => $uuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);
        $c = $this->collections->findOneBy(['project' => $project, 'slug' => $slug]);
        if (!$c) {
            return $this->json(['error' => 'Collection not found'], 404);
        }
        $this->provisioner->updateCollection($c, $request->toArray());
        return $this->json(['data' => ['name' => $c->name, 'slug' => $c->slug]]);
    }

    #[Route('/{slug}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $uuid, string $slug, Request $request): JsonResponse
    {
        $this->requireScope($request, 'schema:write');
        $project = $this->projects->findOneBy(['uuid' => $uuid]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);
        $c = $this->collections->findOneBy(['project' => $project, 'slug' => $slug]);
        if (!$c) {
            return $this->json(['error' => 'Collection not found'], 404);
        }
        $this->provisioner->deleteCollection($c);
        return new JsonResponse(null, 204);
    }
}
