<?php

namespace App\Controller;

use App\Entity\ApiToken;
use App\Entity\Project;
use App\Entity\ProjectMailerSettings;
use App\Repository\ApiTokenRepository;
use App\Repository\ProjectMailerSettingsRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Service\ApiTokenChecker;
use App\Service\AuditService;
use App\Service\EndUserJwtService;
use App\Service\ProjectMailerService;
use App\Service\Security\SsrfProtectionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/projects/{projectUuid}/settings', name: 'api_project_settings_')]
class ProjectSettingsController extends AbstractController
{
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const LOCALE_REGEX = '/^[a-z]{2,3}(-[A-Za-z0-9]+)?$/i';
    private const ALLOWED_ABILITIES = ['read', 'create', 'update', 'delete', '*'];
    private const MAX_PAYLOAD_BYTES = 65536; // 64 KB
    private const MAX_PROJECT_TOKENS = 50;
    private const MAX_LOCALES_COUNT = 50;
    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-store',
    ];

    private readonly SsrfProtectionService $ssrfProtection;

    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly ApiTokenRepository $apiTokenRepository,
        private readonly ProjectMemberRepository $memberRepo,
        private readonly ProjectMailerSettingsRepository $mailerSettingsRepo,
        private readonly ProjectMailerService $mailerService,
        private readonly EntityManagerInterface $em,
        private readonly ApiTokenChecker $tokenChecker,
        private readonly LoggerInterface $logger,
        private readonly string $appSecret = '',
        #[Autowire(service: 'limiter.token_management_limiter')]
        private readonly ?RateLimiterFactory $tokenLimiter = null,
        #[Autowire(service: 'limiter.mailer_test_limiter')]
        private readonly ?RateLimiterFactory $mailerTestLimiter = null,
        private readonly ?TranslatorInterface $translator = null,
        ?SsrfProtectionService $ssrfProtection = null,
        private readonly ?AuditService $auditService = null,
    ) {
        $this->ssrfProtection = $ssrfProtection ?? new SsrfProtectionService();
    }

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

    // ─── Localization ──────────────────────────────────────────────────────

    #[Route('/localization', name: 'localization_update', methods: ['PATCH'])]
    public function updateLocalization(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.payload_too_large', [], 'Payload exceeds maximum size limit.'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.invalid_json', [], 'Invalid JSON body.'),
                'code'  => 'INVALID_JSON',
            ], 400);
        }

        if (isset($data['locales'])) {
            if (!is_array($data['locales'])) {
                return $this->secureJson([
                    'error' => $this->trans('project_settings.locales_must_be_array', [], 'Locales must be a valid list.'),
                    'code'  => 'INVALID_LOCALES',
                ], 422);
            }

            if (count($data['locales']) > self::MAX_LOCALES_COUNT) {
                return $this->secureJson([
                    'error' => $this->trans('project_settings.max_locales_exceeded', [], 'Maximum number of locales reached (50 max).'),
                    'code'  => 'MAX_LOCALES_EXCEEDED',
                ], 422);
            }

            $newLocales = [];
            foreach ($data['locales'] as $loc) {
                if (!is_string($loc) || !preg_match(self::LOCALE_REGEX, trim($loc))) {
                    return $this->secureJson([
                        'error' => $this->trans('project_settings.invalid_locale_format', [], 'Invalid locale format.'),
                        'code'  => 'INVALID_LOCALE_FORMAT',
                    ], 422);
                }
                $newLocales[] = trim($loc);
            }
            $newLocales = array_values(array_unique($newLocales));

            // Always keep the current defaultLocale in the list to avoid inconsistent state.
            if (!in_array($project->defaultLocale, $newLocales, true)) {
                $newLocales[] = $project->defaultLocale;
            }
            $project->locales = array_values($newLocales);
        }

        if (isset($data['default_locale'])) {
            $defaultLoc = trim((string) $data['default_locale']);
            if (!in_array($defaultLoc, $project->locales, true)) {
                return $this->secureJson([
                    'error' => $this->trans('project_settings.default_locale_missing', [], 'default_locale must be present in the locales list.'),
                    'code'  => 'DEFAULT_LOCALE_NOT_IN_LIST',
                ], 422);
            }
            $project->defaultLocale = $defaultLoc;
        }

        $this->em->flush();

        $this->auditService?->log(
            toolName: 'project_settings_localization',
            project: $project,
            input: ['default_locale' => $project->defaultLocale, 'locales' => $project->locales],
            output: ['success' => true],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier() ?? 'api',
            source: 'project_settings'
        );
        $this->auditService?->flush();

        return $this->secureJson(['defaultLocale' => $project->defaultLocale, 'locales' => $project->locales]);
    }

    #[Route('/locales', name: 'locale_add', methods: ['POST'])]
    public function addLocale(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.payload_too_large', [], 'Payload exceeds maximum size limit.'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.invalid_json', [], 'Invalid JSON body.'),
                'code'  => 'INVALID_JSON',
            ], 400);
        }

        $locale = isset($data['locale']) ? trim((string) $data['locale']) : null;
        if (!$locale) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.locale_required', [], 'locale is required.'),
                'code'  => 'LOCALE_REQUIRED',
            ], 422);
        }

        if (!preg_match(self::LOCALE_REGEX, $locale)) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.invalid_locale_format', [], 'Invalid locale format.'),
                'code'  => 'INVALID_LOCALE_FORMAT',
            ], 422);
        }

        if (count($project->locales) >= self::MAX_LOCALES_COUNT && !in_array($locale, $project->locales, true)) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.max_locales_exceeded', [], 'Maximum number of locales reached (50 max).'),
                'code'  => 'MAX_LOCALES_EXCEEDED',
            ], 422);
        }

        if (!in_array($locale, $project->locales, true)) {
            $project->locales = [...$project->locales, $locale];
            $this->em->flush();
        }

        return $this->secureJson($this->serializeLocales($project));
    }

    #[Route('/locale', name: 'locale_set_default', methods: ['PUT'])]
    public function setDefaultLocale(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.payload_too_large', [], 'Payload exceeds maximum size limit.'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.invalid_json', [], 'Invalid JSON body.'),
                'code'  => 'INVALID_JSON',
            ], 400);
        }

        $locale = isset($data['locale']) ? trim((string) $data['locale']) : null;
        if (!$locale) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.locale_required', [], 'locale is required.'),
                'code'  => 'LOCALE_REQUIRED',
            ], 422);
        }

        if (!in_array($locale, $project->locales, true)) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.locale_not_found', [], 'Locale not in project locales list.'),
                'code'  => 'LOCALE_NOT_FOUND',
            ], 422);
        }

        $project->defaultLocale = $locale;
        $this->em->flush();

        return $this->secureJson($this->serializeLocales($project));
    }

    #[Route('/locales/{locale}', name: 'locale_delete', methods: ['DELETE'])]
    public function deleteLocale(string $projectUuid, string $locale): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        if ($locale === $project->defaultLocale) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.cannot_delete_default_locale', [], 'Cannot delete the default locale.'),
                'code'  => 'CANNOT_DELETE_DEFAULT_LOCALE',
            ], 422);
        }

        $project->locales = array_values(array_filter($project->locales, fn ($l) => $l !== $locale));
        $this->em->flush();

        return $this->secureJson($this->serializeLocales($project));
    }

    private function serializeLocales(Project $project): array
    {
        return [
            'default_locale' => $project->defaultLocale,
            'locales'        => $project->locales,
        ];
    }

    // ─── User Access ───────────────────────────────────────────────────────

    #[Route('/members', name: 'members_index', methods: ['GET'])]
    public function listMembers(string $projectUuid): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $members = $this->memberRepo->findByProject($project);

        return $this->secureJson([
            'data' => array_map(fn ($m) => [
                'id'        => $m->id,
                'user'      => $m->user ? [
                    'id'    => $m->user->id,
                    'name'  => $m->user->name,
                    'email' => $m->user->email,
                ] : null,
                'role'      => $m->role ? [
                    'id'    => $m->role->id,
                    'name'  => $m->role->name,
                    'label' => $m->role->label,
                ] : null,
                'email'     => $m->email,
                'status'    => $m->status,
                'joined_at' => $m->joinedAt?->format(\DateTimeInterface::ATOM),
                'created_at' => $m->createdAt->format(\DateTimeInterface::ATOM),
            ], $members),
        ]);
    }

    // ─── API Tokens ────────────────────────────────────────────────────────

    #[Route('/tokens', name: 'tokens_index', methods: ['GET'])]
    public function listTokens(string $projectUuid): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $tokens = $this->apiTokenRepository->findByProject($project);

        return $this->secureJson([
            'data' => array_map(fn ($t) => [
                'id'         => $t->id,
                'name'       => $t->name,
                'abilities'  => $t->abilities,
                'lastUsedAt' => $t->lastUsedAt?->format(\DateTimeInterface::ATOM),
                'expiresAt'  => $t->expiresAt?->format(\DateTimeInterface::ATOM),
                'createdAt'  => $t->createdAt->format(\DateTimeInterface::ATOM),
            ], $tokens),
        ]);
    }

    #[Route('/tokens', name: 'tokens_create', methods: ['POST'])]
    public function createToken(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        if ($this->tokenLimiter !== null) {
            $identifier = $this->getUser()?->getUserIdentifier() ?? $request->getClientIp() ?: '127.0.0.1';
            if (!$this->tokenLimiter->create($identifier)->consume(1)->isAccepted()) {
                return $this->secureJson([
                    'error' => $this->trans('project_settings.rate_limit_exceeded', [], 'Too many requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            }
        }

        $existingCount = count($this->apiTokenRepository->findByProject($project));
        if ($existingCount >= self::MAX_PROJECT_TOKENS) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.token_max_limit_reached', [], 'Maximum token limit reached for this project (50 tokens max).'),
                'code'  => 'MAX_TOKENS_REACHED',
            ], 422);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.payload_too_large', [], 'Payload exceeds maximum size limit.'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.invalid_json', [], 'Invalid JSON body.'),
                'code'  => 'INVALID_JSON',
            ], 400);
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return $this->secureJson([
                'error' => $this->trans('project_settings.token_name_required', [], 'Token name is required.'),
                'code'  => 'NAME_REQUIRED',
            ], 422);
        }
        if (mb_strlen($name) > 255) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.token_name_too_long', [], 'Token name must not exceed 255 characters.'),
                'code'  => 'NAME_TOO_LONG',
            ], 422);
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.token_name_control_chars', [], 'Token name contains invalid control characters.'),
                'code'  => 'NAME_CONTROL_CHARS',
            ], 422);
        }

        $rawAbilities = $data['abilities'] ?? ['read'];
        if (!is_array($rawAbilities)) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.token_invalid_abilities', [], 'Abilities must be an array of valid permissions (read, create, update, delete, *).'),
                'code'  => 'INVALID_ABILITIES',
            ], 422);
        }
        $abilities = [];
        foreach ($rawAbilities as $ab) {
            if (!is_string($ab) || !in_array($ab, self::ALLOWED_ABILITIES, true)) {
                return $this->secureJson([
                    'error' => $this->trans('project_settings.token_invalid_abilities', [], 'Abilities must be an array of valid permissions (read, create, update, delete, *).'),
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
                return $this->secureJson([
                    'error' => $this->trans('project_settings.token_invalid_expires_at', [], 'Invalid expiration date format.'),
                    'code'  => 'INVALID_EXPIRES_AT',
                ], 422);
            }
            if ($expiresAt <= new \DateTimeImmutable()) {
                return $this->secureJson([
                    'error' => $this->trans('project_settings.token_expires_future', [], 'Expiration date must be in the future.'),
                    'code'  => 'EXPIRES_MUST_BE_FUTURE',
                ], 422);
            }
        }

        $plainToken = ApiToken::generatePlainToken();

        $token = new ApiToken();
        $token->project   = $project;
        $token->name      = $name;
        $token->tokenHash = ApiToken::hashToken($plainToken, $this->appSecret);
        $token->tokenVersion = 2;
        $token->abilities = $abilities;
        $token->expiresAt = $expiresAt;

        $this->em->persist($token);
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'api_token_create',
            project: $project,
            input: ['token_id' => $token->id, 'name' => $name, 'abilities' => $abilities],
            output: ['success' => true],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier() ?? 'api',
            source: 'project_settings'
        );
        $this->auditService?->flush();

        return $this->secureJson([
            'id'         => $token->id,
            'name'       => $token->name,
            'token'      => $plainToken, // Only shown once
            'abilities'  => $token->abilities,
            'createdAt'  => $token->createdAt->format(\DateTimeInterface::ATOM),
        ], 201);
    }

    #[Route('/tokens/{id}', name: 'tokens_update', methods: ['PATCH'])]
    public function updateToken(string $projectUuid, int $id, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $token = $this->apiTokenRepository->findOneBy(['id' => $id, 'project' => $project]);
        if ($token === null) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.token_not_found', [], 'Token not found.'),
                'code'  => 'TOKEN_NOT_FOUND',
            ], 404);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.payload_too_large', [], 'Payload exceeds maximum size limit.'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.invalid_json', [], 'Invalid JSON body.'),
                'code'  => 'INVALID_JSON',
            ], 400);
        }

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return $this->secureJson([
                    'error' => $this->trans('project_settings.token_name_required', [], 'Token name is required.'),
                    'code'  => 'NAME_REQUIRED',
                ], 422);
            }
            if (mb_strlen($name) > 255) {
                return $this->secureJson([
                    'error' => $this->trans('project_settings.token_name_too_long', [], 'Token name must not exceed 255 characters.'),
                    'code'  => 'NAME_TOO_LONG',
                ], 422);
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
                return $this->secureJson([
                    'error' => $this->trans('project_settings.token_name_control_chars', [], 'Token name contains invalid control characters.'),
                    'code'  => 'NAME_CONTROL_CHARS',
                ], 422);
            }
            $token->name = $name;
        }

        if (isset($data['abilities'])) {
            if (!is_array($data['abilities'])) {
                return $this->secureJson([
                    'error' => $this->trans('project_settings.token_invalid_abilities', [], 'Abilities must be an array of valid permissions (read, create, update, delete, *).'),
                    'code'  => 'INVALID_ABILITIES',
                ], 422);
            }
            $abilities = [];
            foreach ($data['abilities'] as $ab) {
                if (!is_string($ab) || !in_array($ab, self::ALLOWED_ABILITIES, true)) {
                    return $this->secureJson([
                        'error' => $this->trans('project_settings.token_invalid_abilities', [], 'Abilities must be an array of valid permissions (read, create, update, delete, *).'),
                        'code'  => 'INVALID_ABILITIES',
                    ], 422);
                }
                $abilities[] = $ab;
            }
            $token->abilities = array_values(array_unique($abilities));
        }

        $this->em->flush();

        $this->auditService?->log(
            toolName: 'api_token_update',
            project: $project,
            input: ['token_id' => $token->id, 'name' => $token->name],
            output: ['success' => true],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier() ?? 'api',
            source: 'project_settings'
        );
        $this->auditService?->flush();

        return $this->secureJson([
            'id'        => $token->id,
            'name'      => $token->name,
            'abilities' => $token->abilities,
        ]);
    }

    #[Route('/public-api', name: 'toggle_public_api', methods: ['POST'])]
    public function togglePublicApi(string $projectUuid): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $project->publicApi = !$project->publicApi;
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'project_settings_public_api',
            project: $project,
            input: ['public_api' => $project->publicApi],
            output: ['success' => true],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier() ?? 'api',
            source: 'project_settings'
        );
        $this->auditService?->flush();

        return $this->secureJson(['public_api' => $project->publicApi]);
    }

    #[Route('/tokens/{id}', name: 'tokens_delete', methods: ['DELETE'])]
    public function deleteToken(string $projectUuid, int $id): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $token = $this->apiTokenRepository->findOneBy(['id' => $id, 'project' => $project]);
        if ($token === null) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.token_not_found', [], 'Token not found.'),
                'code'  => 'TOKEN_NOT_FOUND',
            ], 404);
        }

        $tokenId = $token->id;
        $tokenName = $token->name;

        $this->em->remove($token);
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'api_token_delete',
            project: $project,
            input: ['token_id' => $tokenId, 'name' => $tokenName],
            output: ['success' => true],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier() ?? 'api',
            source: 'project_settings'
        );
        $this->auditService?->flush();

        return $this->secureJson(null, 204);
    }

    // ─── Token-aware resolver (shared by JWT TTL + Mailer) ─────────────────

    /** Resolve project via session user OR ApiToken (both accepted). */
    private function resolveWithTokenFallback(string $projectUuid, Request $request): Project|JsonResponse
    {
        if (!preg_match(self::UUID_REGEX, $projectUuid)) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.project_not_found', [], 'Project not found.'),
                'code'  => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.project_not_found', [], 'Project not found.'),
                'code'  => 'PROJECT_NOT_FOUND',
            ], 404);
        }
        if ($project === null) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.project_not_found', [], 'Project not found.'),
                'code'  => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        $user = $this->getUser();
        if ($user !== null) {
            if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true) || $project->hasMember($user)) {
                return $project;
            }
        }

        // Fallback: ApiToken auth
        $token = $this->tokenChecker->resolve($request);
        if ($token !== null && $token->project->uuid?->toString() === $projectUuid && $token->can('create')) {
            return $project;
        }

        return $this->secureJson([
            'error' => $this->trans('project_settings.access_denied', [], 'Access denied.'),
            'code'  => 'ACCESS_DENIED',
        ], 403);
    }

    // ─── JWT TTL ──────────────────────────────────────────────────────────

    #[Route('/jwt-ttl', name: 'jwt_ttl_get', methods: ['GET'])]
    public function getJwtTtl(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->resolveWithTokenFallback($projectUuid, $request);
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

    #[Route('/jwt-ttl', name: 'jwt_ttl_update', methods: ['PATCH'])]
    public function updateJwtTtl(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->resolveWithTokenFallback($projectUuid, $request);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.payload_too_large', [], 'Payload exceeds maximum size limit.'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.invalid_json', [], 'Invalid JSON body.'),
                'code'  => 'INVALID_JSON',
            ], 400);
        }

        $accessVal  = $data['jwt_access_ttl'] ?? null;
        $refreshVal = $data['jwt_refresh_ttl'] ?? null;

        // Accept null, '', "0", 0 as "reset to default"
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

        // Cross-check: refresh TTL must be >= access TTL
        $finalAccess  = $project->jwtAccessTtl ?? EndUserJwtService::DEFAULT_ACCESS_TTL;
        $finalRefresh = $project->jwtRefreshTtl ?? EndUserJwtService::DEFAULT_REFRESH_TTL;
        if ($finalRefresh < $finalAccess) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.jwt_refresh_must_gte_access', [
                    '%refresh%' => $finalRefresh,
                    '%access%'  => $finalAccess,
                ], sprintf('jwt_refresh_ttl (%d s) must be >= jwt_access_ttl (%d s).', $finalRefresh, $finalAccess)),
                'code'  => 'REFRESH_LESS_THAN_ACCESS',
            ], 422);
        }

        $this->em->flush();

        $this->auditService?->log(
            toolName: 'project_settings_jwt_ttl',
            project: $project,
            input: ['jwt_access_ttl' => $project->jwtAccessTtl, 'jwt_refresh_ttl' => $project->jwtRefreshTtl],
            output: ['success' => true],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier() ?? 'api',
            source: 'project_settings'
        );
        $this->auditService?->flush();

        return $this->secureJson([
            'jwt_access_ttl'  => $project->jwtAccessTtl,
            'jwt_refresh_ttl' => $project->jwtRefreshTtl,
        ]);
    }

    /**
     * Validate and normalize a TTL value.
     * Returns ?int (null = reset to default) or JsonResponse on error.
     */
    private function validateAndApplyTtl(mixed $val, string $label): JsonResponse|int|null
    {
        // Reset to default: null, empty string, "0", 0
        if ($val === null || $val === '' || $val === '0' || $val === 0) {
            return null;
        }

        $ttl = (int) $val;
        if ($ttl === 0) {
            return null;
        }

        if ($ttl < 60) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.jwt_ttl_min', [
                    '%label%' => $label,
                ], sprintf('jwt_%s_ttl must be at least 60 seconds.', $label)),
                'code'  => 'TTL_TOO_SMALL',
            ], 422);
        }

        if ($ttl > EndUserJwtService::MAX_TTL) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.jwt_ttl_max', [
                    '%label%' => $label,
                    '%max%'   => EndUserJwtService::MAX_TTL,
                ], sprintf('jwt_%s_ttl must not exceed %d seconds (1 year).', $label, EndUserJwtService::MAX_TTL)),
                'code'  => 'TTL_TOO_LARGE',
            ], 422);
        }

        return $ttl;
    }

    // ─── Mailer (SMTP) ────────────────────────────────────────────────────

    #[Route('/mailer', name: 'mailer_get', methods: ['GET'])]
    public function getMailer(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->resolveWithTokenFallback($projectUuid, $request);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $settings = $this->mailerSettingsRepo->findByProject($project);
        if ($settings === null) {
            return $this->secureJson(['data' => null]);
        }

        return $this->secureJson(['data' => [
            'host'       => $settings->host,
            'port'       => $settings->port,
            'username'   => $settings->username,
            'encryption' => $settings->encryption,
            'from_email' => $settings->fromEmail,
            'from_name'  => $settings->fromName,
            'enabled'    => $settings->enabled,
        ]]);
    }

    #[Route('/mailer', name: 'mailer_update', methods: ['PUT'])]
    public function updateMailer(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->resolveWithTokenFallback($projectUuid, $request);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.payload_too_large', [], 'Payload exceeds maximum size limit.'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        $settings = $this->mailerSettingsRepo->findByProject($project);
        if ($settings === null) {
            $settings = new ProjectMailerSettings();
            $settings->project = $project;
            $this->em->persist($settings);
        }

        try {
            $body = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.invalid_json', [], 'Invalid JSON body.'),
                'code'  => 'INVALID_JSON',
            ], 400);
        }

        if (isset($body['host'])) {
            $host = trim((string) $body['host']);
            $reason = null;
            if (!$this->ssrfProtection->isSafeHost($host, $reason)) {
                return $this->secureJson([
                    'error' => $this->trans('project_settings.mailer_ssrf_detected', [], 'Invalid mail host: private or reserved network addresses are not allowed.'),
                    'code'  => 'SSRF_DETECTED',
                ], 400);
            }
            $settings->host = $host;
        }

        if (isset($body['port'])) {
            $port = (int) $body['port'];
            if (!in_array($port, [25, 465, 587, 2525], true)) {
                return $this->secureJson([
                    'error' => $this->trans('project_settings.mailer_port_invalid', [], 'Invalid port: only SMTP ports allowed (25, 465, 587, 2525).'),
                    'code'  => 'INVALID_PORT',
                ], 400);
            }
            $settings->port = $port;
        }

        if (isset($body['username'])) {
            $username = trim((string) $body['username']);
            if (mb_strlen($username) > 255 || preg_match('/[\x00-\x1F\x7F]/', $username)) {
                return $this->secureJson([
                    'error' => $this->trans('project_settings.mailer_username_invalid', [], 'Username contains invalid characters or exceeds 255 characters.'),
                    'code'  => 'INVALID_USERNAME',
                ], 422);
            }
            $settings->username = $username;
        }

        if (isset($body['encryption'])) {
            $enc = (string) $body['encryption'];
            if (!in_array($enc, ['tls', 'ssl', 'none'], true)) {
                return $this->secureJson([
                    'error' => $this->trans('project_settings.mailer_encryption_invalid', [], 'Invalid encryption: must be tls, ssl, or none.'),
                    'code'  => 'INVALID_ENCRYPTION',
                ], 400);
            }
            $settings->encryption = $enc;
        }

        if (isset($body['from_email'])) {
            $fromEmail = strtolower(trim((string) $body['from_email']));
            if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL) || strlen($fromEmail) > 180) {
                return $this->secureJson([
                    'error' => $this->trans('project_settings.mailer_email_invalid', [], 'Invalid from_email address format.'),
                    'code'  => 'INVALID_EMAIL',
                ], 422);
            }
            $settings->fromEmail = $fromEmail;
        }

        if (isset($body['from_name'])) {
            $fromName = trim((string) $body['from_name']);
            if (mb_strlen($fromName) > 255 || preg_match('/[\r\n\x00-\x1F\x7F]/', $fromName)) {
                return $this->secureJson([
                    'error' => $this->trans('project_settings.mailer_name_crlf', [], 'from_name contains invalid characters or newlines.'),
                    'code'  => 'CRLF_DETECTED',
                ], 422);
            }
            $settings->fromName = $fromName;
        }

        if (isset($body['enabled'])) {
            $settings->enabled = (bool) $body['enabled'];
        }

        // Ne mettre à jour le password que s'il est fourni (non vide)
        if (!empty($body['password'] ?? '')) {
            $settings->encryptedPassword = $this->mailerService->encryptPassword((string) $body['password']);
        }

        $this->em->flush();

        $this->auditService?->log(
            toolName: 'project_settings_mailer_update',
            project: $project,
            input: ['host' => $settings->host, 'port' => $settings->port, 'enabled' => $settings->enabled],
            output: ['success' => true],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier() ?? 'api',
            source: 'project_settings'
        );
        $this->auditService?->flush();

        return $this->secureJson(['data' => [
            'host'       => $settings->host,
            'port'       => $settings->port,
            'username'   => $settings->username,
            'encryption' => $settings->encryption,
            'from_email' => $settings->fromEmail,
            'from_name'  => $settings->fromName,
            'enabled'    => $settings->enabled,
        ]]);
    }

    #[Route('/mailer/test', name: 'mailer_test', methods: ['POST'])]
    public function testMailer(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->resolveWithTokenFallback($projectUuid, $request);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        if ($this->mailerTestLimiter !== null) {
            $identifier = $this->getUser()?->getUserIdentifier() ?? $request->getClientIp() ?: '127.0.0.1';
            if (!$this->mailerTestLimiter->create($identifier)->consume(1)->isAccepted()) {
                return $this->secureJson([
                    'error' => $this->trans('project_settings.rate_limit_exceeded', [], 'Too many requests. Please retry later.'),
                    'code'  => 'RATE_LIMIT_EXCEEDED',
                ], 429);
            }
        }

        $settings = $this->mailerSettingsRepo->findByProject($project);
        if ($settings === null || !$settings->enabled) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.mailer_not_configured', [], 'Mailer not configured or disabled.'),
                'code'  => 'MAILER_DISABLED',
            ], 422);
        }

        try {
            $this->mailerService->send(
                $project,
                $settings->fromEmail,
                'Jambo — Email de test',
                "Bonjour,\n\nCeci est un email de test envoyé depuis Jambo.\n\nSi vous recevez cet email, votre configuration SMTP est correcte.\n\n— L'équipe Jambo",
            );
        } catch (\RuntimeException $e) {
            $this->logger->error('Mailer test failed', ['project' => $projectUuid, 'exception' => $e]);
            return $this->secureJson([
                'error' => $this->trans('project_settings.mailer_test_failed', [], 'Failed to send test email. Check your SMTP configuration and try again.'),
                'code'  => 'MAILER_SEND_FAILED',
            ], 422);
        }

        $this->auditService?->log(
            toolName: 'project_settings_mailer_test',
            project: $project,
            input: ['to' => $settings->fromEmail],
            output: ['success' => true],
            status: 'success',
            createdBy: $this->getUser()?->getUserIdentifier() ?? 'api',
            source: 'project_settings'
        );
        $this->auditService?->flush();

        return $this->secureJson(['sent' => true]);
    }

    private function resolveProject(string $projectUuid): Project|JsonResponse
    {
        if (!preg_match(self::UUID_REGEX, $projectUuid)) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.project_not_found', [], 'Project not found.'),
                'code'  => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        try {
            $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.project_not_found', [], 'Project not found.'),
                'code'  => 'PROJECT_NOT_FOUND',
            ], 404);
        }
        if ($project === null) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.project_not_found', [], 'Project not found.'),
                'code'  => 'PROJECT_NOT_FOUND',
            ], 404);
        }

        $user = $this->getUser();
        if ($user === null || (!in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true) && !$project->hasMember($user))) {
            return $this->secureJson([
                'error' => $this->trans('project_settings.access_denied', [], 'Access denied.'),
                'code'  => 'ACCESS_DENIED',
            ], 403);
        }

        return $project;
    }
}
