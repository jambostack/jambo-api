<?php

declare(strict_types=1);

namespace App\Tests\Controller\AdminApi;

use App\Controller\AdminApi\ApiKeyController;
use App\Controller\AdminApi\TokenController;
use App\Controller\ProjectSettingsController;
use App\Controller\Settings\PersonalAccessTokenController;
use App\Entity\ApiToken;
use App\Entity\PersonalAccessToken;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\Role;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Repository\PersonalAccessTokenRepository;
use App\Repository\ProjectMailerSettingsRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Service\ApiTokenChecker;
use App\Service\AuditService;
use App\Service\ProjectMailerService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

class Cycle109ApiKeyAndTokenSecurityHardeningTest extends TestCase
{
    private string $projectUuid = 'a0000000-0000-4000-8000-000000000109';
    private string $appSecret = 'cycle109_super_secret_test_key_32_bytes!';

    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(function (string $id, array $params = []) {
            $msg = 'trans:' . $id;
            foreach ($params as $k => $v) {
                $msg .= "[$k=$v]";
            }
            return $msg;
        });
        return $translator;
    }

    private function setContainer(object $controller, ?User $user = null): void
    {
        $container = new Container();
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        if ($user !== null) {
            $token = $this->createMock(TokenInterface::class);
            $token->method('getUser')->willReturn($user);
            $tokenStorage->method('getToken')->willReturn($token);
        } else {
            $tokenStorage->method('getToken')->willReturn(null);
        }
        $container->set('security.token_storage', $tokenStorage);
        $controller->setContainer($container);
    }

    private function createProject(User $owner): Project
    {
        $owner->id = 1;
        $owner->roles = ['ROLE_SUPER_ADMIN'];

        $project = new Project();
        $project->id = 109;
        $project->name = 'Cycle 109 Test Project';
        $project->uuid = Uuid::fromString($this->projectUuid);

        $member = new ProjectMember();
        $member->project = $project;
        $member->user = $owner;
        $member->email = $owner->email;
        $member->status = \App\Enum\ProjectMemberStatus::Active;
        $member->role = new Role();
        $project->projectMembers->add($member);

        return $project;
    }

    // ─── 1. ApiKeyController Security Hardening Tests ───

    public function testApiKeyControllerRejectsNonJsonContentType(): void
    {
        $owner = new User();
        $owner->email = 'admin@example.com';
        $project = $this->createProject($owner);

        $projRepo = $this->createMock(ProjectRepository::class);
        $projRepo->method('findOneBy')->willReturn($project);

        $apiTokenRepo = $this->createMock(ApiTokenRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $audit = $this->createMock(AuditService::class);

        $loggedFailures = [];
        $audit->method('log')->willReturnCallback(function (string $toolName, mixed $proj, array $input, mixed $output, string $status) use (&$loggedFailures) {
            $loggedFailures[] = ['tool' => $toolName, 'status' => $status];
            return new \App\Entity\AuditLog();
        });

        $controller = new ApiKeyController(
            projectRepository: $projRepo,
            apiTokenRepository: $apiTokenRepo,
            em: $em,
            appSecret: $this->appSecret,
            tokenLimiter: null,
            translator: $this->createTranslator(),
            auditService: $audit,
        );
        $this->setContainer($controller, $owner);

        // POST with text/plain Content-Type
        $request = Request::create(
            "/admin-api/projects/{$this->projectUuid}/api-keys",
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            'name=plain-token'
        );

        $response = $controller->create($this->projectUuid, $request);
        $this->assertSame(415, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertNotEmpty($loggedFailures);
        $this->assertSame('failure', $loggedFailures[0]['status']);
    }

    public function testApiKeyControllerRejectsExcessivePayloadSize(): void
    {
        $owner = new User();
        $owner->email = 'admin@example.com';
        $project = $this->createProject($owner);

        $projRepo = $this->createMock(ProjectRepository::class);
        $projRepo->method('findOneBy')->willReturn($project);

        $apiTokenRepo = $this->createMock(ApiTokenRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $controller = new ApiKeyController(
            projectRepository: $projRepo,
            apiTokenRepository: $apiTokenRepo,
            em: $em,
            appSecret: $this->appSecret,
            tokenLimiter: null,
            translator: $this->createTranslator(),
            auditService: null,
        );
        $this->setContainer($controller, $owner);

        $oversizedData = json_encode(['name' => str_repeat('A', 35000)]);
        $request = Request::create(
            "/admin-api/projects/{$this->projectUuid}/api-keys",
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $oversizedData
        );

        $response = $controller->create($this->projectUuid, $request);
        $this->assertSame(413, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('PAYLOAD_TOO_LARGE', $data['code']);
    }

    public function testApiKeyControllerValidatesNameAndAbilities(): void
    {
        $owner = new User();
        $owner->email = 'admin@example.com';
        $project = $this->createProject($owner);

        $projRepo = $this->createMock(ProjectRepository::class);
        $projRepo->method('findOneBy')->willReturn($project);

        $apiTokenRepo = $this->createMock(ApiTokenRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $controller = new ApiKeyController(
            projectRepository: $projRepo,
            apiTokenRepository: $apiTokenRepo,
            em: $em,
            appSecret: $this->appSecret,
            tokenLimiter: null,
            translator: $this->createTranslator(),
            auditService: null,
        );
        $this->setContainer($controller, $owner);

        // 1. Empty name
        $req1 = Request::create(
            "/admin-api/projects/{$this->projectUuid}/api-keys",
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => '   ', 'abilities' => ['read']])
        );
        $res1 = $controller->create($this->projectUuid, $req1);
        $this->assertSame(422, $res1->getStatusCode());

        // 2. Control characters in name
        $req2 = Request::create(
            "/admin-api/projects/{$this->projectUuid}/api-keys",
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => "Token\x00Name", 'abilities' => ['read']])
        );
        $res2 = $controller->create($this->projectUuid, $req2);
        $this->assertSame(422, $res2->getStatusCode());

        // 3. Invalid abilities
        $req3 = Request::create(
            "/admin-api/projects/{$this->projectUuid}/api-keys",
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Valid Name', 'abilities' => ['invalid_permission']])
        );
        $res3 = $controller->create($this->projectUuid, $req3);
        $this->assertSame(422, $res3->getStatusCode());

        // 4. Past expiration date
        $req4 = Request::create(
            "/admin-api/projects/{$this->projectUuid}/api-keys",
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Valid Name', 'abilities' => ['read'], 'expires_at' => '2020-01-01T00:00:00Z'])
        );
        $res4 = $controller->create($this->projectUuid, $req4);
        $this->assertSame(422, $res4->getStatusCode());
    }

    public function testApiKeyControllerEnforcesProjectQuota(): void
    {
        $owner = new User();
        $owner->email = 'admin@example.com';
        $project = $this->createProject($owner);

        $projRepo = $this->createMock(ProjectRepository::class);
        $projRepo->method('findOneBy')->willReturn($project);

        $apiTokenRepo = $this->createMock(ApiTokenRepository::class);
        // Return 50 existing tokens
        $apiTokenRepo->method('findByProject')->willReturn(array_fill(0, 50, new ApiToken()));

        $em = $this->createMock(EntityManagerInterface::class);

        $controller = new ApiKeyController(
            projectRepository: $projRepo,
            apiTokenRepository: $apiTokenRepo,
            em: $em,
            appSecret: $this->appSecret,
            tokenLimiter: null,
            translator: $this->createTranslator(),
            auditService: null,
        );
        $this->setContainer($controller, $owner);

        $request = Request::create(
            "/admin-api/projects/{$this->projectUuid}/api-keys",
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Overflow Token', 'abilities' => ['read']])
        );

        $response = $controller->create($this->projectUuid, $request);
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('MAX_KEYS_REACHED', $data['code']);
    }

    public function testApiKeyControllerMultiTenantIsolation(): void
    {
        $owner = new User();
        $owner->email = 'admin@example.com';
        $project = $this->createProject($owner);

        $nonMember = new User();
        $nonMember->email = 'intruder@example.com';

        $projRepo = $this->createMock(ProjectRepository::class);
        $projRepo->method('findOneBy')->willReturn($project);

        $apiTokenRepo = $this->createMock(ApiTokenRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $controller = new ApiKeyController(
            projectRepository: $projRepo,
            apiTokenRepository: $apiTokenRepo,
            em: $em,
            appSecret: $this->appSecret,
            tokenLimiter: null,
            translator: $this->createTranslator(),
            auditService: null,
        );
        $this->setContainer($controller, $nonMember);

        // Intruder trying to list tokens
        $request = Request::create("/admin-api/projects/{$this->projectUuid}/api-keys", 'GET');
        $response = $controller->index($this->projectUuid, $request);
        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('UNAUTHORIZED', $data['code']);
    }

    public function testApiKeyControllerSuccessfulLifecycleWithAudit(): void
    {
        $owner = new User();
        $owner->email = 'admin@example.com';
        $project = $this->createProject($owner);

        $projRepo = $this->createMock(ProjectRepository::class);
        $projRepo->method('findOneBy')->willReturn($project);

        $apiTokenRepo = $this->createMock(ApiTokenRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $audit = $this->createMock(AuditService::class);

        $auditCalls = [];
        $audit->method('log')->willReturnCallback(function (string $toolName, mixed $proj, array $input, mixed $output, string $status) use (&$auditCalls) {
            $auditCalls[] = ['tool' => $toolName, 'status' => $status];
            return new \App\Entity\AuditLog();
        });

        $controller = new ApiKeyController(
            projectRepository: $projRepo,
            apiTokenRepository: $apiTokenRepo,
            em: $em,
            appSecret: $this->appSecret,
            tokenLimiter: null,
            translator: $this->createTranslator(),
            auditService: $audit,
        );
        $this->setContainer($controller, $owner);

        // 1. Create
        $createReq = Request::create(
            "/admin-api/projects/{$this->projectUuid}/api-keys",
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Frontend Integration Key', 'abilities' => ['read', 'create']])
        );
        $createRes = $controller->create($this->projectUuid, $createReq);
        $this->assertSame(201, $createRes->getStatusCode());
        $createData = json_decode((string) $createRes->getContent(), true);
        $this->assertArrayHasKey('token', $createData);
        $this->assertSame('Frontend Integration Key', $createData['name']);
        $this->assertContains('read', $createData['abilities']);

        // 2. Show
        $existingToken = new ApiToken();
        $existingToken->id = 88;
        $existingToken->name = 'Frontend Integration Key';
        $existingToken->project = $project;
        $existingToken->abilities = ['read', 'create'];

        $apiTokenRepo->method('findOneBy')->willReturn($existingToken);

        $showReq = Request::create("/admin-api/projects/{$this->projectUuid}/api-keys/88", 'GET');
        $showRes = $controller->show($this->projectUuid, 88, $showReq);
        $this->assertSame(200, $showRes->getStatusCode());
        $showData = json_decode((string) $showRes->getContent(), true);
        $this->assertSame(88, $showData['id']);
        $this->assertArrayNotHasKey('token', $showData);
        $this->assertArrayNotHasKey('tokenHash', $showData);

        // 3. Update
        $updateReq = Request::create(
            "/admin-api/projects/{$this->projectUuid}/api-keys/88",
            'PATCH',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Renamed Frontend Key', 'abilities' => ['read']])
        );
        $updateRes = $controller->update($this->projectUuid, 88, $updateReq);
        $this->assertSame(200, $updateRes->getStatusCode());
        $updateData = json_decode((string) $updateRes->getContent(), true);
        $this->assertSame('Renamed Frontend Key', $updateData['name']);

        // 4. Delete
        $deleteReq = Request::create("/admin-api/projects/{$this->projectUuid}/api-keys/88", 'DELETE');
        $deleteRes = $controller->delete($this->projectUuid, 88, $deleteReq);
        $this->assertSame(204, $deleteRes->getStatusCode());

        // Verify audit trail
        $toolNames = array_column($auditCalls, 'tool');
        $this->assertContains('api_key_create', $toolNames);
        $this->assertContains('api_key_update', $toolNames);
        $this->assertContains('api_key_delete', $toolNames);
    }

    // ─── 2. TokenController (PAT) Security Hardening Tests ───

    public function testTokenControllerRejectsNonJsonContentType(): void
    {
        $repo = $this->createMock(PersonalAccessTokenRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $audit = $this->createMock(AuditService::class);

        $user = new User();
        $user->email = 'pat_owner@example.com';

        $controller = new TokenController(
            repo: $repo,
            em: $em,
            appSecret: $this->appSecret,
            tokenLimiter: null,
            translator: $this->createTranslator(),
            auditService: $audit,
        );
        $this->setContainer($controller, $user);

        // Request with text/plain
        $req = Request::create(
            '/admin-api/tokens',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            'name=plain-pat'
        );

        $res = $controller->create($req);
        $this->assertSame(415, $res->getStatusCode());
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $res->headers->get('X-Frame-Options'));
    }

    public function testTokenControllerRejectsOversizedPayload(): void
    {
        $repo = $this->createMock(PersonalAccessTokenRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $user = new User();
        $user->email = 'pat_owner@example.com';

        $controller = new TokenController(
            repo: $repo,
            em: $em,
            appSecret: $this->appSecret,
            tokenLimiter: null,
            translator: $this->createTranslator(),
            auditService: null,
        );
        $this->setContainer($controller, $user);

        $hugePayload = json_encode(['name' => str_repeat('X', 33000)]);
        $req = Request::create(
            '/admin-api/tokens',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $hugePayload
        );

        $res = $controller->create($req);
        $this->assertSame(413, $res->getStatusCode());
    }

    // ─── 3. PersonalAccessTokenController Hardening ───

    public function testPersonalAccessTokenControllerRejectsNonJsonContentType(): void
    {
        $repo = $this->createMock(PersonalAccessTokenRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $user = new User();
        $user->email = 'dashboard_user@example.com';

        $controller = new PersonalAccessTokenController(
            repo: $repo,
            em: $em,
            appSecret: $this->appSecret,
            tokenLimiter: null,
            translator: $this->createTranslator(),
            auditService: null,
        );
        $this->setContainer($controller, $user);

        $req = Request::create(
            '/api/settings/personal-access-tokens',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/html'],
            '<h1>bad</h1>'
        );

        $res = $controller->create($req);
        $this->assertSame(415, $res->getStatusCode());
    }

    // ─── 4. ProjectSettingsController Token Hardening ───

    public function testProjectSettingsControllerRejectsNonJsonOnTokenCreation(): void
    {
        $owner = new User();
        $owner->email = 'proj_admin@example.com';
        $project = $this->createProject($owner);

        $projRepo = $this->createMock(ProjectRepository::class);
        $projRepo->method('findOneBy')->willReturn($project);

        $apiTokenRepo = $this->createMock(ApiTokenRepository::class);
        $memberRepo = $this->createMock(ProjectMemberRepository::class);
        $mailerSettingsRepo = $this->createMock(ProjectMailerSettingsRepository::class);
        $mailerService = $this->createMock(ProjectMailerService::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $tokenChecker = $this->createMock(ApiTokenChecker::class);

        $controller = new ProjectSettingsController(
            projectRepository: $projRepo,
            apiTokenRepository: $apiTokenRepo,
            memberRepo: $memberRepo,
            mailerSettingsRepo: $mailerSettingsRepo,
            mailerService: $mailerService,
            em: $em,
            tokenChecker: $tokenChecker,
            logger: new NullLogger(),
            appSecret: $this->appSecret,
            tokenLimiter: null,
            mailerTestLimiter: null,
            translator: $this->createTranslator(),
            auditService: null,
        );
        $this->setContainer($controller, $owner);

        $req = Request::create(
            "/api/projects/{$this->projectUuid}/settings/tokens",
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            'raw_text_data'
        );

        $res = $controller->createToken($this->projectUuid, $req);
        $this->assertSame(415, $res->getStatusCode());
    }
}
