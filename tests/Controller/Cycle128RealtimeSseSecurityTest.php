<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ApiToken;
use App\Entity\Collection;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\Role;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use App\Repository\CollectionRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Service\Mercure\MercureTopicSecurityService;
use App\Service\MercurePublisher;
use Doctrine\ORM\EntityManagerInterface;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cycle #128 — Realtime SSE & Mercure Fallback Multi-Tenant Hardening & Topic Cloisonnement Tests.
 *
 * Verifies:
 * 1. Passive eavesdropping protection on `/.well-known/mercure` (401 anonymous, 403 cross-tenant, 403 multi-tenant).
 * 2. Strict CORS origin validation on Mercure fallback (403 for untrusted origins, preflight headers for trusted).
 * 3. Native SSE streaming on `/api/projects/{uuid}/realtime/stream` and `/sse` (auth, headers, cross-tenant isolation).
 * 4. Cross-tenant topic filtering prevention on `/poll` and `/stream` (403 FORBIDDEN).
 * 5. Sensitive administrative topic protection (`/audit`, `/security`, `/system`, `/members`, `/settings`).
 * 6. Collection topic containment verification (404 COLLECTION_NOT_FOUND for non-existent collections).
 * 7. Path traversal and cross-tenant publish claim rejection in `MercureTopicSecurityService::verifyToken`.
 * 8. Collection-scoped event dispatch in `MercurePublisher`.
 * 9. Exact 4-locales parity (en, fr, es, ar).
 */
class Cycle128RealtimeSseSecurityTest extends WebTestCase
{
    private function makeUser(string $role = 'ROLE_USER'): User
    {
        $em   = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->email    = 'c128rt-' . bin2hex(random_bytes(5)) . '@example.com';
        $user->password = 'hashed_pass_c128';
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
        $project->name = 'C128-RT-' . bin2hex(random_bytes(4));
        $em->persist($project);
        $em->flush();
        return $project;
    }

    private function makeCollection(Project $project, string $slug): Collection
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $collection = new Collection();
        $collection->name = 'Collection ' . $slug;
        $collection->slug = $slug;
        $collection->project = $project;
        $em->persist($collection);
        $em->flush();
        return $collection;
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

