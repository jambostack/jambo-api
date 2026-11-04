<?php

namespace App\Controller\AdminApi;

use App\Entity\ApiToken;
use App\Entity\PersonalAccessToken;
use App\Repository\PersonalAccessTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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

    public function __construct(
        private PersonalAccessTokenRepository $repo,
        private EntityManagerInterface $em,
        private string $appSecret,
        #[Autowire(service: 'limiter.token_management_limiter')]
        private ?RateLimiterFactory $tokenLimiter = null,
        private ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters);
        }
        return $default ?? $id;
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $this->consumeRateLimit($request);

        $tokens = $this->repo->findByUser($this->getUser());
        return new JsonResponse(['data' => array_map(fn (PersonalAccessToken $t) => [
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

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse(['error' => $this->trans('token.invalid_json', [], 'Invalid JSON body')], 400);
        }

        $rawName = trim((string) ($data['name'] ?? ''));
        if ($rawName === '') {
            return new JsonResponse(['error' => $this->trans('token.name_required', [], 'Token name is required.')], 422);
        }
        if (mb_strlen($rawName) > 255) {
            return new JsonResponse(['error' => $this->trans('token.name_too_long', [], 'Token name must not exceed 255 characters.')], 422);
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $rawName)) {
            return new JsonResponse(['error' => $this->trans('token.name_control_chars', [], 'Token name contains invalid control characters.')], 422);
        }

        $rawScopes = $data['scopes'] ?? ['schema:write'];
        if (!is_array($rawScopes) || empty($rawScopes)) {
            return new JsonResponse(['error' => $this->trans('token.scopes_required', [], 'Scopes must be a non-empty array.')], 422);
        }

        $scopes = [];
        foreach ($rawScopes as $s) {
            if (!is_string($s) || !in_array($s, self::ALLOWED_SCOPES, true)) {
                $scopeStr = is_scalar($s) ? (string) $s : gettype($s);
                return new JsonResponse(['error' => $this->trans('token.invalid_scope', ['%scope%' => $scopeStr, '%allowed%' => implode(', ', self::ALLOWED_SCOPES)], sprintf('Invalid scope "%s". Allowed scopes: %s', $scopeStr, implode(', ', self::ALLOWED_SCOPES)))], 422);
            }
            $scopes[] = $s;
        }
        $scopes = array_values(array_unique($scopes));

        // Anti-Privilege Escalation: If caller is authenticated via a PAT, prevent granting scopes not held by authorizer
        $authorizingPat = $request->attributes->get('_pat');
        if ($authorizingPat instanceof PersonalAccessToken) {
            if ((in_array('*', $scopes, true) || in_array('tokens:manage', $scopes, true)) && !$authorizingPat->can('*') && !$authorizingPat->can('tokens:manage')) {
                return new JsonResponse(['error' => $this->trans('token.admin_scope_forbidden', [], 'Cannot grant administrative scopes not held by the authorizing token.')], 403);
            }
            if (!$authorizingPat->can('*') && !$authorizingPat->can('tokens:manage')) {
                foreach ($scopes as $scope) {
                    if (!$authorizingPat->can($scope)) {
                        return new JsonResponse(['error' => $this->trans('token.escalation_blocked', ['%scope%' => $scope], sprintf('Privilege escalation blocked: cannot grant scope "%s" not held by the authorizing token.', $scope))], 403);
                    }
                }
            }
        }

        $plain = 'jbo_pat_' . ApiToken::generatePlainToken();
        $t = new PersonalAccessToken();
        $t->name = $rawName;
        $user = null;
        try {
            $user = $this->getUser();
        } catch (\Throwable) {}
        if ($user === null && $authorizingPat instanceof PersonalAccessToken) {
            $user = $authorizingPat->user;
        }
        $t->user = $user;
        $t->scopes = $scopes;
        $t->tokenHash = ApiToken::hashToken($plain, $this->appSecret);
        $t->tokenVersion = 2;

        if (!empty($data['expires_at'])) {
            try {
                $expiresAt = new \DateTimeImmutable((string) $data['expires_at']);
            } catch (\Exception) {
                return new JsonResponse(['error' => $this->trans('token.invalid_expires_at', [], 'Invalid expires_at date format.')], 422);
            }
            if ($expiresAt <= new \DateTimeImmutable()) {
                return new JsonResponse(['error' => $this->trans('token.expires_future', [], 'Expiration date must be in the future.')], 422);
            }
            $t->expiresAt = $expiresAt;
        }
        $this->em->persist($t);
        $this->em->flush();

        return new JsonResponse(['data' => [
            'id' => $t->id,
            'name' => $t->name,
            'scopes' => $t->scopes,
            'token' => $plain, // affiché UNE SEULE FOIS
        ]], 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $this->consumeRateLimit($request);

        $t = $this->repo->find($id);
        $currentUser = null;
        try {
            $currentUser = $this->getUser();
        } catch (\Throwable) {}

        if (!$t || ($currentUser !== null && $t->user !== $currentUser)) {
            return new JsonResponse(['error' => $this->trans('token.not_found', [], 'Token not found')], 404);
        }

        $authorizingPat = $request->attributes->get('_pat');
        if ($authorizingPat instanceof PersonalAccessToken && $authorizingPat->id !== $t->id && !$authorizingPat->can('*') && !$authorizingPat->can('tokens:manage')) {
            return new JsonResponse(['error' => $this->trans('token.lacks_manage_update', [], 'Personal access token lacks "tokens:manage" to update other tokens.')], 403);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return new JsonResponse(['error' => $this->trans('token.invalid_json', [], 'Invalid JSON body')], 400);
        }

        if (isset($data['name'])) {
            $rawName = trim((string) $data['name']);
            if ($rawName === '') {
                return new JsonResponse(['error' => $this->trans('token.name_empty', [], 'Token name cannot be empty.')], 422);
            }
            if (mb_strlen($rawName) > 255) {
                return new JsonResponse(['error' => $this->trans('token.name_too_long', [], 'Token name must not exceed 255 characters.')], 422);
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $rawName)) {
                return new JsonResponse(['error' => $this->trans('token.name_control_chars', [], 'Token name contains invalid control characters.')], 422);
            }
            $t->name = $rawName;
        }

        if (isset($data['scopes'])) {
            if (!is_array($data['scopes']) || empty($data['scopes'])) {
                return new JsonResponse(['error' => $this->trans('token.scopes_required', [], 'Scopes must be a non-empty array.')], 422);
            }
            $newScopes = [];
            foreach ($data['scopes'] as $s) {
                if (!is_string($s) || !in_array($s, self::ALLOWED_SCOPES, true)) {
                    $scopeStr = is_scalar($s) ? (string) $s : gettype($s);
                    return new JsonResponse(['error' => $this->trans('token.invalid_scope', ['%scope%' => $scopeStr, '%allowed%' => implode(', ', self::ALLOWED_SCOPES)], sprintf('Invalid scope "%s". Allowed scopes: %s', $scopeStr, implode(', ', self::ALLOWED_SCOPES)))], 422);
                }
                $newScopes[] = $s;
            }
            $newScopes = array_values(array_unique($newScopes));

            if ($authorizingPat instanceof PersonalAccessToken) {
                if ((in_array('*', $newScopes, true) || in_array('tokens:manage', $newScopes, true)) && !$authorizingPat->can('*') && !$authorizingPat->can('tokens:manage')) {
                    return new JsonResponse(['error' => $this->trans('token.admin_scope_forbidden', [], 'Cannot grant administrative scopes not held by the authorizing token.')], 403);
                }
                if (!$authorizingPat->can('*') && !$authorizingPat->can('tokens:manage')) {
                    foreach ($newScopes as $scope) {
                        if (!$authorizingPat->can($scope)) {
                            return new JsonResponse(['error' => $this->trans('token.escalation_blocked', ['%scope%' => $scope], sprintf('Privilege escalation blocked: cannot grant scope "%s" not held by the authorizing token.', $scope))], 403);
                        }
                    }
                }
            }

            $t->scopes = $newScopes;
        }

        $this->em->flush();
        return new JsonResponse(['data' => ['id' => $t->id, 'name' => $t->name, 'scopes' => $t->scopes]]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        $this->consumeRateLimit($request);

        $t = $this->repo->find($id);
        $currentUser = null;
        try {
            $currentUser = $this->getUser();
        } catch (\Throwable) {}

        if (!$t || ($currentUser !== null && $t->user !== $currentUser)) {
            return new JsonResponse(['error' => $this->trans('token.not_found', [], 'Token not found')], 404);
        }

        $authorizingPat = $request->attributes->get('_pat');
        if ($authorizingPat instanceof PersonalAccessToken && $authorizingPat->id !== $t->id && !$authorizingPat->can('*') && !$authorizingPat->can('tokens:manage')) {
            return new JsonResponse(['error' => $this->trans('token.lacks_manage_delete', [], 'Personal access token lacks "tokens:manage" to delete other tokens.')], 403);
        }

        $this->em->remove($t);
        $this->em->flush();
        return new JsonResponse(null, 204);
    }

    private function consumeRateLimit(Request $request): void
    {
        if ($this->tokenLimiter === null) {
            return;
        }
        $identifier = $request->getClientIp() ?: '127.0.0.1';
        try {
            $user = $this->getUser();
            if ($user !== null) {
                $identifier = $user->getUserIdentifier();
            }
        } catch (\Throwable) {}

        if (!$this->tokenLimiter->create($identifier)->consume(1)->isAccepted()) {
            throw new \Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException(60, $this->trans('token.rate_limit_exceeded', [], 'Too many token management requests. Please retry later.'));
        }
    }
}
