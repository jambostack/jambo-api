<?php

namespace App\Controller\AdminApi;

use App\Entity\ApiToken;
use App\Entity\PersonalAccessToken;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Repository\ProjectRepository;
use App\Service\AuditService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Controller for managing Project API Keys (ApiToken).
 * Accessible through Admin API (/admin-api/projects/{projectUuid}/api-keys)
 * and Project API (/api/projects/{projectUuid}/api-keys).
 */
class ApiKeyController extends AbstractController
{
    use AdminApiControllerTrait;

    public const ALLOWED_ABILITIES = ['read', 'create', 'update', 'delete', '*'];
    public const MAX_PROJECT_API_KEYS = 50;
    public const MAX_PAYLOAD_BYTES = 32768; // 32 KB

    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    private const SECURITY_HEADERS = [
        'Cache-Control'          => 'no-store, no-cache, must-revalidate',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
    ];

    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly ApiTokenRepository $apiTokenRepository,
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.secret%')]
        private readonly string $appSecret = '',
        #[Autowire(service: 'limiter.token_management_limiter')]
        private readonly ?RateLimiterFactory $tokenLimiter = null,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?AuditService $auditService = null,
    ) {}

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            $translated = $this->translator->trans($id, $parameters, 'messages');
            if ($translated !== $id) {
                return $translated;
            }
        }
        return $default ?? $id;
    }

    private function checkRateLimit(Request $request): ?JsonResponse
    {
        if ($this->tokenLimiter === null) {
            return null;
        }

        $identifier = $request->getClientIp() ?: '127.0.0.1';
        try {
            $user = $this->getUser();
            if ($user instanceof User) {
                $identifier = $user->getUserIdentifier();
            }
        } catch (\Throwable) {}

        $pat = $request->attributes->get('_pat');
        if ($pat instanceof PersonalAccessToken && $pat->user instanceof User) {
            $identifier = $pat->user->getUserIdentifier();
        }

        if (!$this->tokenLimiter->create($identifier)->consume(1)->isAccepted()) {
            return $this->secureJson([
                'error' => $this->trans('api_key.rate_limit_exceeded', [], 'Too many API key requests. Please retry later.'),
                'code'  => 'RATE_LIMIT_EXCEEDED',
            ], 429);
        }

        return null;
    }

    private function resolveProject(string $projectUuid, Request $request): Project|JsonResponse
    {
        if (!preg_match(self::UUID_REGEX, $projectUuid)) {
            return $this->secureJson([
                'error' => $this->trans('api_key.project_not_found', [], 'Project not found.'),
                'code'  => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('api_key.project_not_found', [], 'Project not found.'),
                'code'  => 'PROJECT_NOT_FOUND',
            ], 404);
        }
        if ($project === null) {
            return $this->secureJson([
                'error' => $this->trans('api_key.project_not_found', [], 'Project not found.'),
                'code'  => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        // 1. Session user authorization
        $currentUser = null;
        try {
            $user = $this->getUser();
            if ($user instanceof User) {
                $currentUser = $user;
            }
        } catch (\Throwable) {}

        if ($currentUser !== null) {
            if (in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true) || $project->hasMember($currentUser)) {
                return $project;
            }
        }

        // 2. PAT authorization
        $pat = $request->attributes->get('_pat');
        if ($pat instanceof PersonalAccessToken) {
            if ($pat->can('tokens:manage') || $pat->can('*') || $pat->can('projects:write')) {
                $patUser = $pat->user;
                if ($patUser !== null && (in_array('ROLE_SUPER_ADMIN', $patUser->getRoles(), true) || $project->hasMember($patUser))) {
                    return $project;
                }
            } else {
                return $this->secureJson([
                    'error' => $this->trans('api_key.lacks_scope', [], 'Access token lacks required scope for API key management.'),
                    'code'  => 'FORBIDDEN_SCOPE',
                ], 403);
            }
        }

        return $this->secureJson([
            'error' => $this->trans('api_key.unauthorized', [], 'Unauthorized access to project API keys.'),
            'code'  => 'UNAUTHORIZED',
        ], 403);
    }

    private function getOperatorIdentifier(Request $request): string
    {
        try {
            $user = $this->getUser();
            if ($user instanceof User) {
                return $user->getUserIdentifier();
            }
        } catch (\Throwable) {}

        $pat = $request->attributes->get('_pat');
        if ($pat instanceof PersonalAccessToken && $pat->user instanceof User) {
            return $pat->user->getUserIdentifier();
        }

        return $request->getClientIp() ?: 'api';
    }

    #[Route('/admin-api/projects/{projectUuid}/api-keys', name: 'admin_api_project_api_keys_index', methods: ['GET'])]
    #[Route('/api/projects/{projectUuid}/api-keys', name: 'api_project_api_keys_index', methods: ['GET'])]
    public function index(string $projectUuid, Request $request): JsonResponse
    {
        $limitRes = $this->checkRateLimit($request);
        if ($limitRes !== null) {
            return $limitRes;
        }

        $project = $this->resolveProject($projectUuid, $request);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $tokens = $this->apiTokenRepository->findByProject($project);

        return $this->secureJson([
            'data' => array_map(fn (ApiToken $t) => [
                'id'         => $t->id,
                'name'       => $t->name,
                'abilities'  => $t->abilities,
                'lastUsedAt' => $t->lastUsedAt?->format(\DateTimeInterface::ATOM),
                'expiresAt'  => $t->expiresAt?->format(\DateTimeInterface::ATOM),
                'createdAt'  => $t->createdAt->format(\DateTimeInterface::ATOM),
            ], $tokens),
        ]);
    }

    #[Route('/admin-api/projects/{projectUuid}/api-keys', name: 'admin_api_project_api_keys_create', methods: ['POST'])]
    #[Route('/api/projects/{projectUuid}/api-keys', name: 'api_project_api_keys_create', methods: ['POST'])]
    public function create(string $projectUuid, Request $request): JsonResponse
    {
        $limitRes = $this->checkRateLimit($request);
        if ($limitRes !== null) {
            return $limitRes;
        }

        $project = $this->resolveProject($projectUuid, $request);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $operator = $this->getOperatorIdentifier($request);

        $contentType = (string) $request->headers->get('Content-Type', '');
        if ($contentType !== '' && !str_contains(strtolower($contentType), 'application/json')) {
            $this->auditService?->log('api_key_create_failure', $project, ['reason' => 'invalid_content_type', 'content_type' => $contentType], null, 'failure', 'Content-Type must be application/json.', $operator, 'api_key_controller');
            $this->auditService?->flush();
            return $this->secureJson([
                'error' => $this->trans('api_key.invalid_content_type', [], 'Content-Type must be application/json.'),
                'code'  => 'INVALID_CONTENT_TYPE',
            ], 415);
        }

        $existingCount = count($this->apiTokenRepository->findByProject($project));
        if ($existingCount >= self::MAX_PROJECT_API_KEYS) {
            $this->auditService?->log('api_key_create_failure', $project, ['reason' => 'max_limit_reached'], null, 'failure', 'Maximum API key limit reached for project', $operator, 'api_key_controller');
            $this->auditService?->flush();
            return $this->secureJson([
                'error' => $this->trans('api_key.max_limit_reached', [], 'Maximum API key limit reached for this project (50 keys max).'),
                'code'  => 'MAX_KEYS_REACHED',
            ], 422);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            $this->auditService?->log('api_key_create_failure', $project, ['reason' => 'payload_too_large'], null, 'failure', 'Payload exceeds maximum size limit.', $operator, 'api_key_controller');
            $this->auditService?->flush();
            return $this->secureJson([
                'error' => $this->trans('api_key.payload_too_large', [], 'Payload exceeds maximum size limit.'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            $this->auditService?->log('api_key_create_failure', $project, ['reason' => 'invalid_json'], null, 'failure', 'Invalid JSON body.', $operator, 'api_key_controller');
            $this->auditService?->flush();
            return $this->secureJson([
                'error' => $this->trans('api_key.invalid_json', [], 'Invalid JSON body.'),
                'code'  => 'INVALID_JSON',
            ], 400);
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $this->auditService?->log('api_key_create_failure', $project, ['reason' => 'name_required'], null, 'failure', 'API key name is required.', $operator, 'api_key_controller');
            $this->auditService?->flush();
            return $this->secureJson([
                'error' => $this->trans('api_key.name_required', [], 'API key name is required.'),
                'code'  => 'NAME_REQUIRED',
            ], 422);
        }
        if (mb_strlen($name) > 255) {
            $this->auditService?->log('api_key_create_failure', $project, ['reason' => 'name_too_long'], null, 'failure', 'API key name must not exceed 255 characters.', $operator, 'api_key_controller');
            $this->auditService?->flush();
            return $this->secureJson([
                'error' => $this->trans('api_key.name_too_long', [], 'API key name must not exceed 255 characters.'),
                'code'  => 'NAME_TOO_LONG',
            ], 422);
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
            $this->auditService?->log('api_key_create_failure', $project, ['reason' => 'name_control_chars'], null, 'failure', 'API key name contains invalid control characters.', $operator, 'api_key_controller');
            $this->auditService?->flush();
            return $this->secureJson([
                'error' => $this->trans('api_key.name_control_chars', [], 'API key name contains invalid control characters.'),
                'code'  => 'NAME_CONTROL_CHARS',
            ], 422);
        }

        $rawAbilities = $data['abilities'] ?? ['read'];
        if (!is_array($rawAbilities) || empty($rawAbilities)) {
            $this->auditService?->log('api_key_create_failure', $project, ['reason' => 'invalid_abilities'], null, 'failure', 'Abilities must be a non-empty array of valid permissions.', $operator, 'api_key_controller');
            $this->auditService?->flush();
            return $this->secureJson([
                'error' => $this->trans('api_key.invalid_abilities', [], 'Abilities must be an array of valid permissions (read, create, update, delete, *).'),
                'code'  => 'INVALID_ABILITIES',
            ], 422);
        }

        $abilities = [];
        foreach ($rawAbilities as $ab) {
            if (!is_string($ab) || !in_array($ab, self::ALLOWED_ABILITIES, true)) {
                $this->auditService?->log('api_key_create_failure', $project, ['reason' => 'invalid_abilities'], null, 'failure', 'Abilities must be an array of valid permissions.', $operator, 'api_key_controller');
                $this->auditService?->flush();
                return $this->secureJson([
                    'error' => $this->trans('api_key.invalid_abilities', [], 'Abilities must be an array of valid permissions (read, create, update, delete, *).'),
                    'code'  => 'INVALID_ABILITIES',
                ], 422);
            }
            $abilities[] = $ab;
        }
        $abilities = array_values(array_unique($abilities));

        $expiresAt = null;
        if (!empty($data['expires_at'])) {
            try {
                $expiresAt = new \DateTimeImmutable((string) $data['expires_at']);
            } catch (\Exception) {
                $this->auditService?->log('api_key_create_failure', $project, ['reason' => 'invalid_expires_at'], null, 'failure', 'Invalid expiration date format.', $operator, 'api_key_controller');
                $this->auditService?->flush();
                return $this->secureJson([
                    'error' => $this->trans('api_key.invalid_expires_at', [], 'Invalid expiration date format.'),
                    'code'  => 'INVALID_EXPIRES_AT',
                ], 422);
            }
            if ($expiresAt <= new \DateTimeImmutable()) {
                $this->auditService?->log('api_key_create_failure', $project, ['reason' => 'expires_must_be_future'], null, 'failure', 'Expiration date must be in the future.', $operator, 'api_key_controller');
                $this->auditService?->flush();
                return $this->secureJson([
                    'error' => $this->trans('api_key.expires_future', [], 'Expiration date must be in the future.'),
                    'code'  => 'EXPIRES_MUST_BE_FUTURE',
                ], 422);
            }
        }

        $plainToken = ApiToken::generatePlainToken();

        $token = new ApiToken();
        $token->project      = $project;
        $token->name         = $name;
        $token->tokenHash    = ApiToken::hashToken($plainToken, $this->appSecret);
        $token->tokenVersion = 2;
        $token->abilities    = $abilities;
        $token->expiresAt    = $expiresAt;

        $this->em->persist($token);
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'api_key_create',
            project: $project,
            input: ['token_id' => $token->id, 'name' => $name, 'abilities' => $abilities, 'expires_at' => $expiresAt?->format(DATE_ATOM)],
            output: ['id' => $token->id, 'success' => true],
            status: 'success',
            createdBy: $operator,
            source: 'api_key_controller'
        );
        $this->auditService?->flush();

        return $this->secureJson([
            'id'        => $token->id,
            'name'      => $token->name,
            'token'     => $plainToken, // plaintext shown only once
            'abilities' => $token->abilities,
            'expiresAt' => $token->expiresAt?->format(\DateTimeInterface::ATOM),
            'createdAt' => $token->createdAt->format(\DateTimeInterface::ATOM),
        ], 201);
    }

    #[Route('/admin-api/projects/{projectUuid}/api-keys/{id}', name: 'admin_api_project_api_keys_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[Route('/api/projects/{projectUuid}/api-keys/{id}', name: 'api_project_api_keys_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(string $projectUuid, int $id, Request $request): JsonResponse
    {
        $limitRes = $this->checkRateLimit($request);
        if ($limitRes !== null) {
            return $limitRes;
        }

        $project = $this->resolveProject($projectUuid, $request);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $token = $this->apiTokenRepository->findOneBy(['id' => $id, 'project' => $project]);
        if ($token === null) {
            return $this->secureJson([
                'error' => $this->trans('api_key.not_found', [], 'API key not found.'),
                'code'  => 'API_KEY_NOT_FOUND',
            ], 404);
        }

        return $this->secureJson([
            'id'         => $token->id,
            'name'       => $token->name,
            'abilities'  => $token->abilities,
            'lastUsedAt' => $token->lastUsedAt?->format(\DateTimeInterface::ATOM),
            'expiresAt'  => $token->expiresAt?->format(\DateTimeInterface::ATOM),
            'createdAt'  => $token->createdAt->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/admin-api/projects/{projectUuid}/api-keys/{id}', name: 'admin_api_project_api_keys_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[Route('/api/projects/{projectUuid}/api-keys/{id}', name: 'api_project_api_keys_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(string $projectUuid, int $id, Request $request): JsonResponse
    {
        $limitRes = $this->checkRateLimit($request);
        if ($limitRes !== null) {
            return $limitRes;
        }

        $project = $this->resolveProject($projectUuid, $request);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $operator = $this->getOperatorIdentifier($request);

        $token = $this->apiTokenRepository->findOneBy(['id' => $id, 'project' => $project]);
        if ($token === null) {
            $this->auditService?->log('api_key_update_failure', $project, ['id' => $id, 'reason' => 'not_found'], null, 'failure', 'API key not found.', $operator, 'api_key_controller');
            $this->auditService?->flush();
            return $this->secureJson([
                'error' => $this->trans('api_key.not_found', [], 'API key not found.'),
                'code'  => 'API_KEY_NOT_FOUND',
            ], 404);
        }

        $contentType = (string) $request->headers->get('Content-Type', '');
        if ($contentType !== '' && !str_contains(strtolower($contentType), 'application/json')) {
            $this->auditService?->log('api_key_update_failure', $project, ['id' => $id, 'reason' => 'invalid_content_type', 'content_type' => $contentType], null, 'failure', 'Content-Type must be application/json.', $operator, 'api_key_controller');
            $this->auditService?->flush();
            return $this->secureJson([
                'error' => $this->trans('api_key.invalid_content_type', [], 'Content-Type must be application/json.'),
                'code'  => 'INVALID_CONTENT_TYPE',
            ], 415);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            $this->auditService?->log('api_key_update_failure', $project, ['id' => $id, 'reason' => 'payload_too_large'], null, 'failure', 'Payload exceeds maximum size limit.', $operator, 'api_key_controller');
            $this->auditService?->flush();
            return $this->secureJson([
                'error' => $this->trans('api_key.payload_too_large', [], 'Payload exceeds maximum size limit.'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            $this->auditService?->log('api_key_update_failure', $project, ['id' => $id, 'reason' => 'invalid_json'], null, 'failure', 'Invalid JSON body.', $operator, 'api_key_controller');
            $this->auditService?->flush();
            return $this->secureJson([
                'error' => $this->trans('api_key.invalid_json', [], 'Invalid JSON body.'),
                'code'  => 'INVALID_JSON',
            ], 400);
        }

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return $this->secureJson([
                    'error' => $this->trans('api_key.name_empty', [], 'API key name cannot be empty.'),
                    'code'  => 'NAME_REQUIRED',
                ], 422);
            }
            if (mb_strlen($name) > 255) {
                return $this->secureJson([
                    'error' => $this->trans('api_key.name_too_long', [], 'API key name must not exceed 255 characters.'),
                    'code'  => 'NAME_TOO_LONG',
                ], 422);
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
                return $this->secureJson([
                    'error' => $this->trans('api_key.name_control_chars', [], 'API key name contains invalid control characters.'),
                    'code'  => 'NAME_CONTROL_CHARS',
                ], 422);
            }
            $token->name = $name;
        }

        if (isset($data['abilities'])) {
            if (!is_array($data['abilities']) || empty($data['abilities'])) {
                return $this->secureJson([
                    'error' => $this->trans('api_key.invalid_abilities', [], 'Abilities must be an array of valid permissions (read, create, update, delete, *).'),
                    'code'  => 'INVALID_ABILITIES',
                ], 422);
            }
            $abilities = [];
            foreach ($data['abilities'] as $ab) {
                if (!is_string($ab) || !in_array($ab, self::ALLOWED_ABILITIES, true)) {
                    return $this->secureJson([
                        'error' => $this->trans('api_key.invalid_abilities', [], 'Abilities must be an array of valid permissions (read, create, update, delete, *).'),
                        'code'  => 'INVALID_ABILITIES',
                    ], 422);
                }
                $abilities[] = $ab;
            }
            $token->abilities = array_values(array_unique($abilities));
        }

        $this->em->flush();

        $this->auditService?->log(
            toolName: 'api_key_update',
            project: $project,
            input: ['token_id' => $token->id, 'name' => $token->name, 'abilities' => $token->abilities],
            output: ['success' => true],
            status: 'success',
            createdBy: $operator,
            source: 'api_key_controller'
        );
        $this->auditService?->flush();

        return $this->secureJson([
            'id'        => $token->id,
            'name'      => $token->name,
            'abilities' => $token->abilities,
        ]);
    }

    #[Route('/admin-api/projects/{projectUuid}/api-keys/{id}', name: 'admin_api_project_api_keys_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[Route('/api/projects/{projectUuid}/api-keys/{id}', name: 'api_project_api_keys_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(string $projectUuid, int $id, Request $request): JsonResponse
    {
        $limitRes = $this->checkRateLimit($request);
        if ($limitRes !== null) {
            return $limitRes;
        }

        $project = $this->resolveProject($projectUuid, $request);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $operator = $this->getOperatorIdentifier($request);

        $token = $this->apiTokenRepository->findOneBy(['id' => $id, 'project' => $project]);
        if ($token === null) {
            $this->auditService?->log('api_key_delete_failure', $project, ['id' => $id, 'reason' => 'not_found'], null, 'failure', 'API key not found.', $operator, 'api_key_controller');
            $this->auditService?->flush();
            return $this->secureJson([
                'error' => $this->trans('api_key.not_found', [], 'API key not found.'),
                'code'  => 'API_KEY_NOT_FOUND',
            ], 404);
        }

        $tokenId = $token->id;
        $tokenName = $token->name;

        $this->em->remove($token);
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'api_key_delete',
            project: $project,
            input: ['token_id' => $tokenId, 'name' => $tokenName],
            output: ['success' => true],
            status: 'success',
            createdBy: $operator,
            source: 'api_key_controller'
        );
        $this->auditService?->flush();

        return $this->secureJson(null, 204);
    }
}
