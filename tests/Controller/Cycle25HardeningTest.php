<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\Api\EndUserAdminController;
use App\Controller\Api\EndUserAuthController;
use App\Controller\MercureFallbackController;
use App\Controller\RealtimeController;
use App\Controller\Settings\PersonalAccessTokenController;
use App\Dto\SocialUser;
use App\Entity\EndUser;
use App\Entity\PersonalAccessToken;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\EndUserRepository;
use App\Repository\PersonalAccessTokenRepository;
use App\Repository\ProjectAuthRefreshTokenRepository;
use App\Repository\ProjectAuthSessionRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Service\ApiTokenChecker;
use App\Service\EndUserJwtService;
use App\Service\SocialLoginService;
use App\Service\TwoFactorService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Cycle 25 — Security Hardening Tests
 *
 * Covers:
 *  1. RealtimeController: batch event capping (max 100 per poll) & custom limit validation to prevent memory/latency DoS.
 *  2. MercureFallbackController: rate limiting on fallback endpoint to prevent connection floods.
 *  3. EndUserAuthController: social login rate limiting, inactive/banned account rejection, valid 2FA token generation, and social URL endpoint.
 *  4. EndUserAdminController: session & refresh token revocation on ban/delete, username & custom_fields sanitization.
 *  5. PersonalAccessTokenController: name sanitization (max 255 chars, control char rejection), scope validation, and per-user token ceiling (50 max).
 */
class Cycle25HardeningTest extends TestCase
{
    private function createContainerWithUser(?User $user, array $parameters = []): Container
    {
        $container = new Container();
        $container->set('parameter_bag', new ParameterBag($parameters));

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        if ($user !== null) {
            $token = $this->createMock(TokenInterface::class);
            $token->method('getUser')->willReturn($user);
            $tokenStorage->method('getToken')->willReturn($token);
        } else {
            $tokenStorage->method('getToken')->willReturn(null);
        }
        $container->set('security.token_storage', $tokenStorage);

        $authChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturn(true);
        $container->set('security.authorization_checker', $authChecker);

        return $container;
    }

