<?php

namespace App\Controller\Api;

use App\Entity\EndUserField;
use App\Repository\EndUserFieldRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Service\ApiTokenChecker;
use App\Service\FieldRelationOptionsNormalizer;
use App\Service\NamingConvention;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[OA\Tag(name: 'End-User Schema')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/api/projects/{uuid}/end-users/fields', name: 'api_end_users_fields_')]
class EndUserSchemaController extends AbstractController
{
    use ProjectAwareControllerTrait;

    public function __construct(
        private ProjectRepository $projectRepository,
        private ProjectMemberRepository $memberRepo,
        private EndUserFieldRepository $fieldRepository,
        private EntityManagerInterface $em,
        private Security $security,
        private ApiTokenChecker $tokenChecker,
        private FieldRelationOptionsNormalizer $relationOptionsNormalizer,
    ) {}

    #[OA\Get(
        path: '/api/projects/{uuid}/end-users/fields',
        summary: 'List all end-user fields for a project',
        security: [['ApiToken' => []]],
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Field list', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/EndUserField')),
            ])),
            new OA\Response(response: 403, description: 'Access denied', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 404, description: 'Project not found', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $uuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($uuid, $request);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $fields = $this->fieldRepository->findByProject($project);

        return $this->json(['data' => array_map(fn ($f) => $this->serialize($f), $fields)]);
    }

    #[OA\Post(
        path: '/api/projects/{uuid}/end-users/fields/reorder',
        summary: 'Reorder end-user fields',
        security: [['ApiToken' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'fields', type: 'array', items: new OA\Items(properties: [
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'order', type: 'integer'),
            ])),
        ])),
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Reordered', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
            ])),
            new OA\Response(response: 403, description: 'Insufficient permissions', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('/reorder', name: 'reorder', methods: ['POST'], priority: 10)]
    public function reorder(string $uuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($uuid, $request, requireManage: true);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $data = $request->toArray();
        foreach ($data['fields'] ?? [] as $item) {
            $field = $this->fieldRepository->find((int) $item['id']);
            if ($field && $field->project->id === $project->id) {
                $field->order = (int) $item['order'];
            }
        }
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    #[OA\Post(
        path: '/api/projects/{uuid}/end-users/fields',
        summary: 'Create a new end-user field',
        security: [['ApiToken' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['name', 'type'],
            properties: [
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'slug', type: 'string', nullable: true, description: 'Auto-generated from name if omitted'),
                new OA\Property(property: 'type', type: 'string', example: 'text'),
                new OA\Property(property: 'options', type: 'object', nullable: true),
                new OA\Property(property: 'is_required', type: 'boolean'),
                new OA\Property(property: 'order', type: 'integer'),
            ]
        )),
        parameters: [new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'Field created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/EndUserField'),
            ])),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 403, description: 'Insufficient permissions', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(string $uuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($uuid, $request, requireManage: true);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $data = $request->toArray();

        $rawName = trim($data['name'] ?? '');
        $type = trim($data['type'] ?? '');

        if ($rawName === '' || $type === '') {
            return $this->json(['errors' => ['name' => 'Name and type are required']], 422);
        }

        // Norme canonique Jambo : nom de champ camelCase, slug snake_case dérivé.
        $name = NamingConvention::toCamelCase($rawName);
        $slug = NamingConvention::toSnakeCase($data['slug'] ?? $rawName);

        if ($this->fieldRepository->findOneByProjectAndSlug($project, $slug) !== null) {
            return $this->json(['errors' => ['slug' => 'A field with this slug already exists']], 422);
        }

        $count = count($this->fieldRepository->findByProject($project));

        $field            = new EndUserField();
        $field->project   = $project;
        $field->name      = $name;
        $field->slug      = $slug;
        $field->type      = $type;
        $field->options   = $this->normalizeOptions($type, $data['options'] ?? null, $project);
        $field->order     = $data['order'] ?? $count;
        $field->isRequired = (bool) ($data['is_required'] ?? false);
        $field->isSystem  = false;

        $this->em->persist($field);
        $this->em->flush();

        return $this->json(['data' => $this->serialize($field)], 201);
    }

    #[OA\Patch(
        path: '/api/projects/{uuid}/end-users/fields/{slug}',
        summary: 'Update an end-user field',
        security: [['ApiToken' => []]],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
            new OA\Property(property: 'name', type: 'string'),
            new OA\Property(property: 'options', type: 'object', nullable: true),
            new OA\Property(property: 'is_required', type: 'boolean'),
            new OA\Property(property: 'order', type: 'integer'),
        ])),
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Field updated', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/EndUserField'),
            ])),
            new OA\Response(response: 403, description: 'System fields cannot be modified', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 404, description: 'Field not found', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('/{slug}', name: 'update', methods: ['PATCH', 'PUT'])]
    public function update(string $uuid, string $slug, Request $request): JsonResponse
    {
        $project = $this->resolveProject($uuid, $request, requireManage: true);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $field = $this->fieldRepository->findOneByProjectAndSlug($project, $slug);
        if (!$field) {
            return $this->json(['error' => 'Field not found'], 404);
        }

        if ($field->isSystem) {
            return $this->json(['error' => 'System fields cannot be modified'], 403);
        }

        $data = $request->toArray();

        if (isset($data['name'])) {
            // Norme canonique Jambo : nom de champ camelCase. Le slug reste stable
            // (le renommer casserait les clés déjà stockées dans custom_fields).
            $field->name = NamingConvention::toCamelCase($data['name']);
        }
        if (isset($data['options'])) {
            $field->options = $this->normalizeOptions($field->type, $data['options'], $field->project);
        }
        if (isset($data['is_required'])) {
            $field->isRequired = (bool) $data['is_required'];
        }
        if (isset($data['order'])) {
            $field->order = (int) $data['order'];
        }

        $this->em->flush();

        return $this->json(['data' => $this->serialize($field)]);
    }

    #[OA\Delete(
        path: '/api/projects/{uuid}/end-users/fields/{slug}',
        summary: 'Delete an end-user field',
        security: [['ApiToken' => []]],
        parameters: [
            new OA\Parameter(name: 'uuid', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Field deleted'),
            new OA\Response(response: 403, description: 'System fields cannot be deleted', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 404, description: 'Field not found', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('/{slug}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $uuid, string $slug, Request $request): JsonResponse
    {
        $project = $this->resolveProject($uuid, $request, requireManage: true);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $field = $this->fieldRepository->findOneByProjectAndSlug($project, $slug);
        if (!$field) {
            return $this->json(['error' => 'Field not found'], 404);
        }

        if ($field->isSystem) {
            return $this->json(['error' => 'System fields cannot be deleted'], 403);
        }

        $this->em->remove($field);
        $this->em->flush();

        return $this->json(null, 204);
    }

    private function serialize(EndUserField $field): array
    {
        $options = $field->options;
        // Lecture : enrichit les options de relation (collection_slug dérivé),
        // à parité avec les champs de collection (cf. FieldController).
        if ($field->type === 'relation' && $options !== null) {
            $options = $this->relationOptionsNormalizer->normalize($options, $field->project);
        }

        return [
            'id'          => $field->id,
            'name'        => $field->name,
            'label'       => $field->name,
            'slug'        => $field->slug,
            'type'        => $field->type,
            'options'     => $options ?? [],
            'order'       => $field->order,
            'required'    => $field->isRequired,
            'is_system'   => $field->isSystem,
        ];
    }

    /**
     * Normalise les options avant stockage, à parité avec FieldController :
     * les champs `relation` passent par le normalizer (validation cible +
     * format canonique `targetCollection`) ; les autres types sont conservés.
     */
    private function normalizeOptions(string $type, ?array $options, \App\Entity\Project $project): ?array
    {
        if ($type !== 'relation' || $options === null) {
            return $options;
        }

        return $this->relationOptionsNormalizer->normalize($options, $project, forStorage: true);
    }
}
