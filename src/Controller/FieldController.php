<?php

namespace App\Controller;

use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\Exception\SchemaException;
use App\Repository\CollectionRepository;
use App\Repository\FieldRepository;
use App\Repository\ProjectRepository;
use App\Service\AuditService;
use App\Service\FieldRelationOptionsNormalizer;
use App\Service\SchemaProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/projects/{projectUuid}/collections/{collectionSlug}/fields', name: 'api_field_')]
class FieldController extends AbstractController
{
    public const MAX_PAYLOAD_BYTES = 524288; // 512KB

    public const ALLOWED_TYPES = [
        'text', 'longtext', 'richtext', 'wysiwyg', 'markdown', 'number', 'decimal', 'rating',
        'boolean', 'checkbox', 'date', 'datetime', 'email', 'url', 'slug', 'color', 'icon',
        'code', 'json', 'array', 'repeater', 'enumeration', 'tags', 'media', 'relation',
    ];

    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-store',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private FieldRepository $fieldRepository,
        private ProjectRepository $projectRepository,
        private CollectionRepository $collectionRepository,
        private FieldRelationOptionsNormalizer $relationOptionsNormalizer,
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
    public function index(string $projectUuid, string $collectionSlug, ?Request $request = null): JsonResponse
    {
        $collection = $this->findCollection($projectUuid, $collectionSlug);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        if (!$this->getUser() && $request?->attributes->get('_project') !== $collection->project) {
            return $this->secureJson(['error' => $this->trans('field.admin.auth_required'), 'code' => 'AUTH_REQUIRED'], 401);
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('project.view', $collection->project);
        }

        $fields = $this->fieldRepository->findByCollection($collection);

        return $this->secureJson([
            'data' => array_map(fn($f) => $this->serialize($f), $fields),
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(string $projectUuid, string $collectionSlug, Request $request): JsonResponse
    {
        $collection = $this->findCollection($projectUuid, $collectionSlug);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        if (!$this->getUser() && $request->attributes->get('_project') !== $collection->project) {
            return $this->secureJson(['error' => $this->trans('field.admin.auth_required'), 'code' => 'AUTH_REQUIRED'], 401);
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('collection.update', $collection->project);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->trans('field.admin.payload_too_large'), 'code' => 'PAYLOAD_TOO_LARGE'], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('field.admin.invalid_json'), 'code' => 'INVALID_JSON'], 400);
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $msg = $this->trans('field.admin.name_required');
            return $this->secureJson(['error' => $msg, 'errors' => ['name' => $msg], 'code' => 'NAME_REQUIRED'], 422);
        }
        if (mb_strlen($name) > 255) {
            $msg = $this->trans('field.admin.name_too_long');
            return $this->secureJson(['error' => $msg, 'errors' => ['name' => $msg], 'code' => 'NAME_TOO_LONG'], 422);
        }

        $type = (string) ($data['type'] ?? '');
        if ($type === '') {
            $msg = $this->trans('field.admin.type_required');
            return $this->secureJson(['error' => $msg, 'errors' => ['type' => $msg], 'code' => 'TYPE_REQUIRED'], 422);
        }
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            $msg = $this->trans('field.admin.invalid_type', ['%type%' => $type]);
            return $this->secureJson(['error' => $msg, 'errors' => ['type' => $msg], 'code' => 'INVALID_FIELD_TYPE'], 422);
        }

        if (isset($data['slug'])) {
            $slug = trim((string) $data['slug']);
            if (mb_strlen($slug) > 255) {
                $msg = $this->trans('field.admin.slug_too_long');
                return $this->secureJson(['error' => $msg, 'errors' => ['slug' => $msg], 'code' => 'SLUG_TOO_LONG'], 422);
            }
            if ($slug !== '' && !preg_match('/^[a-zA-Z0-9_\-]+$/', $slug)) {
                $msg = $this->trans('field.admin.invalid_slug_format');
                return $this->secureJson(['error' => $msg, 'errors' => ['slug' => $msg], 'code' => 'INVALID_SLUG_FORMAT'], 422);
            }
        }

        if (isset($data['options'])) {
            if (!is_array($data['options'])) {
                $msg = $this->trans('field.admin.options_invalid');
                return $this->secureJson(['error' => $msg, 'errors' => ['options' => $msg], 'code' => 'INVALID_OPTIONS'], 422);
            }
            $json = json_encode($data['options']);
            if ($json !== false && strlen($json) > 65536) {
                $msg = $this->trans('field.admin.options_too_large');
                return $this->secureJson(['error' => $msg, 'errors' => ['options' => $msg], 'code' => 'OPTIONS_TOO_LARGE'], 422);
            }
            if ($this->getArrayDepth($data['options']) > 6) {
                $msg = $this->trans('field.admin.options_too_deep');
                return $this->secureJson(['error' => $msg, 'errors' => ['options' => $msg], 'code' => 'OPTIONS_TOO_DEEP'], 422);
            }
        }

