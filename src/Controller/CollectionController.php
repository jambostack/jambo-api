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
    public const MAX_PAYLOAD_BYTES = 524288; // 512KB

    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-store',
    ];

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

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $projectUuid, ?Request $request = null): JsonResponse
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $projectUuid)) {
            return $this->secureJson(['error' => $this->trans('collection.admin.project_not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('collection.admin.project_not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }
        if (!$project) {
            return $this->secureJson(['error' => $this->trans('collection.admin.project_not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }

        if (!$this->getUser() && $request?->attributes->get('_project') !== $project) {
            return $this->secureJson(['error' => $this->trans('collection.admin.auth_required'), 'code' => 'AUTH_REQUIRED'], 401);
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('project.view', $project);
        }

        $collections = $this->collectionRepository->findByProject($project);

        return $this->secureJson([
            'data' => array_map(fn($c) => $this->serialize($c), $collections),
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(string $projectUuid, Request $request): JsonResponse
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $projectUuid)) {
            return $this->secureJson(['error' => $this->trans('collection.admin.project_not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('collection.admin.project_not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }
        if (!$project) {
            return $this->secureJson(['error' => $this->trans('collection.admin.project_not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }

        if (!$this->getUser() && $request->attributes->get('_project') !== $project) {
            return $this->secureJson(['error' => $this->trans('collection.admin.auth_required'), 'code' => 'AUTH_REQUIRED'], 401);
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('collection.create', $project);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->trans('collection.admin.payload_too_large'), 'code' => 'PAYLOAD_TOO_LARGE'], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('collection.admin.invalid_json'), 'code' => 'INVALID_JSON'], 400);
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $msg = $this->trans('collection.admin.name_required');
            return $this->secureJson(['error' => $msg, 'errors' => ['name' => $msg], 'code' => 'NAME_REQUIRED'], 422);
        }
        if (mb_strlen($name) > 255) {
            $msg = $this->trans('collection.admin.name_too_long');
            return $this->secureJson(['error' => $msg, 'errors' => ['name' => $msg], 'code' => 'NAME_TOO_LONG'], 422);
        }

        if (isset($data['slug'])) {
            $slug = trim((string) $data['slug']);
            if (mb_strlen($slug) > 255) {
                $msg = $this->trans('collection.admin.slug_too_long');
                return $this->secureJson(['error' => $msg, 'errors' => ['slug' => $msg], 'code' => 'SLUG_TOO_LONG'], 422);
            }
            if ($slug !== '' && !preg_match('/^[a-zA-Z0-9_\-]+$/', $slug)) {
                $msg = $this->trans('collection.admin.invalid_slug_format');
                return $this->secureJson(['error' => $msg, 'errors' => ['slug' => $msg], 'code' => 'INVALID_SLUG_FORMAT'], 422);
            }
        }

        if (isset($data['description']) && mb_strlen((string) $data['description']) > 2000) {
            $msg = $this->trans('collection.admin.description_too_long');
            return $this->secureJson(['error' => $msg, 'errors' => ['description' => $msg], 'code' => 'DESCRIPTION_TOO_LONG'], 422);
        }

        try {
            // Logique centralisée dans SchemaProvisioner (conventions + validation).
            $collection = $this->provisioner->createCollection($project, $data);
        } catch (SchemaException $e) {
            return $this->secureJson(['error' => $e->getMessage(), 'code' => 'SCHEMA_ERROR'], $e->statusCode());
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

        return $this->secureJson(['data' => $this->serialize($collection)], 201);
    }

    #[Route('/reorder', name: 'reorder', methods: ['POST'], priority: 10)]
    public function reorder(string $projectUuid, Request $request): JsonResponse
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $projectUuid)) {
            return $this->secureJson(['error' => $this->trans('collection.admin.project_not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('collection.admin.project_not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }
        if (!$project) {
            return $this->secureJson(['error' => $this->trans('collection.admin.project_not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }

        if (!$this->getUser() && $request->attributes->get('_project') !== $project) {
            return $this->secureJson(['error' => $this->trans('collection.admin.auth_required'), 'code' => 'AUTH_REQUIRED'], 401);
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('collection.update', $project);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->trans('collection.admin.payload_too_large'), 'code' => 'PAYLOAD_TOO_LARGE'], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('collection.admin.invalid_json'), 'code' => 'INVALID_JSON'], 400);
        }

        if (!isset($data['collections']) || !is_array($data['collections']) || count($data['collections']) > 500) {
            return $this->secureJson(['error' => $this->trans('collection.admin.reorder_invalid_list'), 'code' => 'REORDER_INVALID_LIST'], 422);
        }

        $updatedCount = 0;
        $seenIds = [];
        foreach ($data['collections'] as $item) {
            if (!is_array($item) || !isset($item['id'], $item['order'])) {
                continue;
            }
            $id = (int) $item['id'];
            $order = (int) $item['order'];
            if ($id <= 0 || $order < 0 || $order > 10000 || isset($seenIds[$id])) {
                continue;
            }
            $seenIds[$id] = true;

            $collection = $this->collectionRepository->findOneBy([
                'id'      => $id,
                'project' => $project,
            ]);
            if ($collection) {
                $collection->order = $order;
                $updatedCount++;
            }
        }

        $this->em->flush();

        $this->auditService?->log(
            toolName: 'collection_reorder',
            project: $project,
            input: ['count' => $updatedCount],
            output: ['success' => true],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'collection_management'
        );
        $this->auditService?->flush();

        return $this->secureJson(null, 204);
    }

    #[Route('/{slug}', name: 'show', methods: ['GET'])]
    public function show(string $projectUuid, string $slug, ?Request $request = null): JsonResponse
    {
        $collection = $this->findCollection($projectUuid, $slug);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        if (!$this->getUser() && $request?->attributes->get('_project') !== $collection->project) {
            return $this->secureJson(['error' => $this->trans('collection.admin.auth_required'), 'code' => 'AUTH_REQUIRED'], 401);
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('project.view', $collection->project);
        }

        return $this->secureJson(['data' => $this->serialize($collection)]);
    }

    #[Route('/{slug}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(string $projectUuid, string $slug, Request $request): JsonResponse
    {
        $collection = $this->findCollection($projectUuid, $slug);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        if (!$this->getUser() && $request->attributes->get('_project') !== $collection->project) {
            return $this->secureJson(['error' => $this->trans('collection.admin.auth_required'), 'code' => 'AUTH_REQUIRED'], 401);
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('collection.update', $collection->project);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->trans('collection.admin.payload_too_large'), 'code' => 'PAYLOAD_TOO_LARGE'], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('collection.admin.invalid_json'), 'code' => 'INVALID_JSON'], 400);
        }

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                $msg = $this->trans('collection.admin.name_required');
                return $this->secureJson(['error' => $msg, 'errors' => ['name' => $msg], 'code' => 'NAME_REQUIRED'], 422);
            }
            if (mb_strlen($name) > 255) {
                $msg = $this->trans('collection.admin.name_too_long');
                return $this->secureJson(['error' => $msg, 'errors' => ['name' => $msg], 'code' => 'NAME_TOO_LONG'], 422);
            }
            $collection->name = NamingConvention::toPascalCase($name);
        }
        if (isset($data['slug'])) {
            $newSlug = trim((string) $data['slug']);
            if (mb_strlen($newSlug) > 255) {
                $msg = $this->trans('collection.admin.slug_too_long');
                return $this->secureJson(['error' => $msg, 'errors' => ['slug' => $msg], 'code' => 'SLUG_TOO_LONG'], 422);
            }
            if ($newSlug !== '' && !preg_match('/^[a-zA-Z0-9_\-]+$/', $newSlug)) {
                $msg = $this->trans('collection.admin.invalid_slug_format');
                return $this->secureJson(['error' => $msg, 'errors' => ['slug' => $msg], 'code' => 'INVALID_SLUG_FORMAT'], 422);
            }
            $normalizedSlug = NamingConvention::toSnakeCase($newSlug);
            $existing = $this->collectionRepository->findOneByProjectAndSlug($collection->project, $normalizedSlug);
            if ($existing !== null && $existing->id !== $collection->id) {
                $msg = $this->trans('collection.admin.slug_already_exists');
                return $this->secureJson(['error' => $msg, 'errors' => ['slug' => $msg], 'code' => 'SLUG_ALREADY_EXISTS'], 409);
            }
            $collection->slug = $normalizedSlug;
        }
        if (array_key_exists('description', $data)) {
            $desc = (string) $data['description'];
            if (mb_strlen($desc) > 2000) {
                $msg = $this->trans('collection.admin.description_too_long');
                return $this->secureJson(['error' => $msg, 'errors' => ['description' => $msg], 'code' => 'DESCRIPTION_TOO_LONG'], 422);
            }
            $collection->description = $data['description'];
        }
        if (isset($data['is_singleton'])) {
            $collection->isSingleton = (bool) $data['is_singleton'];
        }
        if (isset($data['order'])) {
            $order = (int) $data['order'];
            if ($order >= 0 && $order <= 10000) {
                $collection->order = $order;
            }
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

        return $this->secureJson(['data' => $this->serialize($collection)]);
    }

    #[Route('/{slug}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $projectUuid, string $slug, ?Request $request = null): JsonResponse
    {
        $collection = $this->findCollection($projectUuid, $slug);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        if (!$this->getUser() && $request?->attributes->get('_project') !== $collection->project) {
            return $this->secureJson(['error' => $this->trans('collection.admin.auth_required'), 'code' => 'AUTH_REQUIRED'], 401);
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

        return $this->secureJson(null, 204);
    }

    private function findCollection(string $projectUuid, string $slug): Collection|JsonResponse
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $projectUuid)) {
            return $this->secureJson(['error' => $this->trans('collection.admin.project_not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('collection.admin.project_not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }
        if (!$project) {
            return $this->secureJson(['error' => $this->trans('collection.admin.project_not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }

        $collection = $this->collectionRepository->findOneByProjectAndSlug($project, $slug);
        if (!$collection) {
            return $this->secureJson(['error' => $this->trans('collection.admin.not_found'), 'code' => 'COLLECTION_NOT_FOUND'], 404);
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
