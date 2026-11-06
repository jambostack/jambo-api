<?php

namespace App\Controller\AdminApi;

use App\Exception\SchemaException;
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

#[Route('/admin-api/projects', name: 'admin_api_project_')]
class ProjectController extends AbstractController
{
    use AdminApiControllerTrait;

    public const MAX_PAYLOAD_BYTES = 524288; // 512 KB
    public const MAX_PROJECTS_PER_USER = 100;

    private const SECURITY_HEADERS = [
        'Cache-Control'          => 'no-store, no-cache, must-revalidate',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
    ];

    public function __construct(
        private ProjectRepository $projects,
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
                toolName: 'admin_project_rate_limit_exceeded',
                project: null,
                input: ['ip' => $request->getClientIp()],
                output: null,
                status: 'blocked',
                errorMessage: 'Rate limit exceeded on ProjectController',
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

    private function secureJson(mixed $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, self::SECURITY_HEADERS);
    }

    private function validateUuid(string $uuid): ?JsonResponse
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid)) {
            return $this->secureJson(['error' => $this->trans('admin_api.project.invalid_uuid'), 'code' => 'INVALID_UUID'], 422);
        }
        return null;
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $projects = $this->projects->findByMember($this->getUser());
        return $this->secureJson(['data' => array_map($this->serialize(...), $projects)]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        if ($contentTypeResponse = $this->validateContentType($request)) {
            return $contentTypeResponse;
        }

        $this->requireScope($request, 'projects:write');

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('admin_api.project.payload_too_large', [], 'Project payload exceeds maximum size limit (512 KB).'),
                'code' => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $body = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('admin_api.project.invalid_json'), 'code' => 'INVALID_JSON'], 400);
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return $this->secureJson(['error' => $this->trans('admin_api.project.name_required'), 'code' => 'NAME_REQUIRED'], 422);
        }
        if (mb_strlen($name) > 255) {
            return $this->secureJson(['error' => $this->trans('admin_api.project.name_too_long'), 'code' => 'NAME_TOO_LONG'], 422);
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
            return $this->secureJson([
                'error' => $this->trans('admin_api.project.name_control_chars', [], 'Project name contains invalid control characters.'),
                'code' => 'NAME_CONTROL_CHARS',
            ], 422);
        }

        $existingCount = count($this->projects->findByMember($this->getUser()));
        if ($existingCount >= self::MAX_PROJECTS_PER_USER) {
            return $this->secureJson([
                'error' => $this->trans('admin_api.project.max_projects_exceeded', ['%max%' => self::MAX_PROJECTS_PER_USER], sprintf('Project limit exceeded (maximum %d projects).', self::MAX_PROJECTS_PER_USER)),
                'code' => 'MAX_PROJECTS_EXCEEDED',
            ], 422);
        }

        try {
            $project = $this->provisioner->createProject($this->getUser(), $body);
            $this->auditService?->log(
                toolName: 'admin_api_project_create',
                project: $project,
                input: ['name' => $project->name],
                output: ['uuid' => $project->uuid?->toString()],
                status: 'success',
                source: 'admin_api',
            );
            $this->auditService?->flush();
        } catch (SchemaException $e) {
            return $this->secureJson(['error' => $e->getMessage(), 'code' => 'SCHEMA_ERROR'], $e->statusCode());
        }
        return $this->secureJson(['data' => $this->serialize($project)], 201);
    }

    #[Route('/{uuid}', name: 'show', methods: ['GET'])]
    public function show(string $uuid, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        if ($err = $this->validateUuid($uuid)) {
            return $err;
        }

        try {
            $project = $this->projects->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('admin_api.project.not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }
        if (!$project) {
            return $this->secureJson(['error' => $this->trans('admin_api.project.not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }
        // VIEW = appartenance active au projet (bypass super-admin), aligné sur le studio.
        $this->denyAccessUnlessGranted(ProjectVoter::VIEW, $project);
        return $this->secureJson(['data' => $this->serialize($project)]);
    }

    #[Route('/{uuid}', name: 'update', methods: ['PATCH', 'PUT'])]
    public function update(string $uuid, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        if ($contentTypeResponse = $this->validateContentType($request)) {
            return $contentTypeResponse;
        }

        if ($err = $this->validateUuid($uuid)) {
            return $err;
        }

        $this->requireScope($request, 'projects:write');

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('admin_api.project.payload_too_large', [], 'Project payload exceeds maximum size limit (512 KB).'),
                'code' => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $project = $this->projects->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('admin_api.project.not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }
        if (!$project) {
            return $this->secureJson(['error' => $this->trans('admin_api.project.not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }
        $this->denyAccessUnlessGranted(ProjectVoter::MANAGE, $project);
        try {
            $body = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('admin_api.project.invalid_json'), 'code' => 'INVALID_JSON'], 400);
        }

        if (isset($body['name'])) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                return $this->secureJson(['error' => $this->trans('admin_api.project.name_required'), 'code' => 'NAME_REQUIRED'], 422);
            }
            if (mb_strlen($name) > 255) {
                return $this->secureJson(['error' => $this->trans('admin_api.project.name_too_long'), 'code' => 'NAME_TOO_LONG'], 422);
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
                return $this->secureJson([
                    'error' => $this->trans('admin_api.project.name_control_chars', [], 'Project name contains invalid control characters.'),
                    'code' => 'NAME_CONTROL_CHARS',
                ], 422);
            }
        }

        $this->provisioner->updateProject($project, $body);
        $this->auditService?->log(
            toolName: 'admin_api_project_update',
            project: $project,
            input: ['uuid' => $uuid],
            output: null,
            status: 'success',
            source: 'admin_api',
        );
        $this->auditService?->flush();
        return $this->secureJson(['data' => $this->serialize($project)]);
    }

    #[Route('/{uuid}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $uuid, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        if ($err = $this->validateUuid($uuid)) {
            return $err;
        }

        $this->requireScope($request, 'projects:write');
        try {
            $project = $this->projects->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('admin_api.project.not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }
        if (!$project) {
            return $this->secureJson(['error' => $this->trans('admin_api.project.not_found'), 'code' => 'PROJECT_NOT_FOUND'], 404);
        }
        $this->denyAccessUnlessGranted(ProjectVoter::MANAGE, $project);
        $projectName = $project->name;
        $this->provisioner->deleteProject($project);

        $this->auditService?->log(
            toolName: 'admin_api_project_delete',
            project: null,
            input: ['uuid' => $uuid, 'name' => $projectName],
            output: null,
            status: 'success',
            source: 'admin_api',
        );
        $this->auditService?->flush();

        return new JsonResponse(null, 204, self::SECURITY_HEADERS);
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
