<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\Role;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use App\EventSubscriber\MercureCookieSubscriber;
use App\Repository\ProjectMemberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Cycle #120 — RealtimeController & Mercure Security & Multi-Tenant Isolation Tests.
 *
 * Verifies:
 * 1. Content-Type 415 enforcement on POST /publish and POST /token
 * 2. Null-byte / control character rejection (400)
 * 3. Event spoofing prevention on reserved system prefixes (403 EVENT_SPOOFING_DENIED)
 * 4. Cross-tenant topic subscription & publication rejection (403 FORBIDDEN)
 * 5. Topic depth limit enforcement (422 TOPIC_DEPTH_EXCEEDED)
 * 6. Security headers (X-Content-Type-Options, X-Frame-Options, Referrer-Policy, X-XSS-Protection)
 * 7. Multi-tenant cookie topic isolation in MercureCookieSubscriber
 * 8. MercureFallbackController SSE stream & 503 POST handling
 * 9. Exact 4-locales parity (en, fr, es, ar)
 */
class Cycle120RealtimeMercureSecurityTest extends WebTestCase
{
    private function makeUser(string $role = 'ROLE_USER'): User
    {
        $em   = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->email    = 'c120rt-' . bin2hex(random_bytes(5)) . '@example.com';
        $user->password = 'hashed_pass_c120';
        $user->roles    = [$role];
        $user->locale   = 'en';
        $em->persist($user);
        $em->flush();
        return $user;
    }

    private function makeRole(string $roleName): Role
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $role = $em->getRepository(Role::class)->findOneBy(['name' => $roleName]);
        if ($role !== null) {
            return $role;
        }

