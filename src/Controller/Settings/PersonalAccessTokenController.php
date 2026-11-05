<?php

namespace App\Controller\Settings;

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
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Gestion des Personal Access Tokens depuis le dashboard (session utilisateur).
 *
 * Indispensable pour « bootstrapper » l'Admin API et le MCP : l'endpoint
 * /admin-api/tokens est lui-même protégé par un PAT (problème œuf-poule), donc
 * la création du tout premier jeton doit passer par une route en session.
 *
 * Les permissions (scopes) reconnues par l'Admin API sont `schema:write`
 * (collections & champs) et `projects:write` (création/édition de projets).
 */
#[Route('/api/settings/personal-access-tokens', name: 'api_settings_pat_')]
class PersonalAccessTokenController extends AbstractController
{
    /** Scopes proposés / acceptés côté UI. */
    private const ALLOWED_SCOPES = ['schema:write', 'projects:write', 'content:write', 'content:read', '*'];
    private const MAX_PAYLOAD_BYTES = 32768; // 32 KB

    public function __construct(
        private readonly PersonalAccessTokenRepository $repo,
        private readonly EntityManagerInterface $em,
        private readonly string $appSecret,
        #[Autowire(service: 'limiter.token_management_limiter')]
        private readonly ?RateLimiterFactory $tokenLimiter = null,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?AuditService $auditService = null,
    ) {}

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

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $user = $this->requireUser();
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $tokens = $this->repo->findByUser($user);

        return new JsonResponse(['data' => array_map($this->serialize(...), $tokens)]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        if ($user instanceof JsonResponse) {
            return $user;
        }

        if ($this->tokenLimiter !== null) {
            $identifier = $user->getUserIdentifier();
            if (!$this->tokenLimiter->create($identifier)->consume(1)->isAccepted()) {
                return new JsonResponse([
                    'error' => $this->trans('settings.pat.rate_limit_exceeded', [], 'Too many token requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            }
        }

        $existingTokens = $this->repo->findByUser($user);
        if (count($existingTokens) >= 50) {
            return new JsonResponse([
                'errors' => ['name' => $this->trans('settings.pat.max_limit_reached', [], 'Maximum token limit reached (50 tokens max).')],
                'code'   => 'MAX_TOKENS_REACHED',
            ], 422);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return new JsonResponse([
                'error' => $this->trans('settings.pat.payload_too_large', [], 'Payload exceeds maximum size.'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse([
                'error' => $this->trans('settings.pat.invalid_json', [], 'Invalid JSON body.'),
                'code'  => 'INVALID_JSON',
            ], 400);
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return new JsonResponse([
                'errors' => ['name' => $this->trans('settings.pat.name_required', [], 'Token name is required.')],
                'code'   => 'NAME_REQUIRED',
            ], 422);
        }
        if (mb_strlen($name) > 255) {
            return new JsonResponse([
                'errors' => ['name' => $this->trans('settings.pat.name_too_long', [], 'Token name must not exceed 255 characters.')],
                'code'   => 'NAME_TOO_LONG',
            ], 422);
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
            return new JsonResponse([
                'errors' => ['name' => $this->trans('settings.pat.name_control_chars', [], 'Token name contains invalid control characters.')],
                'code'   => 'NAME_CONTROL_CHARS',
            ], 422);
        }

        $rawScopes = $data['scopes'] ?? ['schema:write'];
        if (!is_array($rawScopes) || empty($rawScopes)) {
            return new JsonResponse([
                'errors' => ['scopes' => $this->trans('settings.pat.scopes_required', [], 'Scopes must be a non-empty array.')],
                'code'   => 'SCOPES_REQUIRED',
            ], 422);
        }
        $scopes = [];
        foreach ($rawScopes as $s) {
            if (!is_string($s) || !in_array($s, self::ALLOWED_SCOPES, true)) {
                $scopeStr = is_scalar($s) ? (string) $s : gettype($s);
                return new JsonResponse([
                    'errors' => ['scopes' => $this->trans('settings.pat.invalid_scope', [
                        '%scope%'   => $scopeStr,
                        '%allowed%' => implode(', ', self::ALLOWED_SCOPES),
                    ], sprintf('Invalid scope "%s". Allowed scopes: %s', $scopeStr, implode(', ', self::ALLOWED_SCOPES)))],
                    'code'   => 'INVALID_SCOPE',
                ], 422);
            }
            $scopes[] = $s;
        }
        $scopes = array_values(array_unique($scopes));

        $expiresAt = null;
        if (!empty($data['expires_at'])) {
            try {
                $expiresAt = new \DateTimeImmutable((string) $data['expires_at']);
            } catch (\Exception) {
                return new JsonResponse([
                    'errors' => ['expires_at' => $this->trans('settings.pat.invalid_expires_at', [], 'Invalid expiration date format.')],
                    'code'   => 'INVALID_EXPIRES_AT',
                ], 422);
            }
            if ($expiresAt <= new \DateTimeImmutable()) {
                return new JsonResponse([
                    'errors' => ['expires_at' => $this->trans('settings.pat.expires_future', [], 'Expiration date must be in the future.')],
                    'code'   => 'EXPIRES_MUST_BE_FUTURE',
                ], 422);
            }
        }

        $plain = 'jbo_pat_' . ApiToken::generatePlainToken();
        $token = new PersonalAccessToken();
        $token->name = $name;
        $token->user = $user;
        $token->scopes = $scopes;
        $token->tokenHash = ApiToken::hashToken($plain, $this->appSecret);
        $token->tokenVersion = 2;
        $token->expiresAt = $expiresAt;

        $this->em->persist($token);
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'pat_create',
            project: null,
            input: ['token_id' => $token->id, 'name' => $name, 'scopes' => $scopes],
            output: ['success' => true],
            status: 'success',
            createdBy: $user->getUserIdentifier(),
            source: 'pat_management'
        );
        $this->auditService?->flush();

        // Le jeton en clair n'est renvoyé qu'à la création — jamais stocké en clair.
        return new JsonResponse(['data' => [...$this->serialize($token), 'token' => $plain]], 201);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): JsonResponse
    {
        $user = $this->requireUser();
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $token = $this->repo->find($id);
        if (!$token || $token->user !== $user) {
            return new JsonResponse([
                'error' => $this->trans('settings.pat.not_found', [], 'Token not found.'),
                'code'  => 'TOKEN_NOT_FOUND',
            ], 404);
        }

        $tokenId = $token->id;
        $tokenName = $token->name;

        $this->em->remove($token);
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'pat_delete',
            project: null,
            input: ['token_id' => $tokenId, 'name' => $tokenName],
            output: ['success' => true],
            status: 'success',
            createdBy: $user->getUserIdentifier(),
            source: 'pat_management'
        );
        $this->auditService?->flush();

        return new JsonResponse(null, 204);
    }

    private function requireUser(): User|JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse([
                'error' => $this->trans('settings.pat.unauthenticated', [], 'Unauthenticated.'),
                'code'  => 'UNAUTHENTICATED',
            ], 401);
        }
        return $user;
    }

    /** @return array<string, mixed> */
    private function serialize(PersonalAccessToken $t): array
    {
        return [
            'id'         => $t->id,
            'name'       => $t->name,
            'scopes'     => $t->scopes,
            'lastUsedAt' => $t->lastUsedAt?->format(DATE_ATOM),
            'expiresAt'  => $t->expiresAt?->format(DATE_ATOM),
            'createdAt'  => $t->createdAt->format(DATE_ATOM),
        ];
    }
}
