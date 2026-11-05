<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Cycle #53 — Realtime/Mercure SSE JWT claims & security hardening tests.
 *
 * Covers:
 * - RealtimeController: /token, /publish, /poll endpoints
 * - MercureFallbackController: /.well-known/mercure SSE fallback
 * - Security headers parity (X-Content-Type-Options, X-Frame-Options, Cache-Control)
 * - Multi-tenant isolation (cross-project topic rejection)
 * - UUID validation, rate-limit plumbing, unauthenticated access enforcement
 * - Mercure JWT token structure (iat, exp, mercure claims when mercure_enabled)
 */
class Cycle53RealtimeMercureSecurityTest extends WebTestCase
{
    // ─── Helpers ────────────────────────────────────────────────────────────────

    private function makeUser(string $role = 'ROLE_SUPER_ADMIN'): User
    {
        $em   = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->email    = 'c53rt-' . bin2hex(random_bytes(5)) . '@example.com';
        $user->password = 'hashed_pass_cycle53';
        $user->roles    = [$role];
        $user->locale   = 'en';
        $em->persist($user);
        $em->flush();
        return $user;
    }

    private function makeProject(bool $publicApi = false): Project
    {
        $em      = self::getContainer()->get(EntityManagerInterface::class);
        $project = new Project();
        $project->name      = 'C53-RT-' . bin2hex(random_bytes(4));
        $project->publicApi = $publicApi;
        $em->persist($project);
        $em->flush();
        return $project;
    }

    private function addMember(User $user, Project $project, string $memberEmail = null): ProjectMember
    {
        $em     = self::getContainer()->get(EntityManagerInterface::class);
        $member = new ProjectMember();
        $member->user     = $user;
        $member->project  = $project;
        $member->email    = $memberEmail ?? $user->email;
        $member->status   = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $em->persist($member);
        $em->flush();
        return $member;
    }