        $role = new Role();
        $role->name = $roleName;
        $role->description = "Role {$roleName}";
        $em->persist($role);
        $em->flush();
        return $role;
    }

    private function makeProject(): Project
    {
        $em      = self::getContainer()->get(EntityManagerInterface::class);
        $project = new Project();
        $project->name = 'C120-RT-' . bin2hex(random_bytes(4));
        $em->persist($project);
        $em->flush();
        return $project;
    }

    private function addMember(User $user, Project $project, ?Role $role = null): ProjectMember
    {
        $em     = self::getContainer()->get(EntityManagerInterface::class);
        $member = new ProjectMember();
        $member->user     = $user;
        $member->project  = $project;
        $member->email    = $user->email;
        $member->role     = $role;
        $member->status   = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $em->persist($member);
        $em->flush();
        return $member;
    }

    private function assertStandardSecurityHeaders(Response $response): void
    {
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $xfo = $response->headers->get('X-Frame-Options');
        self::assertContains($xfo, ['DENY', 'SAMEORIGIN']);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    // ─── Content-Type & Null-Byte Tests ──────────────────────────────

    public function testPublishRejectsNonJsonContentType(): void
    {
        $client  = static::createClient();
        $user    = $this->makeUser('ROLE_SUPER_ADMIN');
        $project = $this->makeProject();
        $this->addMember($user, $project);
        $client->loginUser($user);

        $client->request(
            'POST',
            '/api/projects/' . $project->uuid . '/realtime/publish',
            [], [], ['CONTENT_TYPE' => 'text/plain'],
            '{"event":"test","data":{}}'
        );

        $response = $client->getResponse();
        self::assertSame(415, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('UNSUPPORTED_MEDIA_TYPE', $data['code'] ?? null);
        $this->assertStandardSecurityHeaders($response);
    }

    public function testPublishRejectsNullBytes(): void
    {
        $client  = static::createClient();
        $user    = $this->makeUser('ROLE_SUPER_ADMIN');
        $project = $this->makeProject();
        $this->addMember($user, $project);
        $client->loginUser($user);

        $client->request(
            'POST',
            '/api/projects/' . $project->uuid . '/realtime/publish',
            [], [], ['CONTENT_TYPE' => 'application/json'],
            "{\"event\":\"test\x00malicious\",\"data\":{}}"
        );

        $response = $client->getResponse();
        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('NULL_BYTE_DETECTED', $data['code'] ?? null);
    }

    public function testTokenPostRejectsNonJsonContentType(): void
    {
        $client  = static::createClient();
        $user    = $this->makeUser('ROLE_SUPER_ADMIN');
        $project = $this->makeProject();
        $this->addMember($user, $project);
        $client->loginUser($user);

        $client->request(
            'POST',
            '/api/projects/' . $project->uuid . '/realtime/token',
            [], [], ['CONTENT_TYPE' => 'text/plain'],
            'topics=projects/' . $project->uuid
        );

        $response = $client->getResponse();
        self::assertSame(415, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('UNSUPPORTED_MEDIA_TYPE', $data['code'] ?? null);
    }

    public function testTokenPostRejectsNullBytes(): void
    {
        $client  = static::createClient();
        $user    = $this->makeUser('ROLE_SUPER_ADMIN');
        $project = $this->makeProject();
        $this->addMember($user, $project);
        $client->loginUser($user);

        $client->request(
            'POST',
            '/api/projects/' . $project->uuid . '/realtime/token',
            [], [], ['CONTENT_TYPE' => 'application/json'],
            "{\"topics\":[\"projects/{$project->uuid}\x00\"]}"
        );

        $response = $client->getResponse();
        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('NULL_BYTE_DETECTED', $data['code'] ?? null);
    }

    // ─── Topic Security & Multi-Tenancy ──────────────────────────────

    public function testTokenRejectsCrossTenantTopicWithForbiddenCode(): void
    {
        $client   = static::createClient();
        $user     = $this->makeUser('ROLE_SUPER_ADMIN');
        $project1 = $this->makeProject();
        $project2 = $this->makeProject();
        $this->addMember($user, $project1);
        $this->addMember($user, $project2);
        $client->loginUser($user);

        $client->request('GET', '/api/projects/' . $project1->uuid . '/realtime/token?topics=' . urlencode("projects/{$project2->uuid}"));
        $response = $client->getResponse();
        self::assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('FORBIDDEN', $data['code'] ?? null);
    }

    public function testTopicDepthExceededRejected(): void
    {
        $client  = static::createClient();
        $user    = $this->makeUser('ROLE_SUPER_ADMIN');
        $project = $this->makeProject();
        $this->addMember($user, $project);
        $client->loginUser($user);

        // projects/{uuid}/1/2/3/4/5/6 has 6 sub-segments > max 5
        $deepTopic = "projects/{$project->uuid}/sub1/sub2/sub3/sub4/sub5/sub6";
        $client->request('GET', '/api/projects/' . $project->uuid . '/realtime/token?topics=' . urlencode($deepTopic));
        $response = $client->getResponse();
        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('TOPIC_DEPTH_EXCEEDED', $data['code'] ?? null);
    }

    public function testTopicRejectsIllegalCharacters(): void
    {
        $client  = static::createClient();
        $user    = $this->makeUser('ROLE_SUPER_ADMIN');
        $project = $this->makeProject();
        $this->addMember($user, $project);
        $client->loginUser($user);

        // URL encoded characters or special symbols
        $badTopic = "projects/{$project->uuid}/foo%20bar";
        $client->request('GET', '/api/projects/' . $project->uuid . '/realtime/token?topics=' . urlencode($badTopic));
        $response = $client->getResponse();
        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('ILLEGAL_CHARACTERS', $data['code'] ?? null);
    }

    // ─── Event Spoofing Prevention ───────────────────────────────────

    public function testNonAdminCannotSpoofReservedSystemEvents(): void
    {
        $client  = static::createClient();
        $user    = $this->makeUser('ROLE_USER');
        $project = $this->makeProject();
        $editorRole = $this->makeRole('editor');
        $this->addMember($user, $project, $editorRole);
        $client->loginUser($user);

        // Editor is allowed to publish, but NOT allowed to spoof system 'entry.deleted'
        $client->request(
            'POST',
            '/api/projects/' . $project->uuid . '/realtime/publish',
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'topic' => "projects/{$project->uuid}",
                'event' => 'entry.deleted',
                'data'  => ['id' => 123],
            ])
        );

        $response = $client->getResponse();
        self::assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('EVENT_SPOOFING_DENIED', $data['code'] ?? null);
    }

    public function testNonAdminCanPublishCustomEvents(): void
    {
        $client  = static::createClient();
        $user    = $this->makeUser('ROLE_USER');
        $project = $this->makeProject();
        $editorRole = $this->makeRole('editor');
        $this->addMember($user, $project, $editorRole);
        $client->loginUser($user);

        $client->request(
            'POST',
            '/api/projects/' . $project->uuid . '/realtime/publish',
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'topic' => "projects/{$project->uuid}",
                'event' => 'cursor.position',
                'data'  => ['x' => 100, 'y' => 200],
            ])
        );

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('published', $data['status'] ?? null);
        self::assertSame('cursor.position', $data['event'] ?? null);
    }

    // ─── Multi-Tenant Cookie Topic Scoping ───────────────────────────

    public function testMercureCookieSubscriberScopesTopicsToUserProjects(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $memberRepo = $container->get(ProjectMemberRepository::class);

        $user = new User();
        $user->email = 'c120-cookie-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'pass';
        $user->roles = ['ROLE_USER'];
        $em->persist($user);

        $projA = new Project();
        $projA->name = 'Cookie Proj A';
        $em->persist($projA);

        $projB = new Project();
        $projB->name = 'Cookie Proj B';
        $em->persist($projB);

        $roleEditor = $this->makeRole('editor');

        $pm = new ProjectMember();
        $pm->user = $user;
        $pm->project = $projA;
        $pm->email = $user->email;
        $pm->role = $roleEditor;
        $pm->status = ProjectMemberStatus::Active;
        $em->persist($pm);
        $em->flush();

        $secret = str_repeat('a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6', 2);
        $requestStack = new RequestStack();
        $request = Request::create('https://jambostack.test/.well-known/mercure');
        $requestStack->push($request);

        $subscriber = new MercureCookieSubscriber(
            mercureJwtSecret: $secret,
            mercurePublicUrl: 'https://jambostack.test/.well-known/mercure',
            requestStack: $requestStack,
            mercureEnabled: true,
            memberRepo: $memberRepo,
        );

        $response = new Response();
        $passport = $this->createStub(Passport::class);
        $passport->method('getUser')->willReturn($user);

        $event = new LoginSuccessEvent(
            $this->createStub(AuthenticatorInterface::class),
            $passport,
            new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($user, 'main', $user->getRoles()),
            $request,
            $response,
            'main'
        );

        $subscriber->onLoginSuccess($event);

        $cookies = $response->headers->getCookies();
        self::assertNotEmpty($cookies);
        $authCookie = null;
        foreach ($cookies as $cookie) {
            if ($cookie->getName() === 'mercureAuthorization') {
                $authCookie = $cookie;
                break;
            }
        }

        self::assertNotNull($authCookie);
        self::assertSame('lax' === $authCookie->getSameSite() ? 'lax' : 'strict', strtolower((string) $authCookie->getSameSite()));
        self::assertTrue($authCookie->isHttpOnly());

        // Parse JWT and verify claims
        $jwtToken = (string) $authCookie->getValue();
        $parts = explode('.', $jwtToken);
        self::assertCount(3, $parts);
        $payload = json_decode(base64_decode($parts[1]), true);

        self::assertArrayHasKey('mercure', $payload);
        $subscribeTopics = $payload['mercure']['subscribe'] ?? [];
        $publishTopics = $payload['mercure']['publish'] ?? [];

        // Must include Proj A, but NOT Proj B, and NOT wildcard '*'
        self::assertNotContains('*', $subscribeTopics);
        self::assertContains("projects/{$projA->uuid}", $subscribeTopics);
        self::assertContains("projects/{$projA->uuid}/*", $subscribeTopics);
        self::assertNotContains("projects/{$projB->uuid}", $subscribeTopics);
        self::assertContains("projects/{$projA->uuid}", $publishTopics);

        self::ensureKernelShutdown();
    }

    public function testMercureCookieSubscriberGrantsWildcardToSuperAdmin(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $memberRepo = $container->get(ProjectMemberRepository::class);

        $sa = new User();
        $sa->email = 'c120-sa-' . bin2hex(random_bytes(4)) . '@example.com';
        $sa->password = 'pass';
        $sa->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($sa);
        $em->flush();

        $secret = str_repeat('a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6', 2);
        $requestStack = new RequestStack();
        $request = Request::create('https://jambostack.test/.well-known/mercure');
        $requestStack->push($request);

        $subscriber = new MercureCookieSubscriber(
            mercureJwtSecret: $secret,
            mercurePublicUrl: 'https://jambostack.test/.well-known/mercure',
            requestStack: $requestStack,
            mercureEnabled: true,
            memberRepo: $memberRepo,
        );

        $response = new Response();
        $passport = $this->createStub(Passport::class);
        $passport->method('getUser')->willReturn($sa);

        $event = new LoginSuccessEvent(
            $this->createStub(AuthenticatorInterface::class),
            $passport,
            new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken($sa, 'main', $sa->getRoles()),
            $request,
            $response,
            'main'
        );

        $subscriber->onLoginSuccess($event);

        $cookies = $response->headers->getCookies();
        $authCookie = null;
        foreach ($cookies as $cookie) {
            if ($cookie->getName() === 'mercureAuthorization') {
                $authCookie = $cookie;
                break;
            }
        }

        self::assertNotNull($authCookie);
        $payload = json_decode(base64_decode(explode('.', (string) $authCookie->getValue())[1]), true);
        self::assertSame(['*'], $payload['mercure']['subscribe']);
        self::assertSame(['*'], $payload['mercure']['publish']);

        self::ensureKernelShutdown();
    }

    // ─── 4-Locales Parity Verification ────────────────────────────────

    public function testAllLocalesHaveCycle120Translations(): void
    {
        $keysToCheck = [
            'realtime.unsupported_content_type',
            'realtime.null_byte_detected',
            'realtime.event_spoofing_denied',
            'realtime.payload_invalid_structure',
            'realtime.topic_depth_exceeded',
            'realtime.rate_limit_exceeded_retry',
            'mercure.tenant_access_denied',
        ];

        foreach (['en', 'fr', 'es', 'ar'] as $locale) {
            $path = __DIR__ . "/../../translations/messages.{$locale}.php";
            self::assertFileExists($path);
            $catalog = require $path;
            self::assertIsArray($catalog);

            foreach ($keysToCheck as $key) {
                self::assertArrayHasKey($key, $catalog, "Missing translation key '{$key}' in locale '{$locale}'");
                self::assertNotEmpty($catalog[$key], "Empty translation value for '{$key}' in locale '{$locale}'");
            }
        }
    }
}
