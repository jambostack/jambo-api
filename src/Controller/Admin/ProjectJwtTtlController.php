<?php

namespace App\Controller\Admin;

use App\Entity\Project;
use App\Service\AuditService;
use App\Service\EndUserJwtService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
class ProjectJwtTtlController extends AbstractController
{
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const MAX_PAYLOAD_BYTES = 16384; // 16 KB
    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-store',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?TranslatorInterface $translator = null,
        #[Autowire(service: 'limiter.project_limiter')]
        private readonly ?RateLimiterFactory $projectLimiter = null,
        private readonly ?AuditService $auditService = null,
    ) {}

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    private function checkRateLimit(?Request $request = null): ?JsonResponse
    {
        if ($this->projectLimiter !== null && $request !== null) {
            $identifier = $request->getClientIp() ?: '127.0.0.1';
            try {
                $user = $this->getUser();
                if ($user !== null) {
                    $identifier = $user->getUserIdentifier();
                }
            } catch (\Throwable) {}

            if (false === $this->projectLimiter->create($identifier)->consume(1)->isAccepted()) {
                return $this->secureJson([
                    'error' => $this->trans('project.jwt_ttl.rate_limit_exceeded', [], 'Too many JWT TTL configuration requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            }
        }
        return null;
    }

    #[Route('/api/admin/projects/{uuid}/jwt-ttl', name: 'admin_project_jwt_ttl_get', methods: ['GET'])]
    public function getJwtTtl(string $uuid, ?Request $request = null): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $project = $this->findProject($uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        return $this->secureJson([
            'jwt_access_ttl'  => $project->jwtAccessTtl,
            'jwt_refresh_ttl' => $project->jwtRefreshTtl,
            'defaults' => [
                'access_ttl'  => EndUserJwtService::DEFAULT_ACCESS_TTL,
                'refresh_ttl' => EndUserJwtService::DEFAULT_REFRESH_TTL,
                'max_ttl'     => EndUserJwtService::MAX_TTL,
            ],
        ]);
    }

    #[Route('/api/admin/projects/{uuid}/jwt-ttl', name: 'admin_project_jwt_ttl_update', methods: ['PATCH'])]
    public function updateJwtTtl(string $uuid, Request $request): JsonResponse
    {
        if ($rateLimitResponse = $this->checkRateLimit($request)) {
            return $rateLimitResponse;
        }

        $project = $this->findProject($uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $contentLength = $request->headers->get('Content-Length');
        if ($contentLength !== null && is_numeric($contentLength) && (int) $contentLength > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->trans('project.jwt_ttl.payload_too_large', [], 'Payload exceeds maximum size limit (16 KB).')], 413);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson(['error' => $this->trans('project.jwt_ttl.payload_too_large', [], 'Payload exceeds maximum size limit (16 KB).')], 413);
        }

        $rawContentType = (string) $request->headers->get('Content-Type', '');
        $contentType = strtolower(trim(explode(';', $rawContentType)[0]));
        if ($contentType !== '' && !str_contains($contentType, 'application/json')) {
            return $this->secureJson([
                'error' => $this->trans('project.jwt_ttl.unsupported_content_type', [], 'Unsupported Content-Type header. Expected application/json.'),
                'code' => 'UNSUPPORTED_MEDIA_TYPE',
            ], 415);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('project.invalid_json')], 400);
        }

        $accessVal  = $data['jwt_access_ttl'] ?? null;
        $refreshVal = $data['jwt_refresh_ttl'] ?? null;

        if ($accessVal !== null) {
            $result = $this->validateAndApplyTtl($accessVal, 'access');
            if ($result instanceof JsonResponse) {
                return $result;
            }
            $project->jwtAccessTtl = $result;
        }
        if ($refreshVal !== null) {
            $result = $this->validateAndApplyTtl($refreshVal, 'refresh');
            if ($result instanceof JsonResponse) {
                return $result;
            }
            $project->jwtRefreshTtl = $result;
        }

        $userIdentifier = null;
        try {
            $userIdentifier = $this->getUser()?->getUserIdentifier();
        } catch (\Throwable) {}

        // Cross-check: refresh TTL must be >= access TTL
        $finalAccess  = EndUserJwtService::resolveTtl($project->jwtAccessTtl, EndUserJwtService::DEFAULT_ACCESS_TTL);
        $finalRefresh = EndUserJwtService::resolveTtl($project->jwtRefreshTtl, EndUserJwtService::DEFAULT_REFRESH_TTL);
        if ($finalRefresh < $finalAccess) {
            $this->auditService?->log(
                toolName: 'admin_project_jwt_ttl_update',
                project: $project,
                input: ['jwt_access_ttl' => $finalAccess, 'jwt_refresh_ttl' => $finalRefresh],
                output: ['status' => 'rejected'],
                status: 'failure',
                errorMessage: 'Refresh TTL cannot be less than access TTL',
                createdBy: $userIdentifier,
                source: 'admin_api',
            );
            return $this->secureJson([
                'error' => sprintf(
                    $this->trans('project.jwt_refresh_lt_access'),
                    $finalRefresh, $finalAccess,
                ),
            ], 422);
        }

        $this->em->flush();

        $this->auditService?->log(
            toolName: 'admin_project_jwt_ttl_update',
            project: $project,
            input: [
                'jwt_access_ttl'  => $project->jwtAccessTtl,
                'jwt_refresh_ttl' => $project->jwtRefreshTtl,
            ],
            output: ['status' => 'updated'],
            status: 'success',
            createdBy: $userIdentifier,
            source: 'admin_api',
        );

        return $this->getJwtTtl($uuid, $request);
    }

    private function findProject(string $uuid): Project|JsonResponse
    {
        if (!preg_match(self::UUID_REGEX, $uuid)) {
            return $this->secureJson(['error' => $this->trans('project.not_found')], 404);
        }

        try {
            $project = $this->em->getRepository(Project::class)->findOneBy(['uuid' => $uuid]);
        } catch (\Throwable) {
            return $this->secureJson(['error' => $this->trans('project.not_found')], 404);
        }
        if ($project === null) {
            return $this->secureJson(['error' => $this->trans('project.not_found')], 404);
        }
        $this->denyAccessUnlessGranted('project.manage', $project);
        return $project;
    }

    private function validateAndApplyTtl(mixed $val, string $label): JsonResponse|int|null
    {
        if ($val === null || $val === '' || $val === '0' || $val === 0) {
            return null;
        }

        if (!is_numeric($val) && !is_int($val)) {
            return $this->secureJson([
                'error' => $this->trans('project.jwt_ttl.invalid_format'),
            ], 422);
        }

        $ttl = (int) $val;
        if ($ttl <= 0) {
            return null;
        }

        if ($ttl < 60) {
            $key = $label === 'access' ? 'project.jwt_access_ttl_min' : 'project.jwt_refresh_ttl_min';
            return $this->secureJson([
                'error' => $this->trans($key),
            ], 422);
        }

        if ($ttl > EndUserJwtService::MAX_TTL) {
            $key = $label === 'access' ? 'project.jwt_access_ttl_max' : 'project.jwt_refresh_ttl_max';
            return $this->secureJson([
                'error' => sprintf(
                    $this->trans($key),
                    EndUserJwtService::MAX_TTL,
                ),
            ], 422);
        }

        return $ttl;
    }

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters, 'messages');
        }

        return $default ?? $id;
    }
}
