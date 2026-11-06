<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\EndUserField;
use App\Entity\Project;
use App\Repository\EndUserFieldRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Service\ApiTokenChecker;
use App\Service\AuditService;
use App\Service\FieldRelationOptionsNormalizer;
use App\Service\NamingConvention;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[OA\Tag(name: 'End-User Schema')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/api/projects/{uuid}/end-users/fields', name: 'api_end_users_fields_')]
class EndUserSchemaController extends AbstractController
{
    use ProjectAwareControllerTrait;

    public const MAX_PAYLOAD_BYTES = 65536; // 64 KB
    public const MAX_NAME_LENGTH = 100;
    public const MAX_SLUG_LENGTH = 100;
    public const MAX_OPTIONS_BYTES = 65536; // 64 KB
    public const MAX_ORDER_VALUE = 10000;
    public const MAX_ENUM_CHOICES = 500;

    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-store',
    ];

    /** Types de champs supportés pour les utilisateurs finaux (EndUser). */
    public const ALLOWED_TYPES = [
        'text', 'longtext', 'richtext', 'wysiwyg', 'markdown', 'number', 'decimal', 'rating',
        'boolean', 'checkbox', 'date', 'datetime', 'email', 'url', 'slug', 'color', 'icon',
        'code', 'json', 'array', 'repeater', 'enumeration', 'tags', 'media', 'relation',
    ];

    /** Identifiants et attributs réservés interdits pour les champs personnalisés. */
    public const RESERVED_SLUGS = [
        'id', 'uuid', 'status', 'locale', 'created_at', 'updated_at', 'deleted_at',
        'email', 'password', 'username', 'avatar', 'avatar_url', 'roles', 'role',
        'custom_fields', 'token_version', 'tokenversion', 'two_factor_enabled',
        'two_factor_method', 'two_factor_secret', 'two_factor_backup_codes',
        'two_factor_confirmed_at', 'password_changed_at', 'google_id', 'microsoft_id',
        'github_id', 'gitlab_id', 'oidc_sub', 'oidc_issuer', 'is_admin', 'isadmin',
        '__proto__', 'constructor', 'prototype',
    ];

    public function __construct(
        private ProjectRepository $projectRepository,
        private ProjectMemberRepository $memberRepo,
        private EndUserFieldRepository $fieldRepository,
        private EntityManagerInterface $em,
        private Security $security,
        private ApiTokenChecker $tokenChecker,
        private FieldRelationOptionsNormalizer $relationOptionsNormalizer,
        private ?TranslatorInterface $translator = null,
        private ?AuditService $auditService = null,
        #[Autowire(service: 'limiter.admin_api_limiter')]
        private ?RateLimiterFactory $adminApiLimiter = null,
    ) {}

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    private function checkRateLimit(Request $request): ?JsonResponse
    {
        if ($this->adminApiLimiter !== null) {
            $identifier = $this->security->getUser()?->getUserIdentifier() ?: ($request->getClientIp() ?: '127.0.0.1');
            if (!$this->adminApiLimiter->create($identifier)->consume(1)->isAccepted()) {
                $this->auditService?->log(
                    toolName: 'admin_enduser_schema_rate_limit_exceeded',
                    project: null,
                    input: ['ip' => $request->getClientIp()],
                    output: null,
                    status: 'blocked',
                    errorMessage: 'Rate limit exceeded on EndUserSchemaController',
                    createdBy: $identifier,
                    source: 'enduser_schema'
                );
                $this->auditService?->flush();

                return $this->secureJson([
                    'error' => $this->trans('enduser.schema.rate_limit_exceeded', [], 'Too many schema requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            }
        }
        return null;
    }

    private function validateContentType(Request $request): ?JsonResponse
    {
        $method = $request->getMethod();
        if (in_array($method, ['POST', 'PATCH', 'PUT'], true) && strlen((string) $request->getContent()) > 0) {
            $contentType = (string) $request->headers->get('Content-Type', '');
            if (!str_contains(strtolower($contentType), 'application/json')) {
                return $this->secureJson([
                    'error' => $this->trans('enduser.schema.invalid_content_type', [], 'Content-Type must be application/json.'),
                    'code'  => 'INVALID_CONTENT_TYPE',
                ], 415);
            }
        }
        return null;
    }

    private function validatePayloadSize(Request $request): ?JsonResponse
    {
        if (strlen((string) $request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('enduser.schema.payload_too_large', [], 'Payload exceeds maximum size limit (64 KB).'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }
        return null;
    }

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters);
        }
        return $default !== null ? strtr($default, $parameters) : $id;
    }

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
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $project = $this->resolveProject($uuid, $request);
        if ($project instanceof JsonResponse) {
            foreach (self::SECURITY_HEADERS as $k => $v) {
                $project->headers->set($k, $v);
            }
            return $project;
        }

        $fields = $this->fieldRepository->findByProject($project);

        return $this->secureJson(['data' => array_map(fn ($f) => $this->serialize($f), $fields)]);
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
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        if ($contentTypeResponse = $this->validateContentType($request)) {
            return $contentTypeResponse;
        }

        if ($payloadSizeResponse = $this->validatePayloadSize($request)) {
            return $payloadSizeResponse;
        }

        $project = $this->resolveProject($uuid, $request, requireManage: true);
        if ($project instanceof JsonResponse) {
            foreach (self::SECURITY_HEADERS as $k => $v) {
                $project->headers->set($k, $v);
            }
            return $project;
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('enduser.schema.invalid_json', [], 'Invalid JSON body')], 400);
        }

        $fieldsList = $data['fields'] ?? null;
        if (!is_array($fieldsList)) {
            return $this->secureJson(['error' => $this->trans('enduser.schema.fields_must_be_array', [], 'fields must be an array of field objects')], 422);
        }

        if (count($fieldsList) > 500) {
            return $this->secureJson(['error' => $this->trans('enduser.schema.too_many_fields_to_reorder', [], 'Cannot reorder more than 500 fields at once')], 422);
        }

        $reorderedCount = 0;
        foreach ($fieldsList as $item) {
            if (!is_array($item) || !isset($item['id'], $item['order'])) {
                continue;
            }

            $order = (int) $item['order'];
            if ($order < 0 || $order > self::MAX_ORDER_VALUE) {
                return $this->secureJson(['error' => $this->trans('enduser.schema.order_out_of_bounds', ['%max%' => (string) self::MAX_ORDER_VALUE], sprintf('Order must be between 0 and %d', self::MAX_ORDER_VALUE))], 422);
            }

            $field = $this->fieldRepository->find((int) $item['id']);
            if ($field && $field->project->id === $project->id) {
                $field->order = $order;
                $reorderedCount++;
            }
        }
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'admin_enduser_fields_reorder',
            project: $project,
            input: ['reordered_count' => $reorderedCount],
            output: ['success' => true],
            status: 'success',
            errorMessage: null,
            createdBy: $this->security->getUser()?->getUserIdentifier() ?? 'system',
            source: 'enduser_schema'
        );
        $this->auditService?->flush();

        return $this->secureJson(['success' => true]);
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
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        if ($contentTypeResponse = $this->validateContentType($request)) {
            return $contentTypeResponse;
        }

        if ($payloadSizeResponse = $this->validatePayloadSize($request)) {
            return $payloadSizeResponse;
        }

        $project = $this->resolveProject($uuid, $request, requireManage: true);
        if ($project instanceof JsonResponse) {
            foreach (self::SECURITY_HEADERS as $k => $v) {
                $project->headers->set($k, $v);
            }
            return $project;
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('enduser.schema.invalid_json', [], 'Invalid JSON body')], 400);
        }

        $rawName = isset($data['name']) && is_string($data['name']) ? trim($data['name']) : '';
        $rawType = isset($data['type']) && is_string($data['type']) ? strtolower(trim($data['type'])) : '';

        if ($rawName === '' || $rawType === '') {
            return $this->secureJson(['errors' => ['name' => $this->trans('enduser.schema.name_and_type_required', [], 'Name and type are required')]], 422);
        }

        if (strlen($rawName) > self::MAX_NAME_LENGTH) {
            return $this->secureJson(['errors' => ['name' => $this->trans('enduser.schema.name_too_long', ['%max%' => (string) self::MAX_NAME_LENGTH], sprintf('Field name cannot exceed %d characters', self::MAX_NAME_LENGTH))]], 422);
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $rawName)) {
            return $this->secureJson(['errors' => ['name' => $this->trans('enduser.schema.name_invalid_chars', [], 'Field name contains invalid control characters')]], 422);
        }

        if (!in_array($rawType, self::ALLOWED_TYPES, true)) {
            return $this->secureJson(['errors' => ['type' => $this->trans('enduser.schema.type_unsupported', ['%type%' => $rawType], sprintf('Field type "%s" is not supported', $rawType))]], 422);
        }

        // Norme canonique Jambo : nom de champ camelCase, slug snake_case dérivé
        $name = NamingConvention::toCamelCase($rawName);
        if (isset($data['slug']) && is_string($data['slug']) && trim($data['slug']) !== '') {
            $customSlug = trim($data['slug']);
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $customSlug)) {
                return $this->secureJson(['errors' => ['slug' => $this->trans('enduser.schema.slug_invalid_format', [], 'Field slug must start with a lowercase letter and contain only lowercase alphanumeric characters and underscores')]], 422);
            }
            $slug = $customSlug;
        } else {
            $slug = NamingConvention::toSnakeCase($rawName);
        }

        if (strlen($slug) > self::MAX_SLUG_LENGTH) {
            return $this->secureJson(['errors' => ['slug' => $this->trans('enduser.schema.slug_too_long', ['%max%' => (string) self::MAX_SLUG_LENGTH], sprintf('Field slug cannot exceed %d characters', self::MAX_SLUG_LENGTH))]], 422);
        }

        if (!preg_match('/^[a-z][a-z0-9_]*$/', $slug)) {
            return $this->secureJson(['errors' => ['slug' => $this->trans('enduser.schema.slug_invalid_format', [], 'Field slug must start with a lowercase letter and contain only lowercase alphanumeric characters and underscores')]], 422);
        }

        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            return $this->secureJson(['errors' => ['slug' => $this->trans('enduser.schema.slug_reserved', ['%slug%' => $slug], sprintf('Field slug "%s" is reserved by the system', $slug))]], 422);
        }

        if ($this->fieldRepository->findOneByProjectAndSlug($project, $slug) !== null) {
            return $this->secureJson(['errors' => ['slug' => $this->trans('enduser.schema.slug_already_exists', [], 'A field with this slug already exists')]], 422);
        }

        $optionsResult = $this->validateAndNormalizeOptions($rawType, $data['options'] ?? null, $project);
        if (!$optionsResult['valid']) {
            return $this->secureJson(['errors' => ['options' => $optionsResult['error']]], 422);
        }

        $order = isset($data['order']) ? (int) $data['order'] : null;
        if ($order !== null && ($order < 0 || $order > self::MAX_ORDER_VALUE)) {
            return $this->secureJson(['errors' => ['order' => $this->trans('enduser.schema.order_out_of_bounds', ['%max%' => (string) self::MAX_ORDER_VALUE], sprintf('Order must be between 0 and %d', self::MAX_ORDER_VALUE))]], 422);
        }

        $count = count($this->fieldRepository->findByProject($project));

        $field             = new EndUserField();
        $field->project    = $project;
        $field->name       = $name;
        $field->slug       = $slug;
        $field->type       = $rawType;
        $field->options    = $optionsResult['normalized'];
        $field->order      = $order ?? $count;
        $field->isRequired = (bool) ($data['is_required'] ?? false);
        $field->isSystem   = false;

        $this->em->persist($field);
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'admin_enduser_field_create',
            project: $project,
            input: ['name' => $field->name, 'slug' => $field->slug, 'type' => $field->type],
            output: ['id' => $field->id, 'slug' => $field->slug],
            status: 'success',
            errorMessage: null,
            createdBy: $this->security->getUser()?->getUserIdentifier() ?? 'system',
            source: 'enduser_schema'
        );
        $this->auditService?->flush();

        return $this->secureJson(['data' => $this->serialize($field)], 201);
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
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        if ($contentTypeResponse = $this->validateContentType($request)) {
            return $contentTypeResponse;
        }

        if ($payloadSizeResponse = $this->validatePayloadSize($request)) {
            return $payloadSizeResponse;
        }

        $project = $this->resolveProject($uuid, $request, requireManage: true);
        if ($project instanceof JsonResponse) {
            foreach (self::SECURITY_HEADERS as $k => $v) {
                $project->headers->set($k, $v);
            }
            return $project;
        }

        $field = $this->fieldRepository->findOneByProjectAndSlug($project, $slug);
        if (!$field) {
            return $this->secureJson(['error' => $this->trans('enduser.schema.field_not_found', [], 'Field not found')], 404);
        }

        if ($field->isSystem) {
            return $this->secureJson(['error' => $this->trans('enduser.schema.system_field_immutable', [], 'System fields cannot be modified')], 403);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('enduser.schema.invalid_json', [], 'Invalid JSON body')], 400);
        }

        if (isset($data['name'])) {
            $nameStr = is_string($data['name']) ? trim($data['name']) : '';
            if ($nameStr === '') {
                return $this->secureJson(['errors' => ['name' => $this->trans('enduser.schema.name_cannot_be_empty', [], 'Field name cannot be empty')]], 422);
            }
            if (strlen($nameStr) > self::MAX_NAME_LENGTH) {
                return $this->secureJson(['errors' => ['name' => $this->trans('enduser.schema.name_too_long', ['%max%' => (string) self::MAX_NAME_LENGTH], sprintf('Field name cannot exceed %d characters', self::MAX_NAME_LENGTH))]], 422);
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $nameStr)) {
                return $this->secureJson(['errors' => ['name' => $this->trans('enduser.schema.name_invalid_chars', [], 'Field name contains invalid control characters')]], 422);
            }
            $field->name = NamingConvention::toCamelCase($nameStr);
        }

        if (array_key_exists('options', $data)) {
            $optionsResult = $this->validateAndNormalizeOptions($field->type, $data['options'], $field->project);
            if (!$optionsResult['valid']) {
                return $this->secureJson(['errors' => ['options' => $optionsResult['error']]], 422);
            }
            $field->options = $optionsResult['normalized'];
        }

        if (isset($data['is_required'])) {
            $field->isRequired = (bool) $data['is_required'];
        }

        if (isset($data['order'])) {
            $order = (int) $data['order'];
            if ($order < 0 || $order > self::MAX_ORDER_VALUE) {
                return $this->secureJson(['errors' => ['order' => $this->trans('enduser.schema.order_out_of_bounds', ['%max%' => (string) self::MAX_ORDER_VALUE], sprintf('Order must be between 0 and %d', self::MAX_ORDER_VALUE))]], 422);
            }
            $field->order = $order;
        }

        $this->em->flush();

        $this->auditService?->log(
            toolName: 'admin_enduser_field_update',
            project: $project,
            input: ['slug' => $slug, 'fields' => array_keys($data)],
            output: ['name' => $field->name, 'slug' => $field->slug],
            status: 'success',
            errorMessage: null,
            createdBy: $this->security->getUser()?->getUserIdentifier() ?? 'system',
            source: 'enduser_schema'
        );
        $this->auditService?->flush();

        return $this->secureJson(['data' => $this->serialize($field)]);
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
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $project = $this->resolveProject($uuid, $request, requireManage: true);
        if ($project instanceof JsonResponse) {
            foreach (self::SECURITY_HEADERS as $k => $v) {
                $project->headers->set($k, $v);
            }
            return $project;
        }

        $field = $this->fieldRepository->findOneByProjectAndSlug($project, $slug);
        if (!$field) {
            return $this->secureJson(['error' => $this->trans('enduser.schema.field_not_found', [], 'Field not found')], 404);
        }

        if ($field->isSystem) {
            return $this->secureJson(['error' => $this->trans('enduser.schema.system_field_cannot_delete', [], 'System fields cannot be deleted')], 403);
        }

        $this->em->remove($field);
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'admin_enduser_field_delete',
            project: $project,
            input: ['slug' => $slug, 'name' => $field->name],
            output: ['deleted' => true],
            status: 'success',
            errorMessage: null,
            createdBy: $this->security->getUser()?->getUserIdentifier() ?? 'system',
            source: 'enduser_schema'
        );
        $this->auditService?->flush();

        return $this->secureJson(null, 204);
    }

    /**
     * Valide et normalise les options d'un champ selon son type.
     *
     * @return array{valid: bool, error: ?string, normalized: ?array}
     */
    private function validateAndNormalizeOptions(string $type, mixed $options, Project $project): array
    {
        if ($options === null) {
            return ['valid' => true, 'error' => null, 'normalized' => null];
        }

        if (!is_array($options)) {
            return [
                'valid'      => false,
                'error'      => $this->trans('enduser.schema.options_must_be_object', [], 'Field options must be an object or array'),
                'normalized' => null,
            ];
        }

        // Vérification de la taille brute JSON des options
        $json = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || strlen($json) > self::MAX_OPTIONS_BYTES) {
            return [
                'valid'      => false,
                'error'      => $this->trans('enduser.schema.options_too_large', ['%max%' => (string) self::MAX_OPTIONS_BYTES], sprintf('Field options exceed maximum size of %d bytes', self::MAX_OPTIONS_BYTES)),
                'normalized' => null,
            ];
        }

        // Type: Relation
        if ($type === 'relation') {
            try {
                $normalized = $this->relationOptionsNormalizer->normalize($options, $project, forStorage: true);
                return ['valid' => true, 'error' => null, 'normalized' => $normalized];
            } catch (\Throwable $e) {
                return ['valid' => false, 'error' => $e->getMessage(), 'normalized' => null];
            }
        }

        // Type: Énumération
        if ($type === 'enumeration') {
            $choices = $options['choices'] ?? $options['values'] ?? $options['enumeration']['list'] ?? $options['list'] ?? null;
            if ($choices !== null) {
                if (!is_array($choices)) {
                    return [
                        'valid'      => false,
                        'error'      => $this->trans('enduser.schema.enum_choices_must_be_array', [], 'Enumeration choices must be an array of values'),
                        'normalized' => null,
                    ];
                }
                if (count($choices) > self::MAX_ENUM_CHOICES) {
                    return [
                        'valid'      => false,
                        'error'      => $this->trans('enduser.schema.enum_too_many_choices', ['%max%' => (string) self::MAX_ENUM_CHOICES], sprintf('Enumeration cannot have more than %d choices', self::MAX_ENUM_CHOICES)),
                        'normalized' => null,
                    ];
                }
                foreach ($choices as $choice) {
                    if (!is_scalar($choice)) {
                        return [
                            'valid'      => false,
                            'error'      => $this->trans('enduser.schema.enum_choice_must_be_scalar', [], 'Each enumeration choice must be a scalar string or number'),
                            'normalized' => null,
                        ];
                    }
                }
            }
        }

        // Types numériques : bornage min / max
        if (in_array($type, ['number', 'decimal', 'rating'], true)) {
            $hasMin = isset($options['min']) && is_numeric($options['min']);
            $hasMax = isset($options['max']) && is_numeric($options['max']);
            if ($hasMin && $hasMax && (float) $options['min'] > (float) $options['max']) {
                return [
                    'valid'      => false,
                    'error'      => $this->trans('enduser.schema.min_greater_than_max', [], 'Minimum value cannot be greater than maximum value'),
                    'normalized' => null,
                ];
            }
        }

        return ['valid' => true, 'error' => null, 'normalized' => $options];
    }

    private function serialize(EndUserField $field): array
    {
        $options = $field->options;
        if ($field->type === 'relation' && $options !== null) {
            $options = $this->relationOptionsNormalizer->normalize($options, $field->project);
        }

        return [
            'id'        => $field->id,
            'name'      => $field->name,
            'label'     => $field->name,
            'slug'      => $field->slug,
            'type'      => $field->type,
            'options'   => $options ?? [],
            'order'     => $field->order,
            'required'  => $field->isRequired,
            'is_system' => $field->isSystem,
        ];
    }
}
