<?php

namespace App\Controller;

use App\Entity\ApiToken;
use App\Entity\ProjectMailerSettings;
use App\Repository\ApiTokenRepository;
use App\Repository\ProjectMailerSettingsRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Service\ApiTokenChecker;
use App\Service\EndUserJwtService;
use App\Service\ProjectMailerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/projects/{projectUuid}/settings', name: 'api_project_settings_')]
class ProjectSettingsController extends AbstractController
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private ApiTokenRepository $apiTokenRepository,
        private ProjectMemberRepository $memberRepo,
        private ProjectMailerSettingsRepository $mailerSettingsRepo,
        private ProjectMailerService $mailerService,
        private EntityManagerInterface $em,
        private ApiTokenChecker $tokenChecker,
        private LoggerInterface $logger,
        private string $appSecret = '',
    ) {}

    // ─── Localization ──────────────────────────────────────────────────────

    #[Route('/localization', name: 'localization_update', methods: ['PATCH'])]
    public function updateLocalization(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $data = $request->toArray();

        if (isset($data['locales'])) {
            $newLocales = (array) $data['locales'];
            // Always keep the current defaultLocale in the list to avoid inconsistent state.
            if (!in_array($project->defaultLocale, $newLocales, true)) {
                $newLocales[] = $project->defaultLocale;
            }
            $project->locales = array_values($newLocales);
        }

        if (isset($data['default_locale'])) {
            if (!in_array($data['default_locale'], $project->locales, true)) {
                return $this->json(['error' => 'default_locale must be present in the locales list.'], 422);
            }
            $project->defaultLocale = $data['default_locale'];
        }

        $this->em->flush();

        return $this->json(['defaultLocale' => $project->defaultLocale, 'locales' => $project->locales]);
    }

    #[Route('/locales', name: 'locale_add', methods: ['POST'])]
    public function addLocale(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $locale = $request->toArray()['locale'] ?? null;
        if (!$locale) {
            return $this->json(['error' => 'locale is required'], 422);
        }

        if (!in_array($locale, $project->locales, true)) {
            $project->locales = [...$project->locales, $locale];
            $this->em->flush();
        }

        return $this->json($this->serializeLocales($project));
    }

    #[Route('/locale', name: 'locale_set_default', methods: ['PUT'])]
    public function setDefaultLocale(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $locale = $request->toArray()['locale'] ?? null;
        if (!$locale) {
            return $this->json(['error' => 'locale is required'], 422);
        }

        if (!in_array($locale, $project->locales, true)) {
            return $this->json(['error' => 'Locale not in project locales list'], 422);
        }

        $project->defaultLocale = $locale;
        $this->em->flush();

        return $this->json($this->serializeLocales($project));
    }

    #[Route('/locales/{locale}', name: 'locale_delete', methods: ['DELETE'])]
    public function deleteLocale(string $projectUuid, string $locale): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        if ($locale === $project->defaultLocale) {
            return $this->json(['error' => 'Cannot delete the default locale'], 422);
        }

        $project->locales = array_values(array_filter($project->locales, fn ($l) => $l !== $locale));
        $this->em->flush();

        return $this->json($this->serializeLocales($project));
    }

    private function serializeLocales(\App\Entity\Project $project): array
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

        return $this->json([
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

        return $this->json([
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

        $data = $request->toArray();

        if (empty($data['name'])) {
            return $this->json(['error' => 'name is required'], 422);
        }

        $plainToken = ApiToken::generatePlainToken();

        $token = new ApiToken();
        $token->project   = $project;
        $token->name      = $data['name'];
        $token->tokenHash = ApiToken::hashToken($plainToken, $this->appSecret);
        $token->tokenVersion = 2;
        $token->abilities = $data['abilities'] ?? ['read'];
        if (!empty($data['expires_at'])) {
            $token->expiresAt = new \DateTimeImmutable($data['expires_at']);
        }

        $this->em->persist($token);
        $this->em->flush();

        return $this->json([
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
            return $this->json(['error' => 'Token not found'], 404);
        }

        $data = $request->toArray();
        if (isset($data['name'])) {
            $token->name = $data['name'];
        }
        if (isset($data['abilities'])) {
            $token->abilities = $data['abilities'];
        }
        $this->em->flush();

        return $this->json([
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

        return $this->json(['public_api' => $project->publicApi]);
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
            return $this->json(['error' => 'Token not found'], 404);
        }

        $this->em->remove($token);
        $this->em->flush();

        return $this->json(null, 204);
    }

    // ─── Token-aware resolver (shared by JWT TTL + Mailer) ─────────────────

    /** Resolve project via session user OR ApiToken (both accepted). */
    private function resolveWithTokenFallback(string $projectUuid, Request $request): \App\Entity\Project|JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if ($project === null) {
            return $this->json(['error' => 'Project not found'], 404);
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

        return $this->json(['error' => 'Access denied'], 403);
    }

    // ─── JWT TTL ──────────────────────────────────────────────────────────

    #[Route('/jwt-ttl', name: 'jwt_ttl_get', methods: ['GET'])]
    public function getJwtTtl(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->resolveWithTokenFallback($projectUuid, $request);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        return $this->json([
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

        $data = $request->toArray();
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
            return $this->json([
                'error' => sprintf(
                    'jwt_refresh_ttl (%d s) must be >= jwt_access_ttl (%d s).',
                    $finalRefresh, $finalAccess,
                ),
            ], 422);
        }

        $this->em->flush();

        return $this->json([
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
            return $this->json([
                'error' => sprintf('jwt_%s_ttl must be at least 60 seconds.', $label),
            ], 422);
        }

        if ($ttl > EndUserJwtService::MAX_TTL) {
            return $this->json([
                'error' => sprintf(
                    'jwt_%s_ttl must not exceed %d seconds (1 year).',
                    $label, EndUserJwtService::MAX_TTL,
                ),
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
            return $this->json(['data' => null]);
        }

        return $this->json(['data' => [
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

        $settings = $this->mailerSettingsRepo->findByProject($project);
        if ($settings === null) {
            $settings = new ProjectMailerSettings();
            $settings->project = $project;
            $this->em->persist($settings);
        }

        try {
            $body = $request->toArray();
        } catch (\JsonException) {
            return $this->json(['error' => 'Invalid JSON'], 400);
        }

        if (isset($body['host'])) {
            $host = (string) $body['host'];
            if (!filter_var(gethostbyname($host), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $this->json(['error' => 'Invalid host: private/internal IPs are not allowed.'], 400);
            }
            $settings->host = $host;
        }
        if (isset($body['port'])) {
            $port = (int) $body['port'];
            if (!in_array($port, [25, 465, 587, 2525], true)) {
                return $this->json(['error' => 'Invalid port: only SMTP ports allowed (25, 465, 587, 2525).'], 400);
            }
            $settings->port = $port;
        }
        if (isset($body['username']))    $settings->username = (string) $body['username'];
        if (isset($body['encryption'])) {
            $enc = (string) $body['encryption'];
            if (!in_array($enc, ['tls', 'ssl', 'none'], true)) {
                return $this->json(['error' => 'Invalid encryption: must be tls, ssl, or none.'], 400);
            }
            $settings->encryption = $enc;
        }
        if (isset($body['from_email']))  $settings->fromEmail = (string) $body['from_email'];
        if (isset($body['from_name']))   $settings->fromName = (string) $body['from_name'];
        if (isset($body['enabled']))     $settings->enabled = (bool) $body['enabled'];

        // Ne mettre à jour le password que s'il est fourni (non vide)
        if (!empty($body['password'] ?? '')) {
            $settings->encryptedPassword = $this->mailerService->encryptPassword((string) $body['password']);
        }

        $this->em->flush();

        return $this->json(['data' => [
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

        $settings = $this->mailerSettingsRepo->findByProject($project);
        if ($settings === null || !$settings->enabled) {
            return $this->json(['error' => 'Mailer not configured or disabled'], 422);
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
            return $this->json(['error' => 'Failed to send test email. Check your SMTP configuration and try again.'], 422);
        }

        return $this->json(['sent' => true]);
    }

    private function resolveProject(string $projectUuid): \App\Entity\Project|JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if ($project === null) {
            return $this->json(['error' => 'Project not found'], 404);
        }
        $user = $this->getUser();
        if (!in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true) && !$project->hasMember($user)) {
            return $this->json(['error' => 'Access denied'], 403);
        }
        return $project;
    }
}
