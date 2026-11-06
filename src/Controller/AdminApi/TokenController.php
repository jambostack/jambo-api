<?php

namespace App\Controller\AdminApi;

use App\Entity\ApiToken;
use App\Entity\PersonalAccessToken;
use App\Entity\User;
use App\Repository\PersonalAccessTokenRepository;
use App\Service\AuditService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin-api/tokens', name: 'admin_api_token_')]
class TokenController extends AbstractController
{
    use AdminApiControllerTrait;

    public const ALLOWED_SCOPES = [
        'schema:write',
        'projects:write',
        'content:write',
        'content:read',
        'tokens:manage',
        '*',
    ];

    public const MAX_TOKENS_PER_USER = 50;
    public const MAX_PAYLOAD_BYTES = 32768; // 32 KB

    private const SECURITY_HEADERS = [
        'Cache-Control'          => 'no-store, no-cache, must-revalidate',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
    ];

    public function __construct(
        private PersonalAccessTokenRepository $repo,
        private EntityManagerInterface $em,
        private string $appSecret,
        #[Autowire(service: 'limiter.token_management_limiter')]
        private ?RateLimiterFactory $tokenLimiter = null,
        private ?TranslatorInterface $translator = null,
        private ?AuditService $auditService = null,
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

    private function resolveUser(Request $request): ?User
    {
        try {
            $user = $this->getUser();
            if ($user instanceof User) {
                return $user;
            }
        } catch (\Throwable) {}

        $pat = $request->attributes->get('_pat');
        if ($pat instanceof PersonalAccessToken && $pat->user instanceof User) {
            return $pat->user;
        }

        return null;
    }

    private function getUserIdentifier(Request $request): ?string
    {
        return $this->resolveUser($request)?->getUserIdentifier();
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $this->consumeRateLimit($request);

        $user = $this->resolveUser($request);
        if ($user === null) {
            return $this->secureJson(['error' => $this->trans('token.unauthorized', [], 'Unauthorized.')], 401);
        }

        $tokens = $this->repo->findByUser($user);
        return $this->secureJson(['data' => array_map(fn (PersonalAccessToken $t) => [
            'id' => $t->id,
            'name' => $t->name,
            'scopes' => $t->scopes,
            'lastUsedAt' => $t->lastUsedAt?->format(DATE_ATOM),
            'createdAt' => $t->createdAt->format(DATE_ATOM),
        ], $tokens)]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->consumeRateLimit($request);

        $contentType = (string) $request->headers->get('Content-Type', '');
        if ($contentType !== '' && !str_contains(strtolower($contentType), 'application/json')) {
            $this->auditService?->log(
                toolName: 'personal_access_token_create_failure',
                project: null,
                input: ['reason' => 'invalid_content_type', 'content_type' => $contentType],
                output: null,
                status: 'failure',
                errorMessage: 'Content-Type must be application/json.',
                createdBy: $this->getUserIdentifier($request),
                source: 'admin_api_token',
            );
            $this->auditService?->flush();
            return $this->secureJson(['error' => $this->trans('token.invalid_content_type', [], 'Content-Type must be application/json.')], 415);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            $this->auditService?->log(
                toolName: 'personal_access_token_create_failure',
                project: null,
                input: ['reason' => 'payload_too_large'],
                output: null,
                status: 'failure',
                errorMessage: 'Payload exceeds maximum size limit.',
                createdBy: $this->getUserIdentifier($request),
                source: 'admin_api_token',
            );
            $this->auditService?->flush();
            return $this->secureJson(['error' => $this->trans('token.payload_too_large', [], 'Payload exceeds maximum size limit.')], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            $this->auditService?->log(
                toolName: 'personal_access_token_create_failure',
                project: null,
                input: ['reason' => 'invalid_json'],
                output: null,
                status: 'failure',
                errorMessage: 'Invalid JSON body.',
                createdBy: $this->getUserIdentifier($request),
                source: 'admin_api_token',
            );
            $this->auditService?->flush();
            return $this->secureJson(['error' => $this->trans('token.invalid_json', [], 'Invalid JSON body.')], 400);
        }

        $rawName = trim((string) ($data['name'] ?? ''));
        if ($rawName === '') {
            $this->auditService?->log(
                toolName: 'personal_access_token_create_failure',
                project: null,
                input: ['reason' => 'name_required'],
                output: null,
                status: 'failure',
                errorMessage: 'Token name is required.',
                createdBy: $this->getUserIdentifier($request),
                source: 'admin_api_token',
            );
            $this->auditService?->flush();
            return $this->secureJson(['error' => $this->trans('token.name_required', [], 'Token name is required.')], 422);
        }
        if (mb_strlen($rawName) > 255) {
            $this->auditService?->log(
                toolName: 'personal_access_token_create_failure',
                project: null,
                input: ['reason' => 'name_too_long'],
                output: null,
                status: 'failure',
                errorMessage: 'Token name must not exceed 255 characters.',
                createdBy: $this->getUserIdentifier($request),
                source: 'admin_api_token',
            );
            $this->auditService?->flush();
            return $this->secureJson(['error' => $this->trans('token.name_too_long', [], 'Token name must not exceed 255 characters.')], 422);
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $rawName)) {
            $this->auditService?->log(
                toolName: 'personal_access_token_create_failure',
                project: null,
                input: ['reason' => 'name_control_chars'],
                output: null,
                status: 'failure',
                errorMessage: 'Token name contains invalid control characters.',
                createdBy: $this->getUserIdentifier($request),
                source: 'admin_api_token',
            );
            $this->auditService?->flush();
            return $this->secureJson(['error' => $this->trans('token.name_control_chars', [], 'Token name contains invalid control characters.')], 422);
        }

        $rawScopes = $data['scopes'] ?? ['schema:write'];
        if (!is_array($rawScopes) || empty($rawScopes)) {
            $this->auditService?->log(
                toolName: 'personal_access_token_create_failure',
                project: null,
                input: ['reason' => 'scopes_required'],
                output: null,
                status: 'failure',
                errorMessage: 'Scopes must be a non-empty array.',
                createdBy: $this->getUserIdentifier($request),
                source: 'admin_api_token',
            );
            $this->auditService?->flush();
            return $this->secureJson(['error' => $this->trans('token.scopes_required', [], 'Scopes must be a non-empty array.')], 422);
        }

        $scopes = [];
        foreach ($rawScopes as $s) {
            if (!is_string($s) || !in_array($s, self::ALLOWED_SCOPES, true)) {
                $scopeStr = is_scalar($s) ? (string) $s : gettype($s);
                $this->auditService?->log(
                    toolName: 'personal_access_token_create_failure',
                    project: null,
                    input: ['reason' => 'invalid_scope', 'scope' => $scopeStr],
                    output: null,
                    status: 'failure',
                    errorMessage: sprintf('Invalid scope "%s". Allowed scopes: %s', $scopeStr, implode(', ', self::ALLOWED_SCOPES)),
                    createdBy: $this->getUserIdentifier($request),
                    source: 'admin_api_token',
                );
                $this->auditService?->flush();
                return $this->secureJson(['error' => $this->trans('token.invalid_scope', ['%scope%' => $scopeStr, '%allowed%' => implode(', ', self::ALLOWED_SCOPES)], sprintf('Invalid scope "%s". Allowed scopes: %s', $scopeStr, implode(', ', self::ALLOWED_SCOPES)))], 422);
            }
            $scopes[] = $s;
        }
        $scopes = array_values(array_unique($scopes));

        // Anti-Privilege Escalation: If caller is authenticated via a PAT, prevent granting scopes not held by authorizer
        $authorizingPat = $request->attributes->get('_pat');
        if ($authorizingPat instanceof PersonalAccessToken) {
            if ((in_array('*', $scopes, true) || in_array('tokens:manage', $scopes, true)) && !$authorizingPat->can('*') && !$authorizingPat->can('tokens:manage')) {
                $this->auditService?->log(
                    toolName: 'personal_access_token_create_failure',
                    project: null,
                    input: ['reason' => 'admin_scope_forbidden', 'scopes' => $scopes],
                    output: null,
                    status: 'failure',
                    errorMessage: 'Cannot grant administrative scopes not held by the authorizing token.',
                    createdBy: $this->getUserIdentifier($request),
                    source: 'admin_api_token',
                );
                $this->auditService?->flush();
                return $this->secureJson(['error' => $this->trans('token.admin_scope_forbidden', [], 'Cannot grant administrative scopes not held by the authorizing token.')], 403);
            }
            if (!$authorizingPat->can('*') && !$authorizingPat->can('tokens:manage')) {
                foreach ($scopes as $scope) {
                    if (!$authorizingPat->can($scope)) {
                        $this->auditService?->log(
                            toolName: 'personal_access_token_create_failure',
                            project: null,
                            input: ['reason' => 'privilege_escalation_blocked', 'scope' => $scope],
                            output: null,
                            status: 'failure',
                            errorMessage: sprintf('Privilege escalation blocked: cannot grant scope "%s" not held by the authorizing token.', $scope),
                            createdBy: $this->getUserIdentifier($request),
                            source: 'admin_api_token',
                        );
                        $this->auditService?->flush();
                        return $this->secureJson(['error' => $this->trans('token.escalation_blocked', ['%scope%' => $scope], sprintf('Privilege escalation blocked: cannot grant scope "%s" not held by the authorizing token.', $scope))], 403);
                    }
                }
            }
        }

        $user = $this->resolveUser($request);
        if ($user === null) {
            return $this->secureJson(['error' => $this->trans('token.unauthorized', [], 'Unauthorized.')], 401);
        }

        if ($this->repo->countByUser($user) >= self::MAX_TOKENS_PER_USER) {
            $this->auditService?->log(
                toolName: 'personal_access_token_create_failure',
                project: null,
                input: ['reason' => 'max_tokens_reached', 'max' => self::MAX_TOKENS_PER_USER],
                output: null,
                status: 'failure',
                errorMessage: sprintf('Maximum number of personal access tokens reached (%d).', self::MAX_TOKENS_PER_USER),
                createdBy: $user->getUserIdentifier(),
                source: 'admin_api_token',
            );
            $this->auditService?->flush();
            return $this->secureJson(['error' => $this->trans('token.max_tokens_reached', ['%max%' => self::MAX_TOKENS_PER_USER], sprintf('Maximum number of personal access tokens reached (%d).', self::MAX_TOKENS_PER_USER))], 422);
        }

        $plain = 'jbo_pat_' . ApiToken::generatePlainToken();
        $t = new PersonalAccessToken();
        $t->name = $rawName;
        $t->user = $user;
        $t->scopes = $scopes;
        $t->tokenHash = ApiToken::hashToken($plain, $this->appSecret);
        $t->tokenVersion = 2;

        if (!empty($data['expires_at'])) {
            try {
                $expiresAt = new \DateTimeImmutable((string) $data['expires_at']);
            } catch (\Exception) {
                $this->auditService?->log(
                    toolName: 'personal_access_token_create_failure',
                    project: null,
                    input: ['reason' => 'invalid_expires_at'],
                    output: null,
                    status: 'failure',
                    errorMessage: 'Invalid expires_at date format.',
                    createdBy: $user->getUserIdentifier(),
                    source: 'admin_api_token',
                );
                $this->auditService?->flush();
                return $this->secureJson(['error' => $this->trans('token.invalid_expires_at', [], 'Invalid expires_at date format.')], 422);
            }
            if ($expiresAt <= new \DateTimeImmutable()) {
                $this->auditService?->log(
                    toolName: 'personal_access_token_create_failure',
                    project: null,
                    input: ['reason' => 'expires_must_be_future'],
                    output: null,
                    status: 'failure',
                    errorMessage: 'Expiration date must be in the future.',
                    createdBy: $user->getUserIdentifier(),
                    source: 'admin_api_token',
                );
                $this->auditService?->flush();
                return $this->secureJson(['error' => $this->trans('token.expires_future', [], 'Expiration date must be in the future.')], 422);
            }
            $t->expiresAt = $expiresAt;
        }

        $this->em->persist($t);
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'personal_access_token_create',
            project: null,
            input: ['name' => $t->name, 'scopes' => $t->scopes, 'expires_at' => $t->expiresAt?->format(DATE_ATOM)],
            output: ['id' => $t->id],
            status: 'success',
            createdBy: $user->getUserIdentifier(),
            source: 'admin_api_token',
        );
        $this->auditService?->flush();

        return $this->secureJson(['data' => [
            'id' => $t->id,
            'name' => $t->name,
            'scopes' => $t->scopes,
            'token' => $plain, // affiché UNE SEULE FOIS
        ]], 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $this->consumeRateLimit($request);

        $currentUser = $this->resolveUser($request);
        $t = $this->repo->find($id);

        if (!$t || ($currentUser !== null && $t->user !== $currentUser)) {
            $this->auditService?->log(
                toolName: 'personal_access_token_update_failure',
                project: null,
                input: ['id' => $id, 'reason' => 'token_not_found'],
                output: null,
                status: 'failure',
                errorMessage: 'Token not found.',
                createdBy: $currentUser?->getUserIdentifier(),
                source: 'admin_api_token',
            );
            $this->auditService?->flush();
            return $this->secureJson(['error' => $this->trans('token.not_found', [], 'Token not found.')], 404);
        }

        $authorizingPat = $request->attributes->get('_pat');
        if ($authorizingPat instanceof PersonalAccessToken && $authorizingPat->id !== $t->id && !$authorizingPat->can('*') && !$authorizingPat->can('tokens:manage')) {
            $this->auditService?->log(
                toolName: 'personal_access_token_update_failure',
                project: null,
                input: ['id' => $id, 'reason' => 'lacks_tokens_manage'],
                output: null,
                status: 'failure',
                errorMessage: 'Personal access token lacks "tokens:manage" to update other tokens.',
                createdBy: $currentUser?->getUserIdentifier(),
                source: 'admin_api_token',
            );
            $this->auditService?->flush();
            return $this->secureJson(['error' => $this->trans('token.lacks_manage_update', [], 'Personal access token lacks "tokens:manage" to update other tokens.')], 403);
        }

        $contentType = (string) $request->headers->get('Content-Type', '');
        if ($contentType !== '' && !str_contains(strtolower($contentType), 'application/json')) {
            $this->auditService?->log(
                toolName: 'personal_access_token_update_failure',
                project: null,
                input: ['id' => $id, 'reason' => 'invalid_content_type', 'content_type' => $contentType],
                output: null,
                status: 'failure',
                errorMessage: 'Content-Type must be application/json.',
                createdBy: $currentUser?->getUserIdentifier(),
                source: 'admin_api_token',
            );
            $this->auditService?->flush();
            return $this->secureJson(['error' => $this->trans('token.invalid_content_type', [], 'Content-Type must be application/json.')], 415);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            $this->auditService?->log(
                toolName: 'personal_access_token_update_failure',
                project: null,
                input: ['id' => $id, 'reason' => 'payload_too_large'],
                output: null,
                status: 'failure',
                errorMessage: 'Payload exceeds maximum size limit.',
                createdBy: $currentUser?->getUserIdentifier(),
                source: 'admin_api_token',
            );
            $this->auditService?->flush();
            return $this->secureJson(['error' => $this->trans('token.payload_too_large', [], 'Payload exceeds maximum size limit.')], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            $this->auditService?->log(
                toolName: 'personal_access_token_update_failure',
                project: null,
                input: ['id' => $id, 'reason' => 'invalid_json'],
                output: null,
                status: 'failure',
                errorMessage: 'Invalid JSON body.',
                createdBy: $currentUser?->getUserIdentifier(),
                source: 'admin_api_token',
            );
            $this->auditService?->flush();
            return $this->secureJson(['error' => $this->trans('token.invalid_json', [], 'Invalid JSON body.')], 400);
        }

        if (isset($data['name'])) {
            $rawName = trim((string) $data['name']);
            if ($rawName === '') {
                return $this->secureJson(['error' => $this->trans('token.name_empty', [], 'Token name cannot be empty.')], 422);
            }
            if (mb_strlen($rawName) > 255) {
                return $this->secureJson(['error' => $this->trans('token.name_too_long', [], 'Token name must not exceed 255 characters.')], 422);
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $rawName)) {
                return $this->secureJson(['error' => $this->trans('token.name_control_chars', [], 'Token name contains invalid control characters.')], 422);
            }
            $t->name = $rawName;
        }

        if (isset($data['scopes'])) {
            if (!is_array($data['scopes']) || empty($data['scopes'])) {
                return $this->secureJson(['error' => $this->trans('token.scopes_required', [], 'Scopes must be a non-empty array.')], 422);
            }
            $newScopes = [];
            foreach ($data['scopes'] as $s) {
                if (!is_string($s) || !in_array($s, self::ALLOWED_SCOPES, true)) {
                    $scopeStr = is_scalar($s) ? (string) $s : gettype($s);
                    return $this->secureJson(['error' => $this->trans('token.invalid_scope', ['%scope%' => $scopeStr, '%allowed%' => implode(', ', self::ALLOWED_SCOPES)], sprintf('Invalid scope "%s". Allowed scopes: %s', $scopeStr, implode(', ', self::ALLOWED_SCOPES)))], 422);
                }
                $newScopes[] = $s;
            }
            $newScopes = array_values(array_unique($newScopes));

            if ($authorizingPat instanceof PersonalAccessToken) {
                if ((in_array('*', $newScopes, true) || in_array('tokens:manage', $newScopes, true)) && !$authorizingPat->can('*') && !$authorizingPat->can('tokens:manage')) {
                    return $this->secureJson(['error' => $this->trans('token.admin_scope_forbidden', [], 'Cannot grant administrative scopes not held by the authorizing token.')], 403);
                }
                if (!$authorizingPat->can('*') && !$authorizingPat->can('tokens:manage')) {
                    foreach ($newScopes as $scope) {
                        if (!$authorizingPat->can($scope)) {
                            return $this->secureJson(['error' => $this->trans('token.escalation_blocked', ['%scope%' => $scope], sprintf('Privilege escalation blocked: cannot grant scope "%s" not held by the authorizing token.', $scope))], 403);
                        }
                    }
                }
            }

            $t->scopes = $newScopes;
        }

        $this->em->flush();

        $this->auditService?->log(
            toolName: 'personal_access_token_update',
            project: null,
            input: ['id' => $t->id, 'name' => $t->name, 'scopes' => $t->scopes],
            output: ['id' => $t->id],
            status: 'success',
            createdBy: $currentUser?->getUserIdentifier(),
            source: 'admin_api_token',
        );
        $this->auditService?->flush();

        return $this->secureJson(['data' => ['id' => $t->id, 'name' => $t->name, 'scopes' => $t->scopes]]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        $this->consumeRateLimit($request);

        $currentUser = $this->resolveUser($request);
        $t = $this->repo->find($id);

        if (!$t || ($currentUser !== null && $t->user !== $currentUser)) {
            $this->auditService?->log(
                toolName: 'personal_access_token_delete_failure',
                project: null,
                input: ['id' => $id, 'reason' => 'token_not_found'],
                output: null,
                status: 'failure',
                errorMessage: 'Token not found.',
                createdBy: $currentUser?->getUserIdentifier(),
                source: 'admin_api_token',
            );
            $this->auditService?->flush();
            return $this->secureJson(['error' => $this->trans('token.not_found', [], 'Token not found.')], 404);
        }

        $authorizingPat = $request->attributes->get('_pat');
        if ($authorizingPat instanceof PersonalAccessToken && $authorizingPat->id !== $t->id && !$authorizingPat->can('*') && !$authorizingPat->can('tokens:manage')) {
            $this->auditService?->log(
                toolName: 'personal_access_token_delete_failure',
                project: null,
                input: ['id' => $id, 'reason' => 'lacks_tokens_manage'],
                output: null,
                status: 'failure',
                errorMessage: 'Personal access token lacks "tokens:manage" to delete other tokens.',
                createdBy: $currentUser?->getUserIdentifier(),
                source: 'admin_api_token',
            );
            $this->auditService?->flush();
            return $this->secureJson(['error' => $this->trans('token.lacks_manage_delete', [], 'Personal access token lacks "tokens:manage" to delete other tokens.')], 403);
        }

        $tokenName = $t->name;
        $tokenId = $t->id;

        $this->em->remove($t);
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'personal_access_token_delete',
            project: null,
            input: ['id' => $tokenId, 'name' => $tokenName],
            output: ['deleted' => true],
            status: 'success',
            createdBy: $currentUser?->getUserIdentifier(),
            source: 'admin_api_token',
        );
        $this->auditService?->flush();

        return $this->secureJson(null, 204);
    }

    private function consumeRateLimit(Request $request): void
    {
        if ($this->tokenLimiter === null) {
            return;
        }
        $identifier = $request->getClientIp() ?: '127.0.0.1';
        try {
            $user = $this->resolveUser($request);
            if ($user !== null) {
                $identifier = $user->getUserIdentifier();
            }
        } catch (\Throwable) {}

        if (!$this->tokenLimiter->create($identifier)->consume(1)->isAccepted()) {
            $this->auditService?->log(
                toolName: 'personal_access_token_rate_limited',
                project: null,
                input: ['identifier' => $identifier],
                output: null,
                status: 'failure',
                errorMessage: 'Too many token management requests. Please retry later.',
                createdBy: $this->getUserIdentifier($request),
                source: 'admin_api_token',
            );
            $this->auditService?->flush();
            throw new TooManyRequestsHttpException(60, $this->trans('token.rate_limit_exceeded', [], 'Too many token management requests. Please retry later.'));
        }
    }
}
