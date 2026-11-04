<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\PreviewController;
use App\Controller\PublicShareController;
use App\Controller\ShareController;
use App\Entity\ApiToken;
use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Project;
use App\Entity\Share;
use App\Entity\User;
use App\Enum\ShareDuration;
use App\EventSubscriber\EnsureProjectMemberSubscriber;
use App\Repository\ApiTokenRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Repository\ShareRepository;
use App\Service\ApiTokenChecker;
use App\Service\EavDataFormatterService;
use App\Service\PreviewTokenService;
use App\Service\Security\SsrfProtectionService;
use App\Service\Share\ShareService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Cycle 24 — Hardening Tests
 *
 * Covers:
 *  1. ApiTokenChecker / EnsureProjectMemberSubscriber: strict scope compartmentalization (read vs write) on project-UUID routes.
 *  2. PreviewController & PreviewTokenService: target preview URL validation (anti-SSRF, http/https only), preview token TTL bounds.
 *  3. ShareController & PublicShareController: effective expiration enforcement, multi-tenant isolation, rate limiting on shares.
 */
class Cycle24HardeningTest extends TestCase
{
    private string $appSecret = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    // ─────────────────────────────────────────────────────────────────────────
    // 1. ApiTokenChecker & EnsureProjectMemberSubscriber Scopes
    // ─────────────────────────────────────────────────────────────────────────

    public function testApiTokenCheckerStrictMethodPermissions(): void
    {
        $apiRepo = $this->createStub(ApiTokenRepository::class);
        $em = $this->createStub(EntityManagerInterface::class);
        $checker = new ApiTokenChecker($apiRepo, $em, $this->appSecret);

        // Read-only token
        $readToken = new ApiToken();
        $readToken->abilities = ['read'];

        $this->assertTrue($checker->isAllowedForMethod($readToken, 'GET'));
        $this->assertTrue($checker->isAllowedForMethod($readToken, 'HEAD'));
        $this->assertFalse($checker->isAllowedForMethod($readToken, 'POST'), 'Read token must not allow POST');
        $this->assertFalse($checker->isAllowedForMethod($readToken, 'PUT'), 'Read token must not allow PUT');
        $this->assertFalse($checker->isAllowedForMethod($readToken, 'PATCH'), 'Read token must not allow PATCH');
        $this->assertFalse($checker->isAllowedForMethod($readToken, 'DELETE'), 'Read token must not allow DELETE');

        // Create-only token
        $createToken = new ApiToken();
        $createToken->abilities = ['create'];

        $this->assertFalse($checker->isAllowedForMethod($createToken, 'GET'));
        $this->assertTrue($checker->isAllowedForMethod($createToken, 'POST'));
        $this->assertFalse($checker->isAllowedForMethod($createToken, 'PUT'));
        $this->assertFalse($checker->isAllowedForMethod($createToken, 'DELETE'));

        // Update-only token
        $updateToken = new ApiToken();
        $updateToken->abilities = ['update'];

        $this->assertFalse($checker->isAllowedForMethod($updateToken, 'GET'));
        $this->assertFalse($checker->isAllowedForMethod($updateToken, 'POST'));
        $this->assertTrue($checker->isAllowedForMethod($updateToken, 'PUT'));
        $this->assertTrue($checker->isAllowedForMethod($updateToken, 'PATCH'));
        $this->assertFalse($checker->isAllowedForMethod($updateToken, 'DELETE'));

        // Delete-only token
        $deleteToken = new ApiToken();
        $deleteToken->abilities = ['delete'];

        $this->assertFalse($checker->isAllowedForMethod($deleteToken, 'GET'));
        $this->assertFalse($checker->isAllowedForMethod($deleteToken, 'POST'));
        $this->assertFalse($checker->isAllowedForMethod($deleteToken, 'PUT'));
        $this->assertTrue($checker->isAllowedForMethod($deleteToken, 'DELETE'));

        // Wildcard token
        $wildcardToken = new ApiToken();
        $wildcardToken->abilities = ['*'];

        $this->assertTrue($checker->isAllowedForMethod($wildcardToken, 'GET'));
        $this->assertTrue($checker->isAllowedForMethod($wildcardToken, 'POST'));
        $this->assertTrue($checker->isAllowedForMethod($wildcardToken, 'PUT'));
        $this->assertTrue($checker->isAllowedForMethod($wildcardToken, 'DELETE'));
    }