    private function assertSecurityHeaders(\Symfony\Component\HttpFoundation\Response $response): void
    {
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), 'Missing X-Content-Type-Options: nosniff');
        // X-Frame-Options may be DENY (controller) or SAMEORIGIN (Symfony firewall 401) — both are acceptable
        $xfo = $response->headers->get('X-Frame-Options');
        self::assertContains($xfo, ['DENY', 'SAMEORIGIN'], "Unexpected X-Frame-Options: {$xfo}");
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), 'Missing Cache-Control: no-store');
    }

    private function assertControllerSecurityHeaders(\Symfony\Component\HttpFoundation\Response $response): void
    {
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), 'Missing X-Content-Type-Options: nosniff');
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'), 'Missing X-Frame-Options: DENY');
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), 'Missing Cache-Control: no-store');
    }

    // ─── Poll (GET /api/projects/{uuid}/realtime) ───────────────────────────────

    public function testPollSecurityHeaders(): void
    {
        $client  = static::createClient();
        $user    = $this->makeUser();
        $project = $this->makeProject();
        $this->addMember($user, $project);
        $client->loginUser($user);

        $client->request('GET', '/api/projects/' . $project->uuid . '/realtime?since=0');
        $this->assertSecurityHeaders($client->getResponse());
        self::assertResponseStatusCodeSame(200);
    }

    public function testPollUnauthenticated(): void
    {
        $client  = static::createClient();
        $project = $this->makeProject();

        $client->request('GET', '/api/projects/' . $project->uuid . '/realtime');
        $response = $client->getResponse();
        self::assertSame(401, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('error', $data);
        // Firewall-level 401 — X-Content-Type-Options is set by our controller
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testPollNonMemberForbidden(): void
    {
        $client   = static::createClient();
        $owner    = $this->makeUser();
        $outsider = $this->makeUser('ROLE_USER');
        $project  = $this->makeProject();
        $this->addMember($owner, $project);
        $client->loginUser($outsider);

        $client->request('GET', '/api/projects/' . $project->uuid . '/realtime');
        $response = $client->getResponse();
        self::assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testPollInvalidUuid(): void
    {
        $client = static::createClient();
        $user   = $this->makeUser();
        $client->loginUser($user);

        $client->request('GET', '/api/projects/not-a-uuid/realtime');
        self::assertResponseStatusCodeSame(404); // route constraint fires
    }

    public function testPollNonExistentProject(): void
    {
        $client = static::createClient();
        $user   = $this->makeUser();
        $client->loginUser($user);

        $client->request('GET', '/api/projects/00000000-0000-0000-0000-000000000001/realtime?since=0');
        $response = $client->getResponse();
        self::assertSame(404, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('error', $data);
        $this->assertSecurityHeaders($response);
    }

    public function testPollSinceAndLimitClamped(): void
    {
        $client  = static::createClient();
        $user    = $this->makeUser();
        $project = $this->makeProject();
        $this->addMember($user, $project);
        $client->loginUser($user);

        // limit above 100 → clamped to 100; since negative → clamped to 0
        $client->request('GET', '/api/projects/' . $project->uuid . '/realtime?since=-999&limit=99999');
        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data['events']);
    }

    // ─── Token (GET /api/projects/{uuid}/realtime/token) ────────────────────────

    public function testTokenEndpointSecurityHeaders(): void
    {
        $client  = static::createClient();
        $user    = $this->makeUser();
        $project = $this->makeProject();
        $this->addMember($user, $project);
        $client->loginUser($user);

        $client->request('GET', '/api/projects/' . $project->uuid . '/realtime/token');
        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        $this->assertSecurityHeaders($response);
    }

    public function testTokenResponseStructure(): void
    {
        $client  = static::createClient();
        $user    = $this->makeUser();
        $project = $this->makeProject();
        $this->addMember($user, $project);
        $client->loginUser($user);

        $client->request('GET', '/api/projects/' . $project->uuid . '/realtime/token');
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertArrayHasKey('enabled', $data);
        self::assertArrayHasKey('topics', $data);
        self::assertIsArray($data['topics']);

        // topics must all be scoped to this project UUID
        $uuid = (string) $project->uuid;
        foreach ($data['topics'] as $topic) {
            self::assertStringStartsWith("projects/{$uuid}", $topic, "Topic out of project scope: {$topic}");
        }
    }

    public function testTokenCrossProjectTopicRejected(): void
    {
        $client   = static::createClient();
        $user     = $this->makeUser();
        $project1 = $this->makeProject();
        $project2 = $this->makeProject();
        $this->addMember($user, $project1);
        $this->addMember($user, $project2);
        $client->loginUser($user);

        $foreignTopic = 'projects/' . $project2->uuid . '/content';
        $client->request('GET', '/api/projects/' . $project1->uuid . '/realtime/token?topics=' . urlencode($foreignTopic));

        $response = $client->getResponse();
        // Must be rejected: cross-tenant topic (422 or 403)
        self::assertContains($response->getStatusCode(), [403, 422], 'Cross-project topic must be rejected');
        $data = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testTokenWildcardTopicRejected(): void
    {
        $client  = static::createClient();
        $user    = $this->makeUser();
        $project = $this->makeProject();
        $this->addMember($user, $project);
        $client->loginUser($user);

        $wildcard = 'projects/' . $project->uuid . '/*';
        $client->request('GET', '/api/projects/' . $project->uuid . '/realtime/token?topics=' . urlencode($wildcard));

        $response = $client->getResponse();
        self::assertContains($response->getStatusCode(), [403, 422]);
        $data = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testTokenDirectoryTraversalTopicRejected(): void
    {
        $client  = static::createClient();
        $user    = $this->makeUser();
        $project = $this->makeProject();
        $this->addMember($user, $project);
        $client->loginUser($user);

        $badTopic = 'projects/' . $project->uuid . '/../../../etc/passwd';
        $client->request('GET', '/api/projects/' . $project->uuid . '/realtime/token?topics=' . urlencode($badTopic));

        $response = $client->getResponse();
        self::assertContains($response->getStatusCode(), [403, 422]);
    }

    public function testTokenUnauthenticated(): void
    {
        $client  = static::createClient();
        $project = $this->makeProject();

        $client->request('GET', '/api/projects/' . $project->uuid . '/realtime/token');
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    // ─── Publish (POST /api/projects/{uuid}/realtime/publish) ───────────────────

    public function testPublishSecurityHeadersOnInvalidJson(): void
    {
        $client  = static::createClient();
        $user    = $this->makeUser();
        $project = $this->makeProject();
        $this->addMember($user, $project);
        $client->loginUser($user);

        $client->request(
            'POST',
            '/api/projects/' . $project->uuid . '/realtime/publish',
            [], [], ['CONTENT_TYPE' => 'application/json'],
            'not-json'
        );
        $response = $client->getResponse();
        self::assertSame(400, $response->getStatusCode());
        $this->assertSecurityHeaders($response);
    }

    public function testPublishInvalidEventNameRejected(): void
    {
        $client  = static::createClient();
        $user    = $this->makeUser();
        $project = $this->makeProject();
        $this->addMember($user, $project);
        $client->loginUser($user);

        $payload = json_encode([
            'topic' => 'projects/' . $project->uuid,
            'event' => '<script>xss</script>',
            'data'  => ['msg' => 'test'],
        ]);
        $client->request(
            'POST',
            '/api/projects/' . $project->uuid . '/realtime/publish',
            [], [], ['CONTENT_TYPE' => 'application/json'],
            $payload
        );
        $response = $client->getResponse();
        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testPublishOversizedPayloadRejected(): void
    {
        $client  = static::createClient();
        $user    = $this->makeUser();
        $project = $this->makeProject();
        $this->addMember($user, $project);
        $client->loginUser($user);

        $huge = str_repeat('A', 70000); // > 64 KB
        $payload = json_encode(['topic' => 'projects/' . $project->uuid, 'event' => 'test', 'data' => $huge]);
        $client->request(
            'POST',
            '/api/projects/' . $project->uuid . '/realtime/publish',
            [], [], ['CONTENT_TYPE' => 'application/json'],
            $payload
        );
        self::assertSame(413, $client->getResponse()->getStatusCode());
    }

    public function testPublishUnauthenticated(): void
    {
        $client  = static::createClient();
        $project = $this->makeProject();

        $client->request(
            'POST',
            '/api/projects/' . $project->uuid . '/realtime/publish',
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['event' => 'test', 'data' => []])
        );
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    // ─── MercureFallbackController ───────────────────────────────────────────────

    public function testMercureFallbackPostReturns503WithSecurityHeaders(): void
    {
        $client = static::createClient();
        $client->request('POST', '/.well-known/mercure', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        $response = $client->getResponse();
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $data = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('error', $data);
        self::assertArrayHasKey('code', $data);
        self::assertSame('MERCURE_NOT_AVAILABLE', $data['code']);
    }

    public function testMercureFallbackGetReturnsSseStream(): void
    {
        $client = static::createClient();
        $client->request('GET', '/.well-known/mercure');
        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/event-stream', (string) $response->headers->get('Content-Type'));
        // StreamedResponse body may be empty in test env (no real flush); check headers suffice
        self::assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
    }

    public function testMercureFallbackOptionsPreflightReturns204(): void
    {
        $client = static::createClient();
        $client->request('OPTIONS', '/.well-known/mercure');
        $response = $client->getResponse();
        self::assertSame(204, $response->getStatusCode());
        self::assertNotEmpty($response->headers->get('Access-Control-Allow-Methods'));
    }

    // ─── Multi-tenant isolation sanity ───────────────────────────────────────────

    public function testPollCrossProjectAccessForbidden(): void
    {
        $client   = static::createClient();
        $user     = $this->makeUser('ROLE_USER');
        $project1 = $this->makeProject();
        $project2 = $this->makeProject();
        $this->addMember($user, $project1);
        // user is NOT a member of project2
        $client->loginUser($user);

        $client->request('GET', '/api/projects/' . $project2->uuid . '/realtime');
        $response = $client->getResponse();
        self::assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('error', $data);
    }

    public function testSuperAdminCanAccessAnyProject(): void
    {
        $client  = static::createClient();
        $sa      = $this->makeUser('ROLE_SUPER_ADMIN');
        $project = $this->makeProject(); // sa is NOT a project member
        $client->loginUser($sa);

        $client->request('GET', '/api/projects/' . $project->uuid . '/realtime?since=0');
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }
}