    private function makeApiToken(Project $project, array $abilities = ['read']): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $token = new ApiToken();
        $token->name = 'Test Token ' . bin2hex(random_bytes(3));
        $token->project = $project;
        $plainSecret = bin2hex(random_bytes(24));
        $token->tokenHash = hash('sha256', $plainSecret);
        $token->abilities = $abilities;
        $em->persist($token);
        $em->flush();
        return ['token' => $token, 'secret' => $plainSecret];
    }

    private function createJwtToken(string $projectUuid, array $subscribeTopics, array $publishTopics = [], string $secret = 'mercure_test_jwt_secret_with_32_chars!'): string
    {
        $jwtConfig = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($secret),
        );
        $now = new \DateTimeImmutable();
        return $jwtConfig->builder()
            ->issuedAt($now)
            ->expiresAt($now->modify('+1 hour'))
            ->identifiedBy(bin2hex(random_bytes(16)))
            ->relatedTo('user-test')
            ->issuedBy('https://jambostack.test')
            ->withClaim('mercure', [
                'subscribe' => $subscribeTopics,
                'publish'   => $publishTopics,
            ])
            ->getToken($jwtConfig->signer(), $jwtConfig->signingKey())
            ->toString();
    }

    // ─── 1. MercureFallbackController Passive Eavesdropping Protection ───

    public function testMercureFallbackAnonymousRequestRejectedWith401(): void
    {
        $client  = static::createClient();
        $project = $this->makeProject();

        $client->request('GET', '/.well-known/mercure?topic=projects/' . $project->uuid);
        $response = $client->getResponse();

        self::assertSame(401, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('AUTH_REQUIRED', $data['code'] ?? null);
    }

    public function testMercureFallbackCrossTenantEavesdroppingForbidden(): void
    {
        $client   = static::createClient();
        $projectA = $this->makeProject();
        $projectB = $this->makeProject();

        $userA = $this->makeUser();
        $this->addMember($userA, $projectA);
        $client->loginUser($userA);

        // User from Project A tries to passively listen to Project B events
        $client->request('GET', '/.well-known/mercure?topic=projects/' . $projectB->uuid);
        $response = $client->getResponse();

        self::assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('ACCESS_DENIED', $data['code'] ?? null);
    }

    public function testMercureFallbackMultiProjectTopicsForbidden(): void
    {
        $client   = static::createClient();
        $projectA = $this->makeProject();
        $projectB = $this->makeProject();

        $user = $this->makeUser('ROLE_SUPER_ADMIN');
        $client->loginUser($user);

        // Attempting to subscribe to topics across multiple different projects simultaneously
        $client->request('GET', '/.well-known/mercure?topic=projects/' . $projectA->uuid . '&topic=projects/' . $projectB->uuid);
        $response = $client->getResponse();

        self::assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('FORBIDDEN', $data['code'] ?? null);
    }

    public function testMercureFallbackNullBytesInTopicRejected(): void
    {
        $client   = static::createClient();
        $projectA = $this->makeProject();

        $user = $this->makeUser();
        $this->addMember($user, $projectA);
        $client->loginUser($user);

        $client->request('GET', '/.well-known/mercure?topic=projects/' . $projectA->uuid . "%00injection");
        $response = $client->getResponse();

        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('NULL_BYTE_DETECTED', $data['code'] ?? null);
    }

    public function testMercureFallbackUntrustedCorsOriginRejected(): void
    {
        $client   = static::createClient();
        $projectA = $this->makeProject();

        $client->request('GET', '/.well-known/mercure?topic=projects/' . $projectA->uuid, [], [], [
            'HTTP_ORIGIN' => 'https://evil-untrusted-site.com',
        ]);
        $response = $client->getResponse();

        self::assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('CORS_ORIGIN_DENIED', $data['code'] ?? null);
    }

    public function testMercureFallbackOptionsPreflightWithTrustedOrigin(): void
    {
        $client = static::createClient();

        $client->request('OPTIONS', '/.well-known/mercure', [], [], [
            'HTTP_ORIGIN' => 'http://localhost',
        ]);
        $response = $client->getResponse();

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('http://localhost', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
        self::assertStringContainsString('GET', (string) $response->headers->get('Access-Control-Allow-Methods'));
    }

    public function testMercureFallbackAuthorizedMemberAllowed(): void
    {
        $client   = static::createClient();
        $projectA = $this->makeProject();
        $userA    = $this->makeUser();
        $this->addMember($userA, $projectA);
        $client->loginUser($userA);

        $client->request('GET', '/.well-known/mercure?topic=projects/' . $projectA->uuid);
        $response = $client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/event-stream', (string) $response->headers->get('Content-Type'));
    }

    public function testMercureFallbackWithTamperedJwtTokenRejected(): void
    {
        $client   = static::createClient();
        $projectA = $this->makeProject();

        // Create JWT signed with wrong secret
        $badJwt = $this->createJwtToken($projectA->uuid->toRfc4122(), ['projects/' . $projectA->uuid], [], 'wrong_secret_key_that_is_32_chars_long!');

        $client->request('GET', '/.well-known/mercure?topic=projects/' . $projectA->uuid, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $badJwt,
        ]);
        $response = $client->getResponse();

        self::assertSame(401, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('TOKEN_SIGNATURE_INVALID', $data['code'] ?? null);
    }

    // ─── 2. Native SSE Streaming on /realtime/stream & /realtime/sse ─────

    public function testNativeSseStreamRequiresAuthentication(): void
    {
        $client  = static::createClient();
        $project = $this->makeProject();

        $client->request('GET', '/api/projects/' . $project->uuid . '/realtime/stream');
        $response = $client->getResponse();

        self::assertSame(401, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('AUTH_REQUIRED', $data['code'] ?? null);
    }

    public function testNativeSseStreamNonMemberDenied(): void
    {
        $client   = static::createClient();
        $projectA = $this->makeProject();
        $projectB = $this->makeProject();

        $userB = $this->makeUser();
        $this->addMember($userB, $projectB);
        $client->loginUser($userB);

        $client->request('GET', '/api/projects/' . $projectA->uuid . '/realtime/stream');
        $response = $client->getResponse();

        self::assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('ACCESS_DENIED', $data['code'] ?? null);
    }

    public function testNativeSseStreamAuthorizedMemberReturnsEventStream(): void
    {
        $client   = static::createClient();
        $projectA = $this->makeProject();
        $userA    = $this->makeUser();
        $this->addMember($userA, $projectA);
        $client->loginUser($userA);

        $client->request('GET', '/api/projects/' . $projectA->uuid . '/realtime/stream');
        $response = $client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/event-stream', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('no-transform', (string) $response->headers->get('Cache-Control'));
        self::assertSame('no', (string) $response->headers->get('X-Accel-Buffering'));

        // Test alias /sse
        $client->request('GET', '/api/projects/' . $projectA->uuid . '/realtime/sse');
        $responseSse = $client->getResponse();
        self::assertSame(200, $responseSse->getStatusCode());
        self::assertStringContainsString('text/event-stream', (string) $responseSse->headers->get('Content-Type'));
    }

    public function testNativeSseCrossTenantTopicFilterRejected(): void
    {
        $client   = static::createClient();
        $projectA = $this->makeProject();
        $projectB = $this->makeProject();

        $userA = $this->makeUser();
        $this->addMember($userA, $projectA);
        $client->loginUser($userA);

        $client->request('GET', '/api/projects/' . $projectA->uuid . '/realtime/stream?topic=projects/' . $projectB->uuid);
        $response = $client->getResponse();

        self::assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('FORBIDDEN', $data['code'] ?? null);
    }

    public function testPollCrossTenantTopicFilterRejected(): void
    {
        $client   = static::createClient();
        $projectA = $this->makeProject();
        $projectB = $this->makeProject();

        $userA = $this->makeUser();
        $this->addMember($userA, $projectA);
        $client->loginUser($userA);

        $client->request('GET', '/api/projects/' . $projectA->uuid . '/realtime?topic=projects/' . $projectB->uuid);
        $response = $client->getResponse();

        self::assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('FORBIDDEN', $data['code'] ?? null);
    }

    public function testPollInvalidEventNameRejected(): void
    {
        $client   = static::createClient();
        $projectA = $this->makeProject();

        $userA = $this->makeUser();
        $this->addMember($userA, $projectA);
        $client->loginUser($userA);

        $client->request('GET', '/api/projects/' . $projectA->uuid . '/realtime?event=invalid%20name%20with%20spaces!');
        $response = $client->getResponse();

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('INVALID_EVENT_NAME', $data['code'] ?? null);
    }

    // ─── 3. Topic Security: Administrative Privileges & Collection Containment ──

    public function testSensitiveTopicRequiresAdminPrivileges(): void
    {
        $client   = static::createClient();
        $projectA = $this->makeProject();

        // 1. Regular viewer member requesting sensitive audit topic
        $viewerRole = $this->makeRole('viewer');
        $viewerUser = $this->makeUser();
        $this->addMember($viewerUser, $projectA, $viewerRole);
        $client->loginUser($viewerUser);

        $client->request('GET', '/api/projects/' . $projectA->uuid . '/realtime/token?topics=projects/' . $projectA->uuid . '/audit');
        $response = $client->getResponse();

        self::assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('FORBIDDEN', $data['code'] ?? null);

        // 2. Admin member requesting sensitive audit topic
        $adminRole = $this->makeRole('admin');
        $adminUser = $this->makeUser();
        $this->addMember($adminUser, $projectA, $adminRole);
        $client->loginUser($adminUser);

        $client->request('GET', '/api/projects/' . $projectA->uuid . '/realtime/token?topics=projects/' . $projectA->uuid . '/audit');
        $responseAdmin = $client->getResponse();
        self::assertSame(200, $responseAdmin->getStatusCode());
    }

    public function testCollectionTopicValidation(): void
    {
        $client   = static::createClient();
        $projectA = $this->makeProject();
        $this->makeCollection($projectA, 'articles');

        $userA = $this->makeUser();
        $this->addMember($userA, $projectA);
        $client->loginUser($userA);

        // Valid collection topic
        $client->request('GET', '/api/projects/' . $projectA->uuid . '/realtime/token?topics=projects/' . $projectA->uuid . '/collections/articles');
        $responseValid = $client->getResponse();
        self::assertSame(200, $responseValid->getStatusCode());

        // Non-existent collection topic
        $client->request('GET', '/api/projects/' . $projectA->uuid . '/realtime/token?topics=projects/' . $projectA->uuid . '/collections/unknown-col-xyz');
        $responseInvalid = $client->getResponse();
        self::assertSame(404, $responseInvalid->getStatusCode());
        $data = json_decode((string) $responseInvalid->getContent(), true);
        self::assertSame('COLLECTION_NOT_FOUND', $data['code'] ?? null);
    }

    // ─── 4. MercureTopicSecurityService::verifyToken Traversal & Publish Security ──

    public function testVerifyTokenRejectsPathTraversalAndCrossTenantPublish(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $memberRepo = $container->get(ProjectMemberRepository::class);
        $collectionRepo = $container->get(CollectionRepository::class);
        $projectRepo = $container->get(ProjectRepository::class);
        $securityService = new MercureTopicSecurityService($memberRepo, null, $collectionRepo, $projectRepo);

        $secret = 'test_secret_for_jwt_verification_32_bytes!';
        $projectA = '11111111-1111-1111-1111-111111111111';
        $projectB = '22222222-2222-2222-2222-222222222222';

        // 1. Path traversal in subscribe topic
        $traversalJwt = $this->createJwtToken($projectA, ["projects/{$projectA}/../../{$projectB}"], [], $secret);
        $resTraversal = $securityService->verifyToken($traversalJwt, $secret, $projectA);
        self::assertFalse($resTraversal['valid']);
        self::assertSame('ILLEGAL_CHARACTERS', $resTraversal['code'] ?? null);

        // 2. Cross-tenant publish claim in JWT
        $crossPublishJwt = $this->createJwtToken($projectA, ["projects/{$projectA}"], ["projects/{$projectB}"], $secret);
        $resPublish = $securityService->verifyToken($crossPublishJwt, $secret, $projectA);
        self::assertFalse($resPublish['valid']);
        self::assertSame('FORBIDDEN', $resPublish['code'] ?? null);

        // 3. Valid single-tenant JWT
        $validJwt = $this->createJwtToken($projectA, ["projects/{$projectA}"], ["projects/{$projectA}"], $secret);
        $resValid = $securityService->verifyToken($validJwt, $secret, $projectA);
        self::assertTrue($resValid['valid']);
    }

    // ─── 5. MercurePublisher Collection Topic Dispatch ───────────────────

    public function testMercurePublisherDispatchesToCollectionTopics(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $projectDir = (string) $container->getParameter('kernel.project_dir');
        $publisher = new MercurePublisher($projectDir, null);

        $projectUuid = '33333333-3333-3333-3333-333333333333';
        $fallbackFile = $projectDir . '/var/realtime/' . $projectUuid . '.jsonl';
        @unlink($fallbackFile);

        $publisher->contentChanged($projectUuid, 'created', ['uuid' => 'entry-123'], 'Test Article', 'articles');

        self::assertFileExists($fallbackFile);
        $lines = file($fallbackFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertNotEmpty($lines);
        $entryData = json_decode($lines[count($lines) - 1], true);

        self::assertSame('entry.created', $entryData['event'] ?? null);
        self::assertSame('articles', $entryData['data']['collection'] ?? null);
        self::assertSame("projects/{$projectUuid}/content", $entryData['topic'] ?? null);

        @unlink($fallbackFile);
    }

    // ─── 6. Strict 4-Language I18N Parity ─────────────────────────────────

    public function testAllFourLocalesHaveExactParityAndRequiredKeys(): void
    {
        $locales = ['en', 'fr', 'es', 'ar'];
        $counts  = [];

        $requiredKeys = [
            'realtime.sensitive_topic_denied',
            'realtime.collection_not_found',
            'mercure.cors_origin_denied',
            'mercure.multiple_projects_denied',
            'mercure.auth_required',
        ];

        foreach ($locales as $loc) {
            $path = __DIR__ . '/../../translations/messages.' . $loc . '.php';
            self::assertFileExists($path, "File missing: messages.{$loc}.php");
            $data = require $path;
            self::assertIsArray($data);
            $counts[$loc] = count($data);

            foreach ($requiredKeys as $rk) {
                self::assertArrayHasKey($rk, $data, "Locale '{$loc}' is missing key '{$rk}'");
                self::assertNotEmpty($data[$rk], "Key '{$rk}' is empty in locale '{$loc}'");
            }
        }

        self::assertSame($counts['en'], $counts['fr'], 'FR count != EN count');
        self::assertSame($counts['en'], $counts['es'], 'ES count != EN count');
        self::assertSame($counts['en'], $counts['ar'], 'AR count != EN count');
    }
}
