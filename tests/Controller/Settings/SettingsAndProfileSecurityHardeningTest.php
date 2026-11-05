<?php

namespace App\Tests\Controller\Settings;

use App\Controller\Api\CaptchaController;
use App\Controller\InvitationController;
use App\Controller\MercureFallbackController;
use App\Controller\Settings\AdminApiOpenApiController;
use App\Controller\Settings\LocaleController;
use App\Controller\Settings\PasswordController;
use App\Controller\Settings\PersonalAccessTokenController;
use App\Controller\Settings\ProfileController;
use App\Entity\ApiToken;
use App\Entity\PersonalAccessToken;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use App\Repository\PersonalAccessTokenRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\UserRepository;
use App\Service\AuditService;
use App\Service\Security\PasswordPolicyValidator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class SettingsAndProfileSecurityHardeningTest extends TestCase
{
    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(function (string $id, array $params = [], ?string $domain = null) {
            $dict = require __DIR__ . '/../../../translations/messages.en.php';
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

    // ──────────────────────────────────────────────────────────────────────────
    // 1. ProfileController Tests
    // ──────────────────────────────────────────────────────────────────────────

    public function testProfileShowUnauthenticated(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $translator = $this->createTranslator();

        $controller = new class($userRepo, $em, null, null, $translator) extends ProfileController {
            public function getUser(): ?User { return null; }
        };
        $response = $controller->show(new Request());

        $this->assertSame(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Unauthenticated.', $data['error']);
        $this->assertSame('UNAUTHENTICATED', $data['code']);
    }

    public function testProfileUpdatePayloadTooLarge(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $translator = $this->createTranslator();

        $controller = new class($userRepo, $em, null, null, $translator) extends ProfileController {
            public User $mockUser;
            public function getUser(): ?User { return $this->mockUser; }
        };
        $user = new User();
        $user->name = 'Original Name';
        $user->email = 'test@example.com';
        $controller->mockUser = $user;

        $hugePayload = json_encode(['name' => str_repeat('A', 70000)]);
        $req = Request::create('/api/settings/profile', 'PATCH', [], [], [], ['CONTENT_TYPE' => 'application/json'], $hugePayload);

        $response = $controller->update($req);
        $this->assertSame(413, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Profile payload exceeds maximum size.', $data['error']);
    }

    public function testProfileUpdateValidatesNameAndEmail(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $translator = $this->createTranslator();

        $controller = new class($userRepo, $em, null, null, $translator) extends ProfileController {
            public User $mockUser;
            public function getUser(): ?User { return $this->mockUser; }
        };
        $user = new User();
        $user->name = 'Original';
        $user->email = 'test@example.com';
        $controller->mockUser = $user;

        // Name containing control characters
        $req = Request::create('/api/settings/profile', 'PATCH', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => "Bad\x00Name",
        ]));
        $res = $controller->update($req);
        $this->assertSame(422, $res->getStatusCode());
        $data = json_decode($res->getContent(), true);
        $this->assertSame('Name contains invalid control characters.', $data['errors']['name']);

        // Invalid email
        $req2 = Request::create('/api/settings/profile', 'PATCH', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => "not-an-email",
        ]));
        $res2 = $controller->update($req2);
        $this->assertSame(422, $res2->getStatusCode());
        $data2 = json_decode($res2->getContent(), true);
        $this->assertSame('Valid email address is required.', $data2['errors']['email']);

        // Duplicate email
        $existing = new User();
        $userRepo->method('findByEmail')->willReturn($existing);
        $req3 = Request::create('/api/settings/profile', 'PATCH', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email' => "existing@example.com",
        ]));
        $res3 = $controller->update($req3);
        $this->assertSame(422, $res3->getStatusCode());
        $data3 = json_decode($res3->getContent(), true);
        $this->assertSame('Email already taken.', $data3['errors']['email']);
    }

    public function testProfileDeleteRequiresPasswordWhenSet(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $translator = $this->createTranslator();
        $hasher = $this->createMock(UserPasswordHasherInterface::class);

        $controller = new class($userRepo, $em, null, $hasher, $translator) extends ProfileController {
            public User $mockUser;
            public function getUser(): ?User { return $this->mockUser; }
        };
        $user = new User();
        $user->password = 'hashed-pw';
        $controller->mockUser = $user;

        // Missing password in delete request
        $req = Request::create('/api/settings/profile', 'DELETE');
        $res = $controller->delete($req);
        $this->assertSame(422, $res->getStatusCode());
        $data = json_decode($res->getContent(), true);
        $this->assertSame('Current password is required to delete account.', $data['error']);
        $this->assertSame('PASSWORD_REQUIRED', $data['code']);

        // Invalid password
        $hasher->method('isPasswordValid')->willReturn(false);
        $req2 = Request::create('/api/settings/profile', 'DELETE', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'password' => 'wrong',
        ]));
        $res2 = $controller->delete($req2);
        $this->assertSame(422, $res2->getStatusCode());
        $data2 = json_decode($res2->getContent(), true);
        $this->assertSame('Current password is invalid.', $data2['error']);
        $this->assertSame('INVALID_PASSWORD', $data2['code']);
    }

    public function testProfileDeleteProtectsLastSuperAdmin(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $translator = $this->createTranslator();
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('isPasswordValid')->willReturn(true);

        $query = $this->createMock(Query::class);
        $query->method('setParameter')->willReturnSelf();
        $query->method('getSingleScalarResult')->willReturn(1); // Only 1 super admin remaining
        $em->method('createQuery')->willReturn($query);

        $controller = new class($userRepo, $em, null, $hasher, $translator) extends ProfileController {
            public User $mockUser;
            public function getUser(): ?User { return $this->mockUser; }
        };
        $user = new User();
        $user->roles = ['ROLE_SUPER_ADMIN'];
        $user->password = 'hashed';
        $controller->mockUser = $user;

        $req = Request::create('/api/settings/profile', 'DELETE', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'password' => 'secret123',
        ]));
        $res = $controller->delete($req);
        $this->assertSame(403, $res->getStatusCode());
        $data = json_decode($res->getContent(), true);
        $this->assertSame('Cannot delete account: at least one active super administrator must remain.', $data['error']);
        $this->assertSame('LAST_ADMIN_PROTECTION', $data['code']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 2. PasswordController Tests
    // ──────────────────────────────────────────────────────────────────────────

    public function testPasswordControllerRateLimitingAndValidation(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $translator = $this->createTranslator();
        $limiter = $this->createRateLimiter(1);
        // Pre-consume the only token available
        $limiter->create('admin@example.com')->consume(1);

        $controller = new class($em, $hasher, null, $limiter, $translator) extends PasswordController {
            public User $mockUser;
            public function getUser(): ?User { return $this->mockUser; }
        };
        $user = new User();
        $user->email = 'admin@example.com';
        $controller->mockUser = $user;

        $req = Request::create('/api/settings/password', 'PUT');
        $res = $controller->update($req);
        $this->assertSame(429, $res->getStatusCode());
        $data = json_decode($res->getContent(), true);
        $this->assertSame('Too many password update attempts. Please retry later.', $data['error']);
    }

    public function testPasswordControllerMismatch(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->method('isPasswordValid')->willReturnCallback(fn ($u, $p) => $p === 'old-pw');
        $translator = $this->createTranslator();
        $limiter = $this->createRateLimiter(100);

        $controller = new class($em, $hasher, null, $limiter, $translator) extends PasswordController {
            public User $mockUser;
            public function getUser(): ?User { return $this->mockUser; }
        };
        $user = new User();
        $user->password = 'old-pw';
        $user->email = 'admin@example.com';
        $controller->mockUser = $user;

        $req = Request::create('/api/settings/password', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'current_password' => 'old-pw',
            'password' => 'StrongP@ssw0rd!2026',
            'password_confirmation' => 'DifferentP@ssw0rd!',
        ]));

        $res = $controller->update($req);
        $this->assertSame(422, $res->getStatusCode());
        $data = json_decode($res->getContent(), true);
        $this->assertSame('Passwords do not match.', $data['errors']['password_confirmation']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 3. LocaleController Tests
    // ──────────────────────────────────────────────────────────────────────────

    public function testLocaleControllerRejectsInvalidLocale(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $translator = $this->createTranslator();
        $controller = new LocaleController($em, $translator);

        $req = Request::create('/settings/locale', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'locale' => 'de', // not in allowed locales (en, fr, es, ar)
        ]));

        $res = $controller->update($req);
        $this->assertSame(422, $res->getStatusCode());
        $data = json_decode($res->getContent(), true);
        $this->assertSame('Invalid locale.', $data['error']);
        $this->assertSame('INVALID_LOCALE', $data['code']);
    }

    public function testLocaleControllerSanitizesRefererHost(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $translator = $this->createTranslator();

        $controller = new class($em, $translator) extends LocaleController {
            public User $mockUser;
            public function getUser(): ?User { return $this->mockUser; }
        };
        $user = new User();
        $user->locale = 'en';
        $controller->mockUser = $user;

        // Malicious external referer
        $req = Request::create('https://jambostack.site/settings/locale', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_REFERER' => 'https://evil-phishing.com/steal-creds',
        ], json_encode(['locale' => 'fr']));

        $res = $controller->update($req);
        $this->assertTrue($res->isRedirection());
        $this->assertSame('/', $res->headers->get('Location'));
        $this->assertSame('fr', $user->locale);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 4. PersonalAccessTokenController Tests
    // ──────────────────────────────────────────────────────────────────────────

    public function testPersonalAccessTokenControllerMaxLimitAndValidation(): void
    {
        $repo = $this->createMock(PersonalAccessTokenRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $translator = $this->createTranslator();

        $controller = new class($repo, $em, 'secret', null, $translator) extends PersonalAccessTokenController {
            public User $mockUser;
            public function getUser(): ?User { return $this->mockUser; }
        };
        $user = new User();
        $user->email = 'dev@test.com';
        $controller->mockUser = $user;

        // 50 tokens limit
        $existing = array_fill(0, 50, new PersonalAccessToken());
        $repo->method('findByUser')->willReturn($existing);

        $req = Request::create('/api/settings/personal-access-tokens', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => '51st Token',
        ]));
        $res = $controller->create($req);
        $this->assertSame(422, $res->getStatusCode());
        $data = json_decode($res->getContent(), true);
        $this->assertSame('Maximum token limit reached (50 tokens max).', $data['errors']['name']);
        $this->assertSame('MAX_TOKENS_REACHED', $data['code']);
    }

    public function testPersonalAccessTokenInvalidScope(): void
    {
        $repo = $this->createMock(PersonalAccessTokenRepository::class);
        $repo->method('findByUser')->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $translator = $this->createTranslator();

        $controller = new class($repo, $em, 'secret', null, $translator) extends PersonalAccessTokenController {
            public User $mockUser;
            public function getUser(): ?User { return $this->mockUser; }
        };
        $user = new User();
        $user->email = 'dev@test.com';
        $controller->mockUser = $user;

        $req = Request::create('/api/settings/personal-access-tokens', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'CLI Token',
            'scopes' => ['admin:all', 'invalid:scope'],
        ]));
        $res = $controller->create($req);
        $this->assertSame(422, $res->getStatusCode());
        $data = json_decode($res->getContent(), true);
        $this->assertStringContainsString('Invalid scope "admin:all"', $data['errors']['scopes']);
        $this->assertSame('INVALID_SCOPE', $data['code']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 5. CaptchaController Tests
    // ──────────────────────────────────────────────────────────────────────────

    public function testCaptchaControllerProjectNotFound(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $repo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);
        $em->method('getRepository')->willReturn($repo);

        $cache = $this->createMock(CacheInterface::class);
        $limiter = $this->createRateLimiter(100);
        $translator = $this->createTranslator();

        $controller = new CaptchaController($em, $cache, $limiter, $translator);
        $res = $controller->captcha('00000000-0000-0000-0000-000000000000', new Request());

        $this->assertSame(404, $res->getStatusCode());
        $data = json_decode($res->getContent(), true);
        $this->assertSame('Project not found.', $data['error']);
        $this->assertSame('PROJECT_NOT_FOUND', $data['code']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 6. MercureFallbackController Tests
    // ──────────────────────────────────────────────────────────────────────────

    public function testMercureFallbackControllerOptionsAndPost(): void
    {
        $translator = $this->createTranslator();
        $controller = new MercureFallbackController(null, $translator);

        $optionsReq = Request::create('/.well-known/mercure', 'OPTIONS');
        $optionsRes = $controller->handle($optionsReq);
        $this->assertSame(204, $optionsRes->getStatusCode());
        $this->assertSame('*', $optionsRes->headers->get('Access-Control-Allow-Origin'));

        $postReq = Request::create('/.well-known/mercure', 'POST');
        $postRes = $controller->handle($postReq);
        $this->assertSame(503, $postRes->getStatusCode());
        $data = json_decode($postRes->getContent(), true);
        $this->assertSame('Mercure hub not available on this host.', $data['error']);
        $this->assertSame('MERCURE_NOT_AVAILABLE', $data['code']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 7. InvitationController Tests
    // ──────────────────────────────────────────────────────────────────────────

    public function testInvitationControllerRejectsInvalidTokenFormat(): void
    {
        $memberRepo = $this->createMock(ProjectMemberRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $translator = $this->createTranslator();

        $controller = new InvitationController($memberRepo, $em, $translator);
        $req = Request::create('/api/invitations/short/accept', 'POST');

        // Short token (< 8 chars)
        $res = $controller->accept('short', $req);
        $this->assertSame(404, $res->getStatusCode());
        $data = json_decode($res->getContent(), true);
        $this->assertSame('This invitation link is invalid.', $data['error']);
    }

    public function testInvitationControllerRequiresAuth(): void
    {
        $memberRepo = $this->createMock(ProjectMemberRepository::class);
        $member = new ProjectMember();
        $member->status = ProjectMemberStatus::Pending;
        $member->email = 'invited@example.com';
        $memberRepo->method('findOneBy')->willReturn($member);

        $em = $this->createMock(EntityManagerInterface::class);
        $translator = $this->createTranslator();

        $controller = new class($memberRepo, $em, $translator) extends InvitationController {
            public function getUser(): ?User { return null; }
        };

        $req = Request::create('/api/invitations/valid-invitation-token-12345/accept', 'POST', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $res = $controller->accept('valid-invitation-token-12345', $req);

        $this->assertSame(401, $res->getStatusCode());
        $data = json_decode($res->getContent(), true);
        $this->assertSame('Authentication required.', $data['error']);
    }
}
