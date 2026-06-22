<?php

namespace App\Controller\AdminApi;

use App\Exception\SchemaException;
use App\Repository\CollectionRepository;
use App\Repository\FieldRepository;
use App\Repository\ProjectRepository;
use App\Security\ProjectVoter;
use App\Service\SchemaProvisioner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin-api/projects/{uuid}/collections/{slug}/fields', name: 'admin_api_field_')]
class FieldController extends AbstractController
{
    use AdminApiControllerTrait;

    public function __construct(
        private ProjectRepository $projects,
        private CollectionRepository $collections,
        private FieldRepository $fields,
        private SchemaProvisioner $provisioner,
    ) {}

    /** @return array{0: ?\App\Entity\Collection, 1: ?JsonResponse} */
    private function resolveCollection(string $uuid, string $slug): array
    {
        $project = $this->projects->findOneBy(['uuid' => $uuid]);
        if (!$project) {
            return [null, $this->json(['error' => 'Project not found'], 404)];
        }
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);
        $c = $this->collections->findOneBy(['project' => $project, 'slug' => $slug]);
        if (!$c) {
            return [null, $this->json(['error' => 'Collection not found'], 404)];
        }
        return [$c, null];
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(string $uuid, string $slug, Request $request): JsonResponse
    {
        $this->requireScope($request, 'schema:write');
        [$c, $err] = $this->resolveCollection($uuid, $slug);
        if ($err) {
            return $err;
        }
        try {
            $f = $this->provisioner->addField($c, $request->toArray());
        } catch (SchemaException $e) {
            return $this->json(['error' => $e->getMessage()], $e->statusCode());
        }
        return $this->json(['data' => ['name' => $f->name, 'slug' => $f->slug, 'type' => $f->type, 'isRequired' => $f->isRequired]], 201);
    }

    #[Route('/{fieldSlug}', name: 'update', methods: ['PATCH', 'PUT'])]
    public function update(string $uuid, string $slug, string $fieldSlug, Request $request): JsonResponse
    {
        $this->requireScope($request, 'schema:write');
        [$c, $err] = $this->resolveCollection($uuid, $slug);
        if ($err) {
            return $err;
        }
        $f = $this->fields->findOneBy(['collection' => $c, 'slug' => $fieldSlug]);
        if (!$f) {
            return $this->json(['error' => 'Field not found'], 404);
        }
        try {
            $this->provisioner->updateField($f, $request->toArray());
        } catch (SchemaException $e) {
            return $this->json(['error' => $e->getMessage()], $e->statusCode());
        }
        return $this->json(['data' => ['slug' => $f->slug, 'type' => $f->type]]);
    }

    #[Route('/{fieldSlug}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $uuid, string $slug, string $fieldSlug, Request $request): JsonResponse
    {
        $this->requireScope($request, 'schema:write');
        [$c, $err] = $this->resolveCollection($uuid, $slug);
        if ($err) {
            return $err;
        }
        $f = $this->fields->findOneBy(['collection' => $c, 'slug' => $fieldSlug]);
        if (!$f) {
            return $this->json(['error' => 'Field not found'], 404);
        }
        $this->provisioner->deleteField($f);
        return new JsonResponse(null, 204);
    }
}
