<?php

namespace App\Controller;

use App\Entity\Collection;
use App\Entity\Project;
use App\Exception\SchemaException;
use App\Repository\CollectionRepository;
use App\Repository\ProjectRepository;
use App\Service\AuditService;
use App\Service\NamingConvention;
use App\Service\SchemaProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/projects/{projectUuid}/collections', name: 'api_collection_')]
class CollectionController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ProjectRepository $projectRepository,
        private CollectionRepository $collectionRepository,
        private SchemaProvisioner $provisioner,
        private ?TranslatorInterface $translator = null,
        private ?AuditService $auditService = null,
    ) {}

    private function trans(string $id, array $parameters = []): string
    {
        return $this->translator?->trans($id, $parameters) ?? $id;
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $projectUuid, ?Request $request = null): JsonResponse
    {
        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('collection.admin.project_not_found')], 404);
        }
        if (!$project) {
            return $this->json(['error' => $this->trans('collection.admin.project_not_found')], 404);
        }

        if (!$this->getUser() && $request?->attributes->get('_project') !== $project) {
            return $this->json(['error' => $this->trans('collection.admin.auth_required')], 401);
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('project.view', $project);
        }

        $collections = $this->collectionRepository->findByProject($project);

        return $this->json([
            'data' => array_map(fn($c) => $this->serialize($c), $collections),
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(string $projectUuid, Request $request): JsonResponse
    {
        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('collection.admin.project_not_found')], 404);
        }
        if (!$project) {
            return $this->json(['error' => $this->trans('collection.admin.project_not_found')], 404);
        }

        if (!$this->getUser() && $request->attributes->get('_project') !== $project) {
            return $this->json(['error' => $this->trans('collection.admin.auth_required')], 401);
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('collection.create', $project);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('collection.admin.invalid_json')], 400);
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return $this->json(['errors' => ['name' => $this->trans('collection.admin.name_required')]], 422);
        }
        if (mb_strlen($name) > 255) {
            return $this->json(['errors' => ['name' => $this->trans('collection.admin.name_too_long')]], 422);
        }

        if (isset($data['slug'])) {
            $slug = trim((string) $data['slug']);
            if (mb_strlen($slug) > 255) {
                return $this->json(['errors' => ['slug' => $this->trans('collection.admin.slug_too_long')]], 422);
            }
            if ($slug !== '' && !preg_match('/^[a-zA-Z0-9_\-]+$/', $slug)) {
                return $this->json(['errors' => ['slug' => $this->trans('collection.admin.invalid_slug_format')]], 422);
            }
        }

        if (isset($data['description']) && mb_strlen((string) $data['description']) > 2000) {
            return $this->json(['errors' => ['description' => $this->trans('collection.admin.description_too_long')]], 422);
        }

        try {
            // Logique centralisée dans SchemaProvisioner (conventions + validation).
            $collection = $this->provisioner->createCollection($project, $data);
        } catch (SchemaException $e) {
            return $this->json(['error' => $e->getMessage()], $e->statusCode());
        }

        $this->auditService?->log(
            toolName: 'collection_create',
            project: $project,
            input: ['name' => $collection->name, 'slug' => $collection->slug],
            output: ['success' => true, 'id' => $collection->id],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'collection_management'
        );
        $this->auditService?->flush();

        return $this->json(['data' => $this->serialize($collection)], 201);
    }

    #[Route('/reorder', name: 'reorder', methods: ['POST'], priority: 10)]
    public function reorder(string $projectUuid, Request $request): JsonResponse
    {
        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('collection.admin.project_not_found')], 404);
        }
        if (!$project) {
            return $this->json(['error' => $this->trans('collection.admin.project_not_found')], 404);
        }

        if (!$this->getUser() && $request->attributes->get('_project') !== $project) {
            return $this->json(['error' => $this->trans('collection.admin.auth_required')], 401);
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('collection.update', $project);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('collection.admin.invalid_json')], 400);
        }

        if (!isset($data['collections']) || !is_array($data['collections']) || count($data['collections']) > 500) {
            return $this->json(['error' => $this->trans('collection.admin.reorder_invalid_list')], 422);
        }

        foreach ($data['collections'] as $item) {
            if (!is_array($item) || !isset($item['id'], $item['order'])) {
                continue;
            }
            $collection = $this->collectionRepository->findOneBy([
                'id'      => (int) $item['id'],
                'project' => $project,
            ]);
            if ($collection) {
                $collection->order = (int) $item['order'];
            }
        }

        $this->em->flush();

        $this->auditService?->log(
            toolName: 'collection_reorder',
            project: $project,
            input: ['count' => count($data['collections'])],
            output: ['success' => true],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'collection_management'
        );
        $this->auditService?->flush();

        return $this->json(null, 204);
    }

    #[Route('/{slug}', name: 'show', methods: ['GET'])]
    public function show(string $projectUuid, string $slug, ?Request $request = null): JsonResponse
    {
        $collection = $this->findCollection($projectUuid, $slug);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        if (!$this->getUser() && $request?->attributes->get('_project') !== $collection->project) {
            return $this->json(['error' => $this->trans('collection.admin.auth_required')], 401);
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('project.view', $collection->project);
        }

        return $this->json(['data' => $this->serialize($collection)]);
    }

    #[Route('/{slug}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(string $projectUuid, string $slug, Request $request): JsonResponse
    {
        $collection = $this->findCollection($projectUuid, $slug);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        if (!$this->getUser() && $request->attributes->get('_project') !== $collection->project) {
            return $this->json(['error' => $this->trans('collection.admin.auth_required')], 401);
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('collection.update', $collection->project);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('collection.admin.invalid_json')], 400);
        }

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return $this->json(['errors' => ['name' => $this->trans('collection.admin.name_required')]], 422);
            }
            if (mb_strlen($name) > 255) {
                return $this->json(['errors' => ['name' => $this->trans('collection.admin.name_too_long')]], 422);
            }
            $collection->name = NamingConvention::toPascalCase($name);
        }
        if (isset($data['slug'])) {
            $newSlug = trim((string) $data['slug']);
            if (mb_strlen($newSlug) > 255) {
                return $this->json(['errors' => ['slug' => $this->trans('collection.admin.slug_too_long')]], 422);
            }
            if ($newSlug !== '' && !preg_match('/^[a-zA-Z0-9_\-]+$/', $newSlug)) {
                return $this->json(['errors' => ['slug' => $this->trans('collection.admin.invalid_slug_format')]], 422);
            }
            $normalizedSlug = NamingConvention::toSnakeCase($newSlug);
            $existing = $this->collectionRepository->findOneByProjectAndSlug($collection->project, $normalizedSlug);
            if ($existing !== null && $existing->id !== $collection->id) {
                return $this->json(['errors' => ['slug' => $this->trans('collection.admin.slug_already_exists')]], 409);
            }
            $collection->slug = $normalizedSlug;
        }
        if (array_key_exists('description', $data)) {
            $desc = (string) $data['description'];
            if (mb_strlen($desc) > 2000) {
                return $this->json(['errors' => ['description' => $this->trans('collection.admin.description_too_long')]], 422);
            }
            $collection->description = $data['description'];
        }
        if (isset($data['is_singleton'])) {
            $collection->isSingleton = (bool) $data['is_singleton'];
        }
        if (isset($data['order'])) {
            $collection->order = (int) $data['order'];
        }

        $this->em->flush();

        $this->auditService?->log(
            toolName: 'collection_update',
            project: $collection->project,
            input: ['name' => $collection->name, 'slug' => $collection->slug],
            output: ['success' => true, 'id' => $collection->id],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'collection_management'
        );
        $this->auditService?->flush();

        return $this->json(['data' => $this->serialize($collection)]);
    }

    #[Route('/{slug}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $projectUuid, string $slug, ?Request $request = null): JsonResponse
    {
        $collection = $this->findCollection($projectUuid, $slug);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        if (!$this->getUser() && $request?->attributes->get('_project') !== $collection->project) {
            return $this->json(['error' => $this->trans('collection.admin.auth_required')], 401);
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('collection.delete', $collection->project);
        }

        $collId = $collection->id;
        $collName = $collection->name;
        $collSlug = $collection->slug;
        $project = $collection->project;

        $this->em->remove($collection);
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'collection_delete',
            project: $project,
            input: ['id' => $collId, 'name' => $collName, 'slug' => $collSlug],
            output: ['success' => true],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'collection_management'
        );
        $this->auditService?->flush();

        return $this->json(null, 204);
    }

    private function findCollection(string $projectUuid, string $slug): Collection|JsonResponse
    {
        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('collection.admin.project_not_found')], 404);
        }
        if (!$project) {
            return $this->json(['error' => $this->trans('collection.admin.project_not_found')], 404);
        }

        $collection = $this->collectionRepository->findOneByProjectAndSlug($project, $slug);
        if (!$collection) {
            return $this->json(['error' => $this->trans('collection.admin.not_found')], 404);
        }

        return $collection;
    }

    private function serialize(Collection $collection): array
    {
        return [
            'id' => $collection->id,
            'uuid' => $collection->uuid?->toRfc4122(),
            'name' => $collection->name,
            'slug' => $collection->slug,
            'description' => $collection->description,
            'is_singleton' => $collection->isSingleton,
            'order' => $collection->order,
            'fields_count' => $collection->fields->count(),
        ];
    }
}