        try {
            $field = $this->provisioner->addField($collection, $data);
        } catch (SchemaException $e) {
            return $this->secureJson(['error' => $e->getMessage(), 'code' => 'SCHEMA_ERROR'], $e->statusCode());
        }

        $this->auditService?->log(
            toolName: 'field_create',
            project: $collection->project,
            input: ['collection' => $collection->slug, 'name' => $field->name, 'slug' => $field->slug, 'type' => $field->type],
            output: ['success' => true, 'id' => $field->id],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'field_management'
        );
        $this->auditService?->flush();

        return $this->secureJson(['data' => $this->serialize($field)], 201);
    }

    #[Route('/{fieldSlug}', name: 'show', methods: ['GET'])]
    public function show(string $projectUuid, string $collectionSlug, string $fieldSlug, ?Request $request = null): JsonResponse
    {
        $field = $this->findField($projectUuid, $collectionSlug, $fieldSlug);
        if ($field instanceof JsonResponse) {
            return $field;
        }

        if (!$this->getUser() && $request?->attributes->get('_project') !== $field->collection->project) {
            return $this->secureJson(['error' => $this->trans('field.admin.auth_required'), 'code' => 'AUTH_REQUIRED'], 401);
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('project.view', $field->collection->project);
        }

        return $this->secureJson(['data' => $this->serialize($field)]);
    }

    #[Route('/{fieldSlug}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(string $projectUuid, string $collectionSlug, string $fieldSlug, Request $request): JsonResponse
    {
        $field = $this->findField($projectUuid, $collectionSlug, $fieldSlug);
        if ($field instanceof JsonResponse) {
            return $field;
        }

        if (!$this->getUser() && $request->attributes->get('_project') !== $field->collection->project) {
            return $this->secureJson(['error' => $this->trans('field.admin.auth_required'), 'code' => 'AUTH_REQUIRED'], 401);
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('collection.update', $field->collection->project);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->trans('field.admin.payload_too_large'), 'code' => 'PAYLOAD_TOO_LARGE'], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('field.admin.invalid_json'), 'code' => 'INVALID_JSON'], 400);
        }

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                $msg = $this->trans('field.admin.name_required');
                return $this->secureJson(['error' => $msg, 'errors' => ['name' => $msg], 'code' => 'NAME_REQUIRED'], 422);
            }
            if (mb_strlen($name) > 255) {
                $msg = $this->trans('field.admin.name_too_long');
                return $this->secureJson(['error' => $msg, 'errors' => ['name' => $msg], 'code' => 'NAME_TOO_LONG'], 422);
            }
        }

        if (isset($data['type'])) {
            $type = (string) $data['type'];
            if (!in_array($type, self::ALLOWED_TYPES, true)) {
                $msg = $this->trans('field.admin.invalid_type', ['%type%' => $type]);
                return $this->secureJson(['error' => $msg, 'errors' => ['type' => $msg], 'code' => 'INVALID_FIELD_TYPE'], 422);
            }
        }

        if (isset($data['slug'])) {
            $slug = trim((string) $data['slug']);
            if (mb_strlen($slug) > 255) {
                $msg = $this->trans('field.admin.slug_too_long');
                return $this->secureJson(['error' => $msg, 'errors' => ['slug' => $msg], 'code' => 'SLUG_TOO_LONG'], 422);
            }
            if ($slug !== '' && !preg_match('/^[a-zA-Z0-9_\-]+$/', $slug)) {
                $msg = $this->trans('field.admin.invalid_slug_format');
                return $this->secureJson(['error' => $msg, 'errors' => ['slug' => $msg], 'code' => 'INVALID_SLUG_FORMAT'], 422);
            }
        }

        if (isset($data['options']) && is_array($data['options'])) {
            $json = json_encode($data['options']);
            if ($json !== false && strlen($json) > 65536) {
                $msg = $this->trans('field.admin.options_too_large');
                return $this->secureJson(['error' => $msg, 'errors' => ['options' => $msg], 'code' => 'OPTIONS_TOO_LARGE'], 422);
            }
            if ($this->getArrayDepth($data['options']) > 6) {
                $msg = $this->trans('field.admin.options_too_deep');
                return $this->secureJson(['error' => $msg, 'errors' => ['options' => $msg], 'code' => 'OPTIONS_TOO_DEEP'], 422);
            }
        }

        try {
            $this->provisioner->updateField($field, $data);
        } catch (SchemaException $e) {
            return $this->secureJson(['error' => $e->getMessage(), 'code' => 'SCHEMA_ERROR'], $e->statusCode());
        }

        $this->auditService?->log(
            toolName: 'field_update',
            project: $field->collection->project,
            input: ['collection' => $field->collection->slug, 'slug' => $field->slug, 'type' => $field->type],
            output: ['success' => true, 'id' => $field->id],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'field_management'
        );
        $this->auditService?->flush();

        return $this->secureJson(['data' => $this->serialize($field)]);
    }

    #[Route('/reorder', name: 'reorder', methods: ['POST'], priority: 10)]
    public function reorder(string $projectUuid, string $collectionSlug, Request $request): JsonResponse
    {
        $collection = $this->findCollection($projectUuid, $collectionSlug);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        if (!$this->getUser() && $request->attributes->get('_project') !== $collection->project) {
            return $this->secureJson(['error' => $this->trans('field.admin.auth_required'), 'code' => 'AUTH_REQUIRED'], 401);
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('collection.update', $collection->project);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->trans('field.admin.payload_too_large'), 'code' => 'PAYLOAD_TOO_LARGE'], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('field.admin.invalid_json'), 'code' => 'INVALID_JSON'], 400);
        }

        if (!isset($data['fields']) || !is_array($data['fields']) || count($data['fields']) > 500) {
            return $this->secureJson(['error' => $this->trans('field.admin.reorder_invalid_list'), 'code' => 'INVALID_REORDER_LIST'], 422);
        }

        $updatedCount = 0;
        foreach ($data['fields'] as $item) {
            if (!is_array($item) || !isset($item['id'], $item['order'])) {
                continue;
            }
            $id = (int) $item['id'];
            $order = (int) $item['order'];
            if ($id <= 0 || $order < 0 || $order > 10000) {
                continue;
            }

            $field = $this->fieldRepository->findOneBy(['id' => $id, 'collection' => $collection]);
            if ($field) {
                $field->order = $order;
                $updatedCount++;
            }
        }
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'field_reorder',
            project: $collection->project,
            input: ['collection' => $collection->slug, 'count' => $updatedCount],
            output: ['success' => true],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'field_management'
        );
        $this->auditService?->flush();

        return $this->secureJson(null, 204);
    }

    #[Route('/{fieldSlug}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $projectUuid, string $collectionSlug, string $fieldSlug, ?Request $request = null): JsonResponse
    {
        $field = $this->findField($projectUuid, $collectionSlug, $fieldSlug);
        if ($field instanceof JsonResponse) {
            return $field;
        }

        if (!$this->getUser() && $request?->attributes->get('_project') !== $field->collection->project) {
            return $this->secureJson(['error' => $this->trans('field.admin.auth_required'), 'code' => 'AUTH_REQUIRED'], 401);
        }

        if ($this->getUser()) {
            $this->denyAccessUnlessGranted('collection.update', $field->collection->project);
        }

        $fieldId = $field->id;
        $fieldSlugName = $field->slug;
        $fieldType = $field->type;
        $project = $field->collection->project;

        $field->deletedAt = new \DateTimeImmutable();
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'field_delete',
            project: $project,
            input: ['collection' => $collectionSlug, 'id' => $fieldId, 'slug' => $fieldSlugName, 'type' => $fieldType],
            output: ['success' => true],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier(),
            source: 'field_management'
        );
        $this->auditService?->flush();

        return $this->secureJson(null, 204);
    }

    private function findCollection(string $projectUuid, string $collectionSlug): Collection|JsonResponse
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $projectUuid)) {
            return $this->secureJson(['error' => $this->trans('field.admin.project_not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('field.admin.project_not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }
        if (!$project) {
            return $this->secureJson(['error' => $this->trans('field.admin.project_not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }

        $collection = $this->collectionRepository->findOneByProjectAndSlug($project, $collectionSlug);
        if (!$collection) {
            return $this->secureJson(['error' => $this->trans('field.admin.collection_not_found'), 'code' => 'COLLECTION_NOT_FOUND'], 404);
        }

        return $collection;
    }

    private function findField(string $projectUuid, string $collectionSlug, string $fieldSlug): Field|JsonResponse
    {
        $collection = $this->findCollection($projectUuid, $collectionSlug);
        if ($collection instanceof JsonResponse) {
            return $collection;
        }

        $field = $this->fieldRepository->findOneByCollectionAndSlug($collection, $fieldSlug);
        if (!$field) {
            return $this->secureJson(['error' => $this->trans('field.admin.not_found'), 'code' => 'FIELD_NOT_FOUND'], 404);
        }

        return $field;
    }

    private function getArrayDepth(array $array): int
    {
        $maxDepth = 1;
        foreach ($array as $value) {
            if (is_array($value)) {
                $depth = $this->getArrayDepth($value) + 1;
                if ($depth > $maxDepth) {
                    $maxDepth = $depth;
                }
            }
        }
        return $maxDepth;
    }

    private function serialize(Field $field): array
    {
        $options = $field->options;
        // Lecture : enrichit les options relation (collection_slug dérivé)
        if ($field->type === 'relation' && $options !== null) {
            $options = $this->relationOptionsNormalizer->normalize($options, $field->collection->project);
        }

        return [
            'id' => $field->id,
            'name' => $field->name,
            'slug' => $field->slug,
            'type' => $field->type,
            'options' => $options,
            'order' => $field->order,
            'is_required' => $field->isRequired,
        ];
    }
}