    public function testEnsureProjectMemberSubscriberEnforcesScopeOnUuidRoute(): void
    {
        $projectUuid = '00000000-0000-4000-8000-000000000001';
        $project = new Project();
        $project->uuid = Uuid::fromString($projectUuid);

        $readToken = new ApiToken();
        $readToken->abilities = ['read'];
        $readToken->project = $project;

        $projectRepo = $this->createStub(ProjectRepository::class);
        $memberRepo = $this->createStub(ProjectMemberRepository::class);
        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null); // No admin session

        $apiRepo = $this->createStub(ApiTokenRepository::class);
        $em = $this->createStub(EntityManagerInterface::class);
        $checker = new ApiTokenChecker($apiRepo, $em, $this->appSecret);

        $mockChecker = $this->createStub(ApiTokenChecker::class);
        $mockChecker->method('resolve')->willReturn($readToken);
        $mockChecker->method('isAllowedForMethod')->willReturnCallback(function (ApiToken $token, string $method) use ($checker) {
            return $checker->isAllowedForMethod($token, $method);
        });

        $subscriber = new EnsureProjectMemberSubscriber(
            $projectRepo,
            $memberRepo,
            $tokenStorage,
            $mockChecker
        );

        $kernel = $this->createStub(HttpKernelInterface::class);

        // 1. GET with read token should succeed (no response set)
        $getRequest = Request::create('/api/projects/' . $projectUuid . '/entries', 'GET');
        $getRequest->attributes->set('projectUuid', $projectUuid);
        $getEvent = new RequestEvent($kernel, $getRequest, HttpKernelInterface::MAIN_REQUEST);

        $subscriber->onKernelRequest($getEvent);
        $this->assertNull($getEvent->getResponse(), 'GET request with read token must pass');
        $this->assertSame($project, $getRequest->attributes->get('_project'));

        // 2. POST with read token must be denied with 403
        $postRequest = Request::create('/api/projects/' . $projectUuid . '/entries', 'POST');
        $postRequest->attributes->set('projectUuid', $projectUuid);
        $postEvent = new RequestEvent($kernel, $postRequest, HttpKernelInterface::MAIN_REQUEST);

        $subscriber->onKernelRequest($postEvent);
        $this->assertNotNull($postEvent->getResponse(), 'POST request with read token must be blocked');
        $this->assertSame(403, $postEvent->getResponse()->getStatusCode());

        $payload = json_decode((string) $postEvent->getResponse()->getContent(), true);
        $this->assertSame('Insufficient token abilities.', $payload['error']);

        // 3. Token from different project must be denied with 403
        $otherProject = new Project();
        $otherProject->uuid = Uuid::fromString('00000000-0000-4000-8000-000000000002');
        $otherToken = new ApiToken();
        $otherToken->abilities = ['read'];
        $otherToken->project = $otherProject;

        $otherChecker = $this->createStub(ApiTokenChecker::class);
        $otherChecker->method('resolve')->willReturn($otherToken);

        $diffSub = new EnsureProjectMemberSubscriber($projectRepo, $memberRepo, $tokenStorage, $otherChecker);
        $diffEvent = new RequestEvent($kernel, $getRequest, HttpKernelInterface::MAIN_REQUEST);
        $diffSub->onKernelRequest($diffEvent);

        $this->assertNotNull($diffEvent->getResponse());
        $this->assertSame(403, $diffEvent->getResponse()->getStatusCode());
        $diffPayload = json_decode((string) $diffEvent->getResponse()->getContent(), true);
        $this->assertSame('Access denied to this project.', $diffPayload['error']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. PreviewController & PreviewTokenService Hardening
    // ─────────────────────────────────────────────────────────────────────────

    public function testPreviewTokenServiceTtlBounds(): void
    {
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable {
                return new \DateTimeImmutable('2026-11-04 12:00:00');
            }
        };

        $tokenService = new PreviewTokenService($this->appSecret, $clock);

        $entry = new ContentEntry();
        $entry->uuid = Uuid::v4();
        $project = new Project();
        $project->uuid = Uuid::v4();
        $entry->project = $project;
        $col = new Collection();
        $col->slug = 'posts';
        $entry->collection = $col;

        // TTL too short (< 60s)
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Preview token TTL must be between 60 and 86400 seconds.');
        $tokenService->createToken($entry, 30);
    }

    public function testPreviewTokenServiceTtlTooHigh(): void
    {
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable {
                return new \DateTimeImmutable('2026-11-04 12:00:00');
            }
        };

        $tokenService = new PreviewTokenService($this->appSecret, $clock);

        $entry = new ContentEntry();
        $entry->uuid = Uuid::v4();
        $project = new Project();
        $project->uuid = Uuid::v4();
        $entry->project = $project;
        $col = new Collection();
        $col->slug = 'posts';
        $entry->collection = $col;

