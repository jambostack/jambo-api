<?php

namespace App\Controller\AdminApi;

use App\Exception\SchemaException;
use App\Repository\CollectionRepository;
use App\Repository\FieldRepository;
use App\Repository\ProjectRepository;
use App\Security\ProjectVoter;
use App\Service\AuditService;
use App\Service\SchemaProvisioner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin-api/projects/{uuid}/collections/{slug}/fields', name: 'admin_api_field_')]
class FieldController extends AbstractController
{
    use AdminApiControllerTrait;

    public const MAX_PAYLOAD_BYTES = 524288; // 512KB
    public const MAX_FIELDS_COUNT = 100;

    private const SECURITY_HEADERS = [
        'Cache-Control'          => 'no-store, no-cache, must-revalidate',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
    ];

    public function __construct(
        private ProjectRepository $projects,
        private CollectionRepository $collections,
        private FieldRepository $fields,
        private SchemaProvisioner $provisioner,
        private ?TranslatorInterface $translator = null,
        #[Autowire(service: 'limiter.admin_api_limiter')]
        private ?RateLimiterFactory $adminApiLimiter = null,
        private ?AuditService $auditService = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters);
        }
        return $default ?? $id;
    }

    private function checkRateLimit(Request $request): ?JsonResponse
    {
        if ($this->adminApiLimiter === null) {
            return null;
        }
        $identifier = $request->getClientIp() ?: '127.0.0.1';
        try {
            $user = $this->getUser();
            if ($user !== null) {
                $identifier = $user->getUserIdentifier();
            }
        } catch (\Throwable) {}

        $pat = $request->attributes->get('_pat');
        if ($pat instanceof \App\Entity\PersonalAccessToken && $pat->user !== null) {
            $identifier = $pat->user->getUserIdentifier();
        }

        if (!$this->adminApiLimiter->create($identifier)->consume(1)->isAccepted()) {
            $this->auditService?->log(
                toolName: 'admin_field_rate_limit_exceeded',
                project: null,
                input: ['ip' => $request->getClientIp()],
                output: null,
                status: 'blocked',
                errorMessage: 'Rate limit exceeded on FieldController',
                createdBy: $identifier,
                source: 'admin_api',
            );
            $this->auditService?->flush();

            return new JsonResponse([
                'error' => $this->trans('admin_api.rate_limit_exceeded', [], 'Too many API requests. Please retry later.'),
                'code' => 'RATE_LIMIT_EXCEEDED',
            ], 429, self::SECURITY_HEADERS);
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
                    'error' => $this->trans('admin_api.invalid_content_type', [], 'Content-Type must be application/json.'),
                    'code'  => 'INVALID_CONTENT_TYPE',
                ], 415);
            }
        }
        return null;
    }

    private function validateCollectionSlug(string $slug): ?JsonResponse
    {
        if (mb_strlen($slug) > 255) {
            return $this->secureJson([
                'error' => $this->trans('admin_api.collection.slug_too_long'),
                'code' => 'SLUG_TOO_LONG',
            ], 422);
        }
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $slug)) {
            return $this->secureJson([
                'error' => $this->trans('admin_api.collection.invalid_slug_format'),
                'code' => 'INVALID_SLUG_FORMAT',
            ], 422);
        }
        return null;
    }

    private function validateFieldSlug(string $slug): ?JsonResponse
    {
        if (mb_strlen($slug) > 255) {
            return $this->secureJson([
                'error' => $this->trans('admin_api.field.slug_too_long'),
                'code' => 'SLUG_TOO_LONG',
            ], 422);
        }
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $slug)) {
            return $this->secureJson([
                'error' => $this->trans('admin_api.field.invalid_slug_format'),
                'code' => 'INVALID_SLUG_FORMAT',
            ], 422);
        }
        return null;
    }

    private function secureJson(mixed $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, self::SECURITY_HEADERS);
    }

    /** @return array{0: ?\App\Entity\Collection, 1: ?JsonResponse} */
    private function resolveCollection(string $uuid, string $slug): array
    {
        if ($err = $this->validateCollectionSlug($slug)) {
            return [null, $err];
        }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid)) {
            return [null, $this->secureJson(['error' => $this->trans('admin_api.field.project_not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404)];
        }
        try {
            $project = $this->projects->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return [null, $this->secureJson(['error' => $this->trans('admin_api.field.project_not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404)];
        }
        if (!$project) {
            return [null, $this->secureJson(['error' => $this->trans('admin_api.field.project_not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404)];
        }
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);
        $c = $this->collections->findOneBy(['project' => $project, 'slug' => $slug]);
        if (!$c) {
            return [null, $this->secureJson(['error' => $this->trans('admin_api.field.collection_not_found'), 'code' => 'COLLECTION_NOT_FOUND'], 404)];
        }
        return [$c, null];
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $uuid, string $slug, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        [$c, $err] = $this->resolveCollection($uuid, $slug);
        if ($err) {
            return $err;
        }
        return $this->secureJson(['data' => array_map(
            fn (\App\Entity\Field $f) => [
                'name'       => $f->name,
                'slug'       => $f->slug,
                'type'       => $f->type,
                'isRequired' => $f->isRequired,
                'order'      => $f->order,
                'options'    => $f->options,
            ],
            $this->fields->findByCollection($c),
        )]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(string $uuid, string $slug, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        if ($contentTypeResponse = $this->validateContentType($request)) {
            return $contentTypeResponse;
        }

        $this->requireScope($request, 'schema:write');
        [$c, $err] = $this->resolveCollection($uuid, $slug);
        if ($err) {
            return $err;
        }
        $this->denyAccessUnlessGranted(ProjectVoter::MANAGE, $c->project);

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->trans('admin_api.field.payload_too_large'), 'code' => 'PAYLOAD_TOO_LARGE'], 413);
        }

        try {
            $body = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('admin_api.field.invalid_json'), 'code' => 'INVALID_JSON'], 400);
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return $this->secureJson(['error' => $this->trans('admin_api.field.name_required'), 'code' => 'NAME_REQUIRED'], 422);
        }
        if (mb_strlen($name) > 255) {
            return $this->secureJson(['error' => $this->trans('admin_api.field.name_too_long'), 'code' => 'NAME_TOO_LONG'], 422);
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
            return $this->secureJson([
                'error' => $this->trans('admin_api.field.name_control_chars', [], 'Field name contains invalid control characters.'),
                'code' => 'NAME_CONTROL_CHARS',
            ], 422);
        }

        if (isset($body['slug'])) {
            $fSlug = trim((string) $body['slug']);
            if (mb_strlen($fSlug) > 255) {
                return $this->secureJson(['error' => $this->trans('admin_api.field.slug_too_long'), 'code' => 'SLUG_TOO_LONG'], 422);
            }
            if ($fSlug !== '' && !preg_match('/^[a-zA-Z0-9_\-]+$/', $fSlug)) {
                return $this->secureJson(['error' => $this->trans('admin_api.field.invalid_slug_format'), 'code' => 'INVALID_SLUG_FORMAT'], 422);
            }
        }

        $currentFieldsCount = count($this->fields->findByCollection($c));
        if ($currentFieldsCount >= self::MAX_FIELDS_COUNT) {
            return $this->secureJson([
                'error' => $this->trans('admin_api.field.max_fields_exceeded', ['%max%' => self::MAX_FIELDS_COUNT], sprintf('Field limit exceeded for this collection (maximum %d fields).', self::MAX_FIELDS_COUNT)),
                'code' => 'MAX_FIELDS_EXCEEDED',
            ], 422);
        }

        try {
            $f = $this->provisioner->addField($c, $body);
            $this->auditService?->log(
                toolName: 'admin_api_field_create',
                project: $c->project,
                input: ['collection' => $slug, 'name' => $f->name, 'slug' => $f->slug],
                output: null,
                status: 'success',
                source: 'admin_api',
            );
            $this->auditService?->flush();
        } catch (SchemaException $e) {
            return $this->secureJson(['error' => $e->getMessage(), 'code' => 'SCHEMA_ERROR'], $e->statusCode());
        }
        return $this->secureJson(['data' => ['name' => $f->name, 'slug' => $f->slug, 'type' => $f->type, 'isRequired' => $f->isRequired]], 201);
    }

    #[Route('/{fieldSlug}', name: 'update', methods: ['PATCH', 'PUT'])]
    public function update(string $uuid, string $slug, string $fieldSlug, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        if ($contentTypeResponse = $this->validateContentType($request)) {
            return $contentTypeResponse;
        }

        if ($fieldSlugResponse = $this->validateFieldSlug($fieldSlug)) {
            return $fieldSlugResponse;
        }

        $this->requireScope($request, 'schema:write');
        [$c, $err] = $this->resolveCollection($uuid, $slug);
        if ($err) {
            return $err;
        }
        $this->denyAccessUnlessGranted(ProjectVoter::MANAGE, $c->project);
        $f = $this->fields->findOneBy(['collection' => $c, 'slug' => $fieldSlug]);
        if (!$f) {
            return $this->secureJson(['error' => $this->trans('admin_api.field.not_found'), 'code' => 'FIELD_NOT_FOUND'], 404);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->trans('admin_api.field.payload_too_large'), 'code' => 'PAYLOAD_TOO_LARGE'], 413);
        }

        try {
            $body = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('admin_api.field.invalid_json'), 'code' => 'INVALID_JSON'], 400);
        }

        if (isset($body['name'])) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                return $this->secureJson(['error' => $this->trans('admin_api.field.name_required'), 'code' => 'NAME_REQUIRED'], 422);
            }
            if (mb_strlen($name) > 255) {
                return $this->secureJson(['error' => $this->trans('admin_api.field.name_too_long'), 'code' => 'NAME_TOO_LONG'], 422);
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
                return $this->secureJson([
                    'error' => $this->trans('admin_api.field.name_control_chars', [], 'Field name contains invalid control characters.'),
                    'code' => 'NAME_CONTROL_CHARS',
                ], 422);
            }
        }

        if (isset($body['slug'])) {
            $fSlug = trim((string) $body['slug']);
            if (mb_strlen($fSlug) > 255) {
                return $this->secureJson(['error' => $this->trans('admin_api.field.slug_too_long'), 'code' => 'SLUG_TOO_LONG'], 422);
            }
            if ($fSlug !== '' && !preg_match('/^[a-zA-Z0-9_\-]+$/', $fSlug)) {
                return $this->secureJson(['error' => $this->trans('admin_api.field.invalid_slug_format'), 'code' => 'INVALID_SLUG_FORMAT'], 422);
            }
        }

        try {
            $this->provisioner->updateField($f, $body);
            $this->auditService?->log(
                toolName: 'admin_api_field_update',
                project: $c->project,
                input: ['collection' => $slug, 'field' => $fieldSlug],
                output: null,
                status: 'success',
                source: 'admin_api',
            );
            $this->auditService?->flush();
        } catch (SchemaException $e) {
            return $this->secureJson(['error' => $e->getMessage(), 'code' => 'SCHEMA_ERROR'], $e->statusCode());
        }
        return $this->secureJson(['data' => ['slug' => $f->slug, 'type' => $f->type]]);
    }

    #[Route('/{fieldSlug}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $uuid, string $slug, string $fieldSlug, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        if ($fieldSlugResponse = $this->validateFieldSlug($fieldSlug)) {
            return $fieldSlugResponse;
        }

        $this->requireScope($request, 'schema:write');
        [$c, $err] = $this->resolveCollection($uuid, $slug);
        if ($err) {
            return $err;
        }
        $this->denyAccessUnlessGranted(ProjectVoter::MANAGE, $c->project);
        $f = $this->fields->findOneBy(['collection' => $c, 'slug' => $fieldSlug]);
        if (!$f) {
            return $this->secureJson(['error' => $this->trans('admin_api.field.not_found'), 'code' => 'FIELD_NOT_FOUND'], 404);
        }
        $this->provisioner->deleteField($f);

        $this->auditService?->log(
            toolName: 'admin_api_field_delete',
            project: $c->project,
            input: ['uuid' => $uuid, 'collection' => $slug, 'field' => $fieldSlug, 'name' => $f->name],
            output: null,
            status: 'success',
            source: 'admin_api',
        );
        $this->auditService?->flush();

        return new JsonResponse(null, 204, self::SECURITY_HEADERS);
    }
}