    private function createLimiter(int $limit, string $interval = '60 seconds'): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'test_' . uniqid(), 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => $interval],
            new InMemoryStorage()
        );
    }

    // ─── 1. RealtimeController: Event Capping ───────────────────────────────

    public function testRealtimePollCapsEventsAt100AndSupportsLimit(): void
    {
        $projectUuid = '00000000-0000-0000-0000-000000000025';
        $tmpDir = sys_get_temp_dir() . '/jambo_rt_' . bin2hex(random_bytes(4));
        $realtimeDir = $tmpDir . '/var/realtime';
        mkdir($realtimeDir, 0700, true);

        // Generate 150 events
        $eventFile = $realtimeDir . '/' . $projectUuid . '.jsonl';
        $lines = [];
        for ($i = 0; $i < 150; $i++) {
            $lines[] = json_encode(['event' => 'content:created', 'index' => $i]);
        }
        file_put_contents($eventFile, implode("\n", $lines) . "\n");

        $project = new Project();
        $project->uuid = Uuid::fromString($projectUuid);

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);
        $memberRepo = $this->createMock(ProjectMemberRepository::class);

        $admin = new User();
        $admin->roles = ['ROLE_SUPER_ADMIN'];

        $controller = new RealtimeController($projectRepo, $memberRepo, false, null);
        $controller->setContainer($this->createContainerWithUser($admin, ['kernel.project_dir' => $tmpDir]));

        // First poll: since=0 -> should return only 100 events (capped), since=100
        $req1 = Request::create('/?since=0');
        $res1 = $controller->poll($projectUuid, $req1);
        $this->assertSame(200, $res1->getStatusCode());
        $data1 = json_decode((string) $res1->getContent(), true);
        $this->assertCount(100, $data1['events']);
        $this->assertSame(100, $data1['since']);

        // Second poll: since=100 -> should return remaining 50 events, since=150
        $req2 = Request::create('/?since=100');
        $res2 = $controller->poll($projectUuid, $req2);
        $this->assertSame(200, $res2->getStatusCode());
        $data2 = json_decode((string) $res2->getContent(), true);
        $this->assertCount(50, $data2['events']);
        $this->assertSame(150, $data2['since']);

        // Custom limit: limit=15 -> should return 15 events
        $req3 = Request::create('/?since=0&limit=15');
        $res3 = $controller->poll($projectUuid, $req3);
        $this->assertSame(200, $res3->getStatusCode());
        $data3 = json_decode((string) $res3->getContent(), true);
        $this->assertCount(15, $data3['events']);
        $this->assertSame(15, $data3['since']);

        @unlink($eventFile);
        @rmdir($realtimeDir);
        @rmdir($tmpDir . '/var');
        @rmdir($tmpDir);
    }

    // ─── 2. MercureFallbackController: Rate Limiting ────────────────────────

    public function testMercureFallbackRateLimiterAndOptions(): void
    {
        $limiter = $this->createLimiter(1);
        $controller = new MercureFallbackController($limiter);

        // OPTIONS request should not consume rate limiter and return 204
        $reqOptions = Request::create('/.well-known/mercure', 'OPTIONS');
        $resOptions = $controller->handle($reqOptions);
        $this->assertSame(204, $resOptions->getStatusCode());

        // First POST request consumes the quota
        $reqPost1 = Request::create('/.well-known/mercure', 'POST', [], [], [], ['REMOTE_ADDR' => '192.168.1.1']);
        $resPost1 = $controller->handle($reqPost1);
        $this->assertSame(503, $resPost1->getStatusCode());

        // Second POST request exceeds quota and gets 429
        $reqPost2 = Request::create('/.well-known/mercure', 'POST', [], [], [], ['REMOTE_ADDR' => '192.168.1.1']);
        $resPost2 = $controller->handle($reqPost2);
        $this->assertSame(429, $resPost2->getStatusCode());
        $payload = json_decode((string) $resPost2->getContent(), true);
        $this->assertSame('Too many requests. Try again later.', $payload['error']);
    }

    // ─── 3. EndUserAuthController: Social Login Hardening ───────────────────

    public function testEndUserSocialLoginRejectsBannedUser(): void
    {
        $projectUuid = '00000000-0000-0000-0000-000000000025';
        $project = new Project();
        $project->uuid = Uuid::fromString($projectUuid);
        $project->settings = ['security' => ['endUserSocialLogin' => true]];

        $bannedUser = new EndUser($project, 'banned@example.com');
        $bannedUser->status = 'banned';

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $socialLogin = $this->createMock(SocialLoginService::class);
        $socialLogin->method('getProjectCredentials')->willReturn(['clientId' => 'cid', 'clientSecret' => 'sec']);
        $socialLogin->method('getUserFromProvider')->willReturn(new SocialUser('123', 'banned@example.com', 'banned_user', null));
        $socialLogin->method('findOrCreateEndUser')->willReturn($bannedUser);

        $jwtService = $this->createMock(EndUserJwtService::class);
        $twoFactorService = $this->createMock(TwoFactorService::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $endUserRepo = $this->createMock(EndUserRepository::class);

        $controller = new EndUserAuthController(
            $projectRepo,
            $endUserRepo,
            $jwtService,
            $twoFactorService,
            $hasher,
            $em,
            $socialLogin
        );
        $controller->setContainer($this->createContainerWithUser(null));

        $req = Request::create('/api/' . $projectUuid . '/auth/social/google', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['code' => 'auth_code_123']));

        $res = $controller->socialLogin($req, $projectUuid, 'google');
        $this->assertSame(403, $res->getStatusCode());
        $payload = json_decode((string) $res->getContent(), true);
        $this->assertSame('Account is inactive or banned', $payload['error']);
    }

    public function testEndUserSocialLoginTwoFactorFlow(): void
    {
        $projectUuid = '00000000-0000-0000-0000-000000000025';
        $project = new Project();
        $project->uuid = Uuid::fromString($projectUuid);
        $project->settings = ['security' => ['endUserSocialLogin' => true]];

        $userWith2fa = new EndUser($project, 'twofa@example.com');
        $userWith2fa->status = 'active';
        $userWith2fa->twoFactorEnabled = true;
        $userWith2fa->twoFactorMethod = 'totp';

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $socialLogin = $this->createMock(SocialLoginService::class);
        $socialLogin->method('getProjectCredentials')->willReturn(['clientId' => 'cid', 'clientSecret' => 'sec']);
        $socialLogin->method('getUserFromProvider')->willReturn(new SocialUser('456', 'twofa@example.com', 'twofa_user', null));
        $socialLogin->method('findOrCreateEndUser')->willReturn($userWith2fa);

        $jwtService = $this->createMock(EndUserJwtService::class);
        $jwtService->method('createTwoFactorToken')->willReturn('valid_jwt_2fa_token');

        $twoFactorService = $this->createMock(TwoFactorService::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $endUserRepo = $this->createMock(EndUserRepository::class);

        $controller = new EndUserAuthController(
            $projectRepo,
            $endUserRepo,
            $jwtService,
            $twoFactorService,
            $hasher,
            $em,
            $socialLogin
        );
        $controller->setContainer($this->createContainerWithUser(null));

        $req = Request::create('/api/' . $projectUuid . '/auth/social/google', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['code' => 'auth_code_456']));

        $res = $controller->socialLogin($req, $projectUuid, 'google');
        $this->assertSame(200, $res->getStatusCode());
        $payload = json_decode((string) $res->getContent(), true);
        $this->assertTrue($payload['requires_2fa']);
        $this->assertSame('valid_jwt_2fa_token', $payload['two_factor_token']);
        $this->assertSame('totp', $payload['two_factor_method']);
    }

    public function testEndUserSocialUrlEndpoint(): void
    {
        $projectUuid = '00000000-0000-0000-0000-000000000025';
        $project = new Project();
        $project->uuid = Uuid::fromString($projectUuid);
        $project->settings = ['security' => ['endUserSocialLogin' => true]];

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $socialLogin = $this->createMock(SocialLoginService::class);
        $socialLogin->method('getProjectCredentials')->willReturn(['clientId' => 'cid', 'clientSecret' => 'sec']);
        $socialLogin->method('getAuthorizationUrlAndState')->willReturn([
            'url' => 'https://accounts.google.com/o/oauth2/auth?client_id=cid',
            'state' => 'csrf_state_token_123',
        ]);

        $controller = new EndUserAuthController(
            $projectRepo,
            $this->createMock(EndUserRepository::class),
            $this->createMock(EndUserJwtService::class),
            $this->createMock(TwoFactorService::class),
            $this->createMock(UserPasswordHasherInterface::class),
            $this->createMock(EntityManagerInterface::class),
            $socialLogin
        );
        $controller->setContainer($this->createContainerWithUser(null));

        $req = Request::create('/api/' . $projectUuid . '/auth/social/google', 'GET');
        $res = $controller->getSocialUrl($req, $projectUuid, 'google');
        $this->assertSame(200, $res->getStatusCode());
        $payload = json_decode((string) $res->getContent(), true);
        $this->assertStringContainsString('accounts.google.com', $payload['url']);
        $this->assertSame('csrf_state_token_123', $payload['state']);

        // Unknown provider returns 400
        $resUnknown = $controller->getSocialUrl($req, $projectUuid, 'unknown_oauth');
        $this->assertSame(400, $resUnknown->getStatusCode());
    }

    // ─── 4. EndUserAdminController: Revocation & Validation ──────────────────

    public function testEndUserAdminRevokesSessionsAndRefreshTokensOnBanAndDestroy(): void
    {
        $projectUuid = '00000000-0000-0000-0000-000000000025';
        $userUuid = '00000000-0000-0000-0000-000000000099';
        $project = new Project();
        $project->uuid = Uuid::fromString($projectUuid);

        $endUser = new EndUser($project, 'victim@test.com');
        $endUser->uuid = Uuid::fromString($userUuid);
        $endUser->status = 'active';

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $endUserRepo = $this->createMock(EndUserRepository::class);
        $endUserRepo->method('findOneBy')->willReturn($endUser);

        $sessionRepo = $this->createMock(ProjectAuthSessionRepository::class);
        $sessionRepo->expects($this->exactly(2))->method('revokeAllForEndUser')->with($endUser);

        $refreshTokenRepo = $this->createMock(ProjectAuthRefreshTokenRepository::class);
        $refreshTokenRepo->expects($this->exactly(2))->method('revokeAllForEndUser')->with($endUser);

        $em = $this->createMock(EntityManagerInterface::class);
        $memberRepo = $this->createMock(ProjectMemberRepository::class);
        $admin = new User();
        $admin->roles = ['ROLE_SUPER_ADMIN'];

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($admin);
        $tokenChecker = $this->createMock(ApiTokenChecker::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($admin);

        $controller = new EndUserAdminController(
            $projectRepo,
            $endUserRepo,
            $memberRepo,
            $em,
            $security,
            $tokenChecker,
            $hasher,
            $sessionRepo,
            $refreshTokenRepo
        );
        $controller->setContainer($this->createContainerWithUser($admin));

        // 1. Status change to 'banned' should invoke revokeAllForEndUser
        $reqBan = Request::create('/', 'PATCH', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['status' => 'banned']));
        $reqBan->attributes->set('_project', $project);
        $resBan = $controller->status($projectUuid, $userUuid, $reqBan);
        $this->assertSame(200, $resBan->getStatusCode());
        $this->assertSame('banned', $endUser->status);

        // 2. Destroy should invoke revokeAllForEndUser and remove entity
        $reqDel = Request::create('/', 'DELETE');
        $reqDel->attributes->set('_project', $project);
        $resDel = $controller->destroy($projectUuid, $userUuid, $reqDel);
        $this->assertSame(200, $resDel->getStatusCode());
    }

    public function testEndUserAdminInputSanitization(): void
    {
        $projectUuid = '00000000-0000-0000-0000-000000000025';
        $project = new Project();
        $project->uuid = Uuid::fromString($projectUuid);

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);
        $endUserRepo = $this->createMock(EndUserRepository::class);
        $memberRepo = $this->createMock(ProjectMemberRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $admin = new User();
        $admin->roles = ['ROLE_SUPER_ADMIN'];

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($admin);
        $tokenChecker = $this->createMock(ApiTokenChecker::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);

        $controller = new EndUserAdminController(
            $projectRepo,
            $endUserRepo,
            $memberRepo,
            $em,
            $security,
            $tokenChecker,
            $hasher
        );
        $controller->setContainer($this->createContainerWithUser($admin));

        // Username too long (> 100 characters)
        $reqLongUser = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => 'valid@test.com',
            'password' => 'secret123',
            'username' => str_repeat('a', 105),
        ]));
        $reqLongUser->attributes->set('_project', $project);
        $resLongUser = $controller->create($projectUuid, $reqLongUser);
        $this->assertSame(422, $resLongUser->getStatusCode());
        $payload = json_decode((string) $resLongUser->getContent(), true);
        $this->assertStringContainsString('max 100 characters', $payload['error']);

        // Username with control characters
        $reqCtrlUser = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => 'valid@test.com',
            'password' => 'secret123',
            'username' => "test\x00user",
        ]));
        $reqCtrlUser->attributes->set('_project', $project);
        $resCtrlUser = $controller->create($projectUuid, $reqCtrlUser);
        $this->assertSame(422, $resCtrlUser->getStatusCode());

        // Custom fields payload too large (> 64KB)
        $reqLargeFields = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => 'valid@test.com',
            'password' => 'secret123',
            'custom_fields' => ['large_payload' => str_repeat('x', 70000)],
        ]));
        $reqLargeFields->attributes->set('_project', $project);
        $resLargeFields = $controller->create($projectUuid, $reqLargeFields);
        $this->assertSame(422, $resLargeFields->getStatusCode());
        $payloadCf = json_decode((string) $resLargeFields->getContent(), true);
        $this->assertStringContainsString('max 64KB', $payloadCf['error']);
    }

    // ─── 5. PersonalAccessTokenController: Validation & Limits ───────────────

    public function testPersonalAccessTokenValidationAndLimit(): void
    {
        $user = new User();
        $user->name = 'Test PAT Owner';
        $user->email = 'patowner@test.com';

        $repo = $this->createMock(PersonalAccessTokenRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $controller = new PersonalAccessTokenController($repo, $em, 'app_secret_123', null);
        $controller->setContainer($this->createContainerWithUser($user));

        // 1. Name with control characters
        $repo->method('findByUser')->willReturn([]);
        $reqCtrl = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => "token\r\nbad",
            'scopes' => ['schema:write'],
        ]));
        $resCtrl = $controller->create($reqCtrl);
        $this->assertSame(422, $resCtrl->getStatusCode());
        $payloadCtrl = json_decode((string) $resCtrl->getContent(), true);
        $this->assertStringContainsString('control characters', $payloadCtrl['errors']['name']);

        // 2. Name too long (> 255 chars)
        $reqLong = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => str_repeat('a', 260),
            'scopes' => ['schema:write'],
        ]));
        $resLong = $controller->create($reqLong);
        $this->assertSame(422, $resLong->getStatusCode());
        $payloadLong = json_decode((string) $resLong->getContent(), true);
        $this->assertStringContainsString('not exceed 255', $payloadLong['errors']['name']);

        // 3. Invalid scopes
        $reqScope = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'valid token',
            'scopes' => ['unauthorized:scope'],
        ]));
        $resScope = $controller->create($reqScope);
        $this->assertSame(422, $resScope->getStatusCode());
        $payloadScope = json_decode((string) $resScope->getContent(), true);
        $this->assertStringContainsString('Invalid scope', $payloadScope['errors']['scopes']);

        // 4. Max limit reached (50 tokens)
        $existing = [];
        for ($i = 0; $i < 50; $i++) {
            $existing[] = new PersonalAccessToken();
        }
        $repoLimit = $this->createMock(PersonalAccessTokenRepository::class);
        $repoLimit->method('findByUser')->willReturn($existing);

        $controllerLimit = new PersonalAccessTokenController($repoLimit, $em, 'app_secret_123', null);
        $controllerLimit->setContainer($this->createContainerWithUser($user));

        $reqMax = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => '51st token',
            'scopes' => ['schema:write'],
        ]));
        $resMax = $controllerLimit->create($reqMax);
        $this->assertSame(422, $resMax->getStatusCode());
        $payloadMax = json_decode((string) $resMax->getContent(), true);
        $this->assertStringContainsString('50 tokens max', $payloadMax['errors']['name']);
    }
}