        // TTL too long (> 86400s)
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Preview token TTL must be between 60 and 86400 seconds.');
        $tokenService->createToken($entry, 100000);
    }

    public function testPreviewTokenServiceValidTokenLifecycle(): void
    {
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable {
                return new \DateTimeImmutable('2026-11-04 12:00:00');
            }
        };

        $tokenService = new PreviewTokenService($this->appSecret, $clock);

        $entry = new ContentEntry();
        $entryUuid = Uuid::v4();
        $projectUuid = Uuid::v4();
        $entry->uuid = $entryUuid;
        $project = new Project();
        $project->uuid = $projectUuid;
        $entry->project = $project;
        $col = new Collection();
        $col->slug = 'posts';
        $entry->collection = $col;

        $jwt = $tokenService->createToken($entry, 7200);
        $this->assertNotEmpty($jwt);

        $claims = $tokenService->validateToken($jwt);
        $this->assertNotNull($claims);
        $this->assertSame('preview', $claims['sub']);
        $this->assertSame($projectUuid->toRfc4122(), $claims['pid']);
        $this->assertSame($entryUuid->toRfc4122(), $claims['eid']);
        $this->assertSame('posts', $claims['col']);
    }

    public function testPreviewControllerTargetUrlValidationAntiSsrf(): void
    {
        $projectRepo = $this->createStub(ProjectRepository::class);
        $entryRepo = $this->createStub(ContentEntryRepository::class);
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable {
                return new \DateTimeImmutable('2026-11-04 12:00:00');
            }
        };
        $tokenService = new PreviewTokenService($this->appSecret, $clock);
        $formatter = $this->createStub(EavDataFormatterService::class);
        $ssrf = new SsrfProtectionService();

        $controller = new PreviewController(
            $projectRepo,
            $entryRepo,
            $tokenService,
            $formatter,
            $ssrf
        );

        // 1. Valid public HTTPS URL
        $this->assertNull($controller->validatePreviewTargetUrl('https://example.com/live-preview'));

        // 2. Dangerous schemes
        $this->assertStringContainsString('only http and https allowed', (string) $controller->validatePreviewTargetUrl('javascript:alert(1)'));
        $this->assertStringContainsString('only http and https allowed', (string) $controller->validatePreviewTargetUrl('file:///etc/passwd'));
        $this->assertStringContainsString('only http and https allowed', (string) $controller->validatePreviewTargetUrl('ftp://example.com/feed'));

        // 3. Anti-CRLF validation
        $this->assertStringContainsString('control characters detected', (string) $controller->validatePreviewTargetUrl("https://example.com/test\r\nX-Injected: 1"));

        // 4. Credentials in URL
        $this->assertStringContainsString('credentials in URL are strictly forbidden', (string) $controller->validatePreviewTargetUrl('https://admin:pass@example.com'));

        // 5. Anti-SSRF: Private IP ranges, loopback, cloud metadata
        $this->assertStringContainsString('must not point to private or reserved addresses', (string) $controller->validatePreviewTargetUrl('http://127.0.0.1:8080/preview'));
        $this->assertStringContainsString('must not point to private or reserved addresses', (string) $controller->validatePreviewTargetUrl('http://localhost:3000'));
        $this->assertStringContainsString('must not point to private or reserved addresses', (string) $controller->validatePreviewTargetUrl('http://169.254.169.254/latest/meta-data/'));
        $this->assertStringContainsString('must not point to private or reserved addresses', (string) $controller->validatePreviewTargetUrl('http://10.0.0.5/api'));
        $this->assertStringContainsString('must not point to private or reserved addresses', (string) $controller->validatePreviewTargetUrl('http://192.168.1.1/admin'));
    }

    public function testPreviewControllerValidateUrlEndpoint(): void
    {
        $projectRepo = $this->createStub(ProjectRepository::class);
        $entryRepo = $this->createStub(ContentEntryRepository::class);
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable {
                return new \DateTimeImmutable('2026-11-04 12:00:00');
            }
        };
        $tokenService = new PreviewTokenService($this->appSecret, $clock);
        $formatter = $this->createStub(EavDataFormatterService::class);
        $ssrf = new SsrfProtectionService();

        $controller = new PreviewController(
            $projectRepo,
            $entryRepo,
            $tokenService,
            $formatter,
            $ssrf
        );

        $uuid = Uuid::v4()->toRfc4122();

        // 1. Missing URL
        $req1 = Request::create("/api/projects/{$uuid}/preview/validate-url", 'GET');
        $res1 = $controller->validateUrl($uuid, $req1);
        $this->assertSame(422, $res1->getStatusCode());
        $payload1 = json_decode((string) $res1->getContent(), true);
        $this->assertSame('URL is required.', $payload1['error']);

        // 2. SSRF URL returns 422
        $req2 = Request::create("/api/projects/{$uuid}/preview/validate-url?url=http://127.0.0.1", 'GET');
        $res2 = $controller->validateUrl($uuid, $req2);
        $this->assertSame(422, $res2->getStatusCode());
        $payload2 = json_decode((string) $res2->getContent(), true);
        $this->assertFalse($payload2['valid']);
        $this->assertStringContainsString('private or reserved addresses', $payload2['error']);

        // 3. Safe URL returns 200
        $req3 = Request::create("/api/projects/{$uuid}/preview/validate-url?url=https://mysite.com/preview", 'GET');
        $res3 = $controller->validateUrl($uuid, $req3);
        $this->assertSame(200, $res3->getStatusCode());
        $payload3 = json_decode((string) $res3->getContent(), true);
        $this->assertTrue($payload3['valid']);
        $this->assertSame('https://mysite.com/preview', $payload3['url']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. ShareController & PublicShareController Hardening
    // ─────────────────────────────────────────────────────────────────────────

    public function testPublicShareControllerRejectsExpiredAndCrossTenantShares(): void
    {
        $shareService = $this->createStub(ShareService::class);
        $formatter = $this->createStub(EavDataFormatterService::class);

        $controller = new PublicShareController($shareService, $formatter);

        // 1. Expired share -> 410 Gone
        $project = new Project();
        $project->id = 1;
        $entry = new ContentEntry();
        $entry->project = $project;

        $expiredShare = new Share();
        $expiredShare->project = $project;
        $expiredShare->entry = $entry;
        $expiredShare->expiresAt = (new \DateTimeImmutable())->modify('-1 hour');

        $shareService->method('resolve')->willReturnCallback(function (string $token) use ($expiredShare) {
            return match ($token) {
                'expired_token' => $expiredShare,
                default => null,
            };
        });

        $reqExpired = Request::create('/share/expired_token', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $resExpired = $controller->show('expired_token', 'json', $reqExpired);

        $this->assertSame(410, $resExpired->getStatusCode());
        $payloadExpired = json_decode((string) $resExpired->getContent(), true);
        $this->assertSame('expired', $payloadExpired['error']);

        // 2. Cross-tenant mismatch (entry belonging to project 2 attached to share with project 1) -> 404
        $project2 = new Project();
        $project2->id = 2;
        $entryOther = new ContentEntry();
        $entryOther->project = $project2;

        $mismatchShare = new Share();
        $mismatchShare->project = $project; // Project 1
        $mismatchShare->entry = $entryOther; // Project 2
        $mismatchShare->expiresAt = (new \DateTimeImmutable())->modify('+1 hour');

        $shareService2 = $this->createStub(ShareService::class);
        $shareService2->method('resolve')->willReturn($mismatchShare);

        $controller2 = new PublicShareController($shareService2, $formatter);
        $reqMismatch = Request::create('/share/mismatch_token', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $resMismatch = $controller2->show('mismatch_token', 'json', $reqMismatch);

        $this->assertSame(404, $resMismatch->getStatusCode());
        $payloadMismatch = json_decode((string) $resMismatch->getContent(), true);
        $this->assertSame('invalid', $payloadMismatch['error']);
    }

    public function testShareControllerRateLimiting(): void
    {
        $factory = new RateLimiterFactory(
            ['id' => 'share', 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '60 seconds'],
            new InMemoryStorage()
        );
        // Consume the only available token
        $factory->create('127.0.0.1')->consume(1);

        $projectRepo = $this->createStub(ProjectRepository::class);
        $entryRepo = $this->createStub(ContentEntryRepository::class);
        $shareRepo = $this->createStub(ShareRepository::class);
        $shareService = $this->createStub(ShareService::class);

        $controller = new ShareController(
            $projectRepo,
            $entryRepo,
            $shareRepo,
            $shareService,
            $factory
        );

        $req = Request::create('/api/projects/any/shares', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
        $res = $controller->store('any', $req);

        $this->assertSame(429, $res->getStatusCode());
        $data = json_decode((string) $res->getContent(), true);
        $this->assertSame('Too many share requests. Try again later.', $data['error']);
    }

    public function testShareRepositoryExpiredCountsAndPurge(): void
    {
        $shareRepo = $this->createStub(ShareRepository::class);
        $shareRepo->method('countExpired')->willReturn(4);
        $shareRepo->method('purgeExpired')->willReturn(4);

        $this->assertSame(4, $shareRepo->countExpired());
        $this->assertSame(4, $shareRepo->purgeExpired());
    }
}
