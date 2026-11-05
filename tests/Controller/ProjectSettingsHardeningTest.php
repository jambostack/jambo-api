<?php

namespace App\Tests\Controller;

use App\Controller\ProjectSettingsController;
use App\Entity\ApiToken;
use App\Entity\Project;
use App\Entity\ProjectMailerSettings;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Repository\ProjectMailerSettingsRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Service\ApiTokenChecker;
use App\Service\AuditService;
use App\Service\ProjectMailerService;
use App\Service\Security\SsrfProtectionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

class ProjectSettingsHardeningTest extends TestCase
{
    private string $validUuid = '11111111-2222-3333-4444-555555555555';

    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(function (string $id, array $params = [], ?string $domain = null) {
            $dict = require __DIR__ . '/../../translations/messages.en.php';
            if (isset($dict[$id])) {
                $text = $dict[$id];
                foreach ($params as $k => $v) {
                    $text = str_replace($k, (string) $v, $text);
                }
                return $text;
            }
            return $id;
        });
        return $translator;
    }

    private function createRateLimiter(int $limit = 1000): RateLimiterFactory
    {
        return new RateLimiterFactory([
            'id'       => 'test_limiter',
            'policy'   => 'sliding_window',
            'limit'    => $limit,
            'interval' => '60 seconds',
        ], new InMemoryStorage());
    }

    private function createProject(): Project
    {
        $project = new Project();
        $project->uuid = Uuid::fromString($this->validUuid);
        $project->name = 'Hardening Project';
        $project->defaultLocale = 'en';
        $project->locales = ['en', 'fr'];
        return $project;
    }

    private function createController(
        Project $project,
        ?RateLimiterFactory $tokenLimiter = null,
        ?RateLimiterFactory $mailerLimiter = null,
        ?User $user = null,
        ?ProjectMailerSettings $mailerSettings = null,
        array $existingTokens = [],
    ): ProjectSettingsController {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturnCallback(function ($criteria) use ($project) {
            if (($criteria['uuid'] ?? '') === $project->uuid?->toRfc4122()) {
                return $project;
            }
            return null;
        });

        $apiTokenRepo = $this->createMock(ApiTokenRepository::class);
        $apiTokenRepo->method('findByProject')->willReturn($existingTokens);
        $apiTokenRepo->method('findOneBy')->willReturnCallback(function ($criteria) use ($existingTokens) {
            foreach ($existingTokens as $t) {
                if ($t->id === ($criteria['id'] ?? null)) {
                    return $t;
                }
            }
            return null;
        });

        $memberRepo = $this->createMock(ProjectMemberRepository::class);
        $mailerSettingsRepo = $this->createMock(ProjectMailerSettingsRepository::class);
        $mailerSettingsRepo->method('findByProject')->willReturn($mailerSettings);

        $mailerService = $this->createMock(ProjectMailerService::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $tokenChecker = $this->createMock(ApiTokenChecker::class);
        $logger = $this->createMock(LoggerInterface::class);
        $translator = $this->createTranslator();
        $ssrf = new SsrfProtectionService();
        $auditService = $this->createMock(AuditService::class);

        $currentUser = $user ?? (function () {
            $u = new User();
            $u->email = 'admin@example.com';
            $u->roles = ['ROLE_SUPER_ADMIN'];
            return $u;
        })();

        return new class(
            $projectRepo,
            $apiTokenRepo,
            $memberRepo,
            $mailerSettingsRepo,
            $mailerService,
            $em,
            $tokenChecker,
            $logger,
            'test_secret_32_characters_long_123',
            $tokenLimiter,
            $mailerLimiter,
            $translator,
            $ssrf,
            $auditService,
            $currentUser
        ) extends ProjectSettingsController {
            public function __construct(
                $projectRepo, $apiTokenRepo, $memberRepo, $mailerSettingsRepo, $mailerService,
                $em, $tokenChecker, $logger, $appSecret, $tokenLimiter, $mailerLimiter,
                $translator, $ssrf, $auditService, private readonly ?User $activeUser
            ) {
                parent::__construct(
                    $projectRepo, $apiTokenRepo, $memberRepo, $mailerSettingsRepo, $mailerService,
                    $em, $tokenChecker, $logger, $appSecret, $tokenLimiter, $mailerLimiter,
                    $translator, $ssrf, $auditService
                );
            }

            public function getUser(): ?User { return $this->activeUser; }
        };
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 1. Localization Hardening Tests
    // ──────────────────────────────────────────────────────────────────────────

    public function testLocalizationPayloadTooLargeAndInvalidJson(): void
    {
        $project = $this->createProject();
        $controller = $this->createController($project);

        // Payload too large (> 64 KB)
        $huge = json_encode(['locales' => array_fill(0, 15000, 'en')]);
        $req1 = Request::create('/api/projects/' . $this->validUuid . '/settings/localization', 'PATCH', [], [], [], ['CONTENT_TYPE' => 'application/json'], $huge);
        $res1 = $controller->updateLocalization($this->validUuid, $req1);
        $this->assertSame(413, $res1->getStatusCode());
        $data1 = json_decode($res1->getContent(), true);
        $this->assertSame('Payload exceeds maximum size limit.', $data1['error']);
        $this->assertSame('PAYLOAD_TOO_LARGE', $data1['code']);

        // Invalid JSON
        $req2 = Request::create('/api/projects/' . $this->validUuid . '/settings/localization', 'PATCH', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{bad-json');
        $res2 = $controller->updateLocalization($this->validUuid, $req2);
        $this->assertSame(400, $res2->getStatusCode());
        $data2 = json_decode($res2->getContent(), true);
        $this->assertSame('Invalid JSON body.', $data2['error']);
        $this->assertSame('INVALID_JSON', $data2['code']);
    }

    public function testLocalizationValidatesMaxCountAndFormat(): void
    {
        $project = $this->createProject();
        $controller = $this->createController($project);

        // Max locales exceeded (> 50)
        $manyLocales = array_map(fn ($i) => 'loc' . $i, range(1, 55));
        $req1 = Request::create('/api/projects/' . $this->validUuid . '/settings/localization', 'PATCH', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'locales' => $manyLocales,
        ]));
        $res1 = $controller->updateLocalization($this->validUuid, $req1);
        $this->assertSame(422, $res1->getStatusCode());
        $data1 = json_decode($res1->getContent(), true);
        $this->assertSame('Maximum number of locales reached (50 max).', $data1['error']);
        $this->assertSame('MAX_LOCALES_EXCEEDED', $data1['code']);

        // Invalid locale format (special chars / path injection)
        $req2 = Request::create('/api/projects/' . $this->validUuid . '/settings/localization', 'PATCH', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'locales' => ['en', '../bad-locale'],
        ]));
        $res2 = $controller->updateLocalization($this->validUuid, $req2);
        $this->assertSame(422, $res2->getStatusCode());
        $data2 = json_decode($res2->getContent(), true);
        $this->assertSame('Invalid locale format.', $data2['error']);
        $this->assertSame('INVALID_LOCALE_FORMAT', $data2['code']);

        // Default locale missing from list
        $req3 = Request::create('/api/projects/' . $this->validUuid . '/settings/localization', 'PATCH', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'default_locale' => 'es', // not in locales ['en', 'fr']
        ]));
        $res3 = $controller->updateLocalization($this->validUuid, $req3);
        $this->assertSame(422, $res3->getStatusCode());
        $data3 = json_decode($res3->getContent(), true);
        $this->assertSame('default_locale must be present in the locales list.', $data3['error']);
        $this->assertSame('DEFAULT_LOCALE_NOT_IN_LIST', $data3['code']);
    }

    public function testDeleteDefaultLocaleForbidden(): void
    {
        $project = $this->createProject();
        $controller = $this->createController($project);

        $res = $controller->deleteLocale($this->validUuid, 'en');
        $this->assertSame(422, $res->getStatusCode());
        $data = json_decode($res->getContent(), true);
        $this->assertSame('Cannot delete the default locale.', $data['error']);
        $this->assertSame('CANNOT_DELETE_DEFAULT_LOCALE', $data['code']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 2. API Tokens Hardening Tests
    // ──────────────────────────────────────────────────────────────────────────

    public function testCreateTokenMaxLimitAndRateLimit(): void
    {
        $project = $this->createProject();
        $existingTokens = array_fill(0, 50, new ApiToken());
        $tokenLimiter = $this->createRateLimiter(1);
        $controller = $this->createController($project, $tokenLimiter, null, null, null, $existingTokens);

        // Max tokens limit
        $req = Request::create('/api/projects/' . $this->validUuid . '/settings/tokens', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => '51st Token',
        ]));
        $res = $controller->createToken($this->validUuid, $req);
        $this->assertSame(422, $res->getStatusCode());
        $data = json_decode($res->getContent(), true);
        $this->assertSame('Maximum token limit reached for this project (50 tokens max).', $data['error']);
        $this->assertSame('MAX_TOKENS_REACHED', $data['code']);
    }

    public function testCreateTokenNameAndAbilitiesValidation(): void
    {
        $project = $this->createProject();
        $controller = $this->createController($project);

        // Name containing control characters
        $req1 = Request::create('/api/projects/' . $this->validUuid . '/settings/tokens', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => "Bad\nToken",
        ]));
        $res1 = $controller->createToken($this->validUuid, $req1);
        $this->assertSame(422, $res1->getStatusCode());
        $data1 = json_decode($res1->getContent(), true);
        $this->assertSame('Token name contains invalid control characters.', $data1['error']);
        $this->assertSame('NAME_CONTROL_CHARS', $data1['code']);

        // Invalid ability
        $req2 = Request::create('/api/projects/' . $this->validUuid . '/settings/tokens', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Valid Name',
            'abilities' => ['read', 'root:escalate'],
        ]));
        $res2 = $controller->createToken($this->validUuid, $req2);
        $this->assertSame(422, $res2->getStatusCode());
        $data2 = json_decode($res2->getContent(), true);
        $this->assertSame('Abilities must be an array of valid permissions (read, create, update, delete, *).', $data2['error']);
        $this->assertSame('INVALID_ABILITIES', $data2['code']);

        // Expiration date in past
        $req3 = Request::create('/api/projects/' . $this->validUuid . '/settings/tokens', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Valid Name',
            'expires_at' => '2020-01-01T00:00:00Z',
        ]));
        $res3 = $controller->createToken($this->validUuid, $req3);
        $this->assertSame(422, $res3->getStatusCode());
        $data3 = json_decode($res3->getContent(), true);
        $this->assertSame('Expiration date must be in the future.', $data3['error']);
        $this->assertSame('EXPIRES_MUST_BE_FUTURE', $data3['code']);
    }

    public function testCreateTokenSuccessReturnsPlainOnce(): void
    {
        $project = $this->createProject();
        $controller = $this->createController($project);

        $req = Request::create('/api/projects/' . $this->validUuid . '/settings/tokens', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Production CI/CD',
            'abilities' => ['read', 'create'],
        ]));
        $res = $controller->createToken($this->validUuid, $req);
        $this->assertSame(201, $res->getStatusCode());
        $data = json_decode($res->getContent(), true);
        $this->assertNotEmpty($data['token']);
        $this->assertSame('Production CI/CD', $data['name']);
        $this->assertSame(['read', 'create'], $data['abilities']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 3. JWT TTL Hardening Tests
    // ──────────────────────────────────────────────────────────────────────────

    public function testJwtTtlMinMaxAndCrossValidation(): void
    {
        $project = $this->createProject();
        $controller = $this->createController($project);

        // Min TTL (< 60s)
        $req1 = Request::create('/api/projects/' . $this->validUuid . '/settings/jwt-ttl', 'PATCH', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'jwt_access_ttl' => 30,
        ]));
        $res1 = $controller->updateJwtTtl($this->validUuid, $req1);
        $this->assertSame(422, $res1->getStatusCode());
        $data1 = json_decode($res1->getContent(), true);
        $this->assertSame('jwt_access_ttl must be at least 60 seconds.', $data1['error']);
        $this->assertSame('TTL_TOO_SMALL', $data1['code']);

        // Max TTL (> 1 year)
        $req2 = Request::create('/api/projects/' . $this->validUuid . '/settings/jwt-ttl', 'PATCH', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'jwt_access_ttl' => 40000000,
        ]));
        $res2 = $controller->updateJwtTtl($this->validUuid, $req2);
        $this->assertSame(422, $res2->getStatusCode());
        $data2 = json_decode($res2->getContent(), true);
        $this->assertSame('jwt_access_ttl must not exceed 31536000 seconds (1 year).', $data2['error']);
        $this->assertSame('TTL_TOO_LARGE', $data2['code']);

        // Refresh < Access
        $req3 = Request::create('/api/projects/' . $this->validUuid . '/settings/jwt-ttl', 'PATCH', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'jwt_access_ttl' => 3600,
            'jwt_refresh_ttl' => 1800,
        ]));
        $res3 = $controller->updateJwtTtl($this->validUuid, $req3);
        $this->assertSame(422, $res3->getStatusCode());
        $data3 = json_decode($res3->getContent(), true);
        $this->assertSame('jwt_refresh_ttl (1800 s) must be >= jwt_access_ttl (3600 s).', $data3['error']);
        $this->assertSame('REFRESH_LESS_THAN_ACCESS', $data3['code']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 4. Mailer Anti-SSRF & Configuration Hardening Tests
    // ──────────────────────────────────────────────────────────────────────────

    public function testMailerAntiSsrfAndValidation(): void
    {
        $project = $this->createProject();
        $controller = $this->createController($project);

        // SSRF: Loopback address
        $req1 = Request::create('/api/projects/' . $this->validUuid . '/settings/mailer', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'host' => '127.0.0.1',
        ]));
        $res1 = $controller->updateMailer($this->validUuid, $req1);
        $this->assertSame(400, $res1->getStatusCode());
        $data1 = json_decode($res1->getContent(), true);
        $this->assertSame('Invalid mail host: private or reserved network addresses are not allowed.', $data1['error']);
        $this->assertSame('SSRF_DETECTED', $data1['code']);

        // SSRF: Cloud Metadata
        $req2 = Request::create('/api/projects/' . $this->validUuid . '/settings/mailer', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'host' => '169.254.169.254',
        ]));
        $res2 = $controller->updateMailer($this->validUuid, $req2);
        $this->assertSame(400, $res2->getStatusCode());
        $data2 = json_decode($res2->getContent(), true);
        $this->assertSame('Invalid mail host: private or reserved network addresses are not allowed.', $data2['error']);
        $this->assertSame('SSRF_DETECTED', $data2['code']);

        // Invalid SMTP port
        $req3 = Request::create('/api/projects/' . $this->validUuid . '/settings/mailer', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'port' => 8080,
        ]));
        $res3 = $controller->updateMailer($this->validUuid, $req3);
        $this->assertSame(400, $res3->getStatusCode());
        $data3 = json_decode($res3->getContent(), true);
        $this->assertSame('Invalid port: only SMTP ports allowed (25, 465, 587, 2525).', $data3['error']);
        $this->assertSame('INVALID_PORT', $data3['code']);

        // CRLF in from_name
        $req4 = Request::create('/api/projects/' . $this->validUuid . '/settings/mailer', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'from_name' => "Admin\r\nBcc: victim@example.com",
        ]));
        $res4 = $controller->updateMailer($this->validUuid, $req4);
        $this->assertSame(422, $res4->getStatusCode());
        $data4 = json_decode($res4->getContent(), true);
        $this->assertSame('from_name contains invalid characters or newlines.', $data4['error']);
        $this->assertSame('CRLF_DETECTED', $data4['code']);
    }

    public function testMailerTestRateLimitAndDisabledCheck(): void
    {
        $project = $this->createProject();
        $mailerSettings = new ProjectMailerSettings();
        $mailerSettings->enabled = false;

        $mailerLimiter = $this->createRateLimiter(1);
        $controller = $this->createController($project, null, $mailerLimiter, null, $mailerSettings);

        // When mailer is disabled
        $req1 = Request::create('/api/projects/' . $this->validUuid . '/settings/mailer/test', 'POST');
        $res1 = $controller->testMailer($this->validUuid, $req1);
        $this->assertSame(422, $res1->getStatusCode());
        $data1 = json_decode($res1->getContent(), true);
        $this->assertSame('Mailer not configured or disabled.', $data1['error']);
        $this->assertSame('MAILER_DISABLED', $data1['code']);

        // Exhaust limiter
        $res2 = $controller->testMailer($this->validUuid, $req1);
        $this->assertSame(429, $res2->getStatusCode());
        $data2 = json_decode($res2->getContent(), true);
        $this->assertSame('Too many requests. Please retry later.', $data2['error']);
        $this->assertSame('RATE_LIMIT_EXCEEDED', $data2['code']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 5. Access Control Tests
    // ──────────────────────────────────────────────────────────────────────────

    public function testAccessDeniedForNonMembers(): void
    {
        $project = $this->createProject();
        $regularUser = new User();
        $regularUser->email = 'stranger@example.com';
        $regularUser->roles = ['ROLE_USER'];

        $controller = $this->createController($project, null, null, $regularUser);

        $res = $controller->listMembers($this->validUuid);
        $this->assertSame(403, $res->getStatusCode());
        $data = json_decode($res->getContent(), true);
        $this->assertSame('Access denied.', $data['error']);
        $this->assertSame('ACCESS_DENIED', $data['code']);
    }
}
