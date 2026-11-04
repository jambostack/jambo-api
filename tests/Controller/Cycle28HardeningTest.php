<?php

namespace App\Tests\Controller;

use App\Controller\GraphQLController;
use App\Controller\PreviewController;
use App\Controller\PublicShareController;
use App\Entity\ContentEntry;
use App\Entity\Project;
use App\Entity\Share;
use App\GraphQL\SchemaGenerator;
use App\Repository\ContentEntryRepository;
use App\Repository\ProjectRepository;
use App\Service\EavDataFormatterService;
use App\Service\PreviewTokenService;
use App\Service\Security\SsrfProtectionService;
use App\Service\Share\ShareService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Uid\Uuid;

/**
 * Cycle 28 — Security Hardening Tests
 *
 * Covers:
 *  1. PreviewController:
 *     - SSRF target URL port filtering (blocks dangerous ports: 22, 3306, 6379, etc.).
 *     - Draft preview content endpoint returns Cache-Control: no-store, private.
 *  2. PublicShareController:
 *     - Shared entry JSON and HTML views return Cache-Control: no-store, private.
 *  3. GraphQLController:
 *     - GraphQL queries return Cache-Control: no-store, private.
 *     - Rate limiter enforcement returns 429 Too Many Requests when exhausted.
 *  4. Preview, GraphQL and Share rate limiting bindings.
 */
#[AllowMockObjectsWithoutExpectations]
class Cycle28HardeningTest extends TestCase
{
    private function createRejectingLimiterFactory(string $id = 'test_limiter'): RateLimiterFactory
    {
        $factory = new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
        $factory->create('127.0.0.1')->consume(1);
        return $factory;
    }

    private function createAcceptingLimiterFactory(string $id = 'test_limiter_accept'): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => 100, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
    }

    // =========================================================================
    // 1. PREVIEW CONTROLLER HARDENING
    // =========================================================================

    public function testPreviewTargetUrlBlocksDangerousPorts(): void
    {
        $ssrf = $this->createStub(SsrfProtectionService::class);
        $ssrf->method('isSafeHost')->willReturn(true);

        $controller = new PreviewController(
            $this->createStub(ProjectRepository::class),
            $this->createStub(ContentEntryRepository::class),
            $this->createStub(PreviewTokenService::class),
            $this->createStub(EavDataFormatterService::class),
            $ssrf,
            $this->createAcceptingLimiterFactory(),
        );

        // Dangereux ports d'infrastructure
        $errRedis = $controller->validatePreviewTargetUrl('http://public-preview.example.com:6379/keys');
        $this->assertNotNull($errRedis);
        $this->assertStringContainsString('port 6379 is not allowed for preview', $errRedis);

        $errSsh = $controller->validatePreviewTargetUrl('https://public-preview.example.com:22/shell');
        $this->assertNotNull($errSsh);
        $this->assertStringContainsString('port 22 is not allowed for preview', $errSsh);

        $errMysql = $controller->validatePreviewTargetUrl('http://public-preview.example.com:3306/db');
        $this->assertNotNull($errMysql);
        $this->assertStringContainsString('port 3306 is not allowed for preview', $errMysql);

        // Ports web légitimes autorisés
        $this->assertNull($controller->validatePreviewTargetUrl('https://public-preview.example.com/preview'));
        $this->assertNull($controller->validatePreviewTargetUrl('http://public-preview.example.com:8080/preview'));
        $this->assertNull($controller->validatePreviewTargetUrl('http://public-preview.example.com:3000/preview'));
        $this->assertNull($controller->validatePreviewTargetUrl('http://public-preview.example.com:5173/preview'));
    }

    public function testPreviewContentEndpointReturnsNoStoreCacheControl(): void
    {
        $projectUuid = (string) Uuid::v4();
        $entryUuid = (string) Uuid::v4();

        $project = new Project();
        $project->previewEnabled = true;

        $entry = new ContentEntry();

        $projectRepo = $this->createStub(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $entryRepo = $this->createStub(ContentEntryRepository::class);
        $entryRepo->method('findOneBy')->willReturn($entry);

        $tokenService = $this->createStub(PreviewTokenService::class);
        $tokenService->method('validateToken')->willReturn([
            'pid' => $projectUuid,
            'eid' => $entryUuid,
            'col' => 'articles',
        ]);

        $formatter = $this->createStub(EavDataFormatterService::class);
        $formatter->method('formatEntry')->willReturn(['id' => $entryUuid, 'title' => 'Draft Title']);

        $controller = new PreviewController(
            $projectRepo,
            $entryRepo,
            $tokenService,
            $formatter,
            $this->createStub(SsrfProtectionService::class),
            $this->createAcceptingLimiterFactory(),
        );

        $request = Request::create(
            sprintf('/api/projects/%s/preview/content/articles/%s', $projectUuid, $entryUuid),
            'GET',
            ['token' => 'valid-mock-token'],
        );

        $response = $controller->content($projectUuid, 'articles', $entryUuid, $request);

        $this->assertSame(200, $response->getStatusCode());
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertNotNull($cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
    }

    // =========================================================================
    // 2. PUBLIC SHARE CONTROLLER CACHE-CONTROL HARDENING
    // =========================================================================

    public function testPublicShareReturnsNoStoreCacheControl(): void
    {
        $project = new Project();

        $entry = new ContentEntry();
        $entry->project = $project;

        $share = new Share();
        $share->project = $project;
        $share->entry = $entry;

        $shareService = $this->createStub(ShareService::class);
        $shareService->method('resolve')->willReturn($share);

        $formatter = $this->createStub(EavDataFormatterService::class);
        $formatter->method('formatEntry')->willReturn(['title' => 'Confidential Shared Post', 'collection' => 'posts']);

        $controller = new PublicShareController(
            $shareService,
            $formatter,
            $this->createAcceptingLimiterFactory(),
        );

        $request = Request::create('/share/token123.json', 'GET');
        $response = $controller->show('token123', 'json', $request);

        $this->assertSame(200, $response->getStatusCode());
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertNotNull($cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
    }

    public function testPublicShareRateLimiting(): void
    {
        $controller = new PublicShareController(
            $this->createStub(ShareService::class),
            $this->createStub(EavDataFormatterService::class),
            $this->createRejectingLimiterFactory('share_test'),
        );

        $request = Request::create('/share/token123.json', 'GET');
        $response = $controller->show('token123', 'json', $request);

        $this->assertSame(429, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('too_many_requests', $data['error']);
    }

    // =========================================================================
    // 3. GRAPHQL CONTROLLER HARDENING
    // =========================================================================

    public function testGraphQLControllerRateLimiting(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $schemaGen = $this->createStub(SchemaGenerator::class);
        $rejectingLimiter = $this->createRejectingLimiterFactory('graphql_test');

        $controller = new GraphQLController($em, $schemaGen, $rejectingLimiter);

        $request = Request::create('/api/projects/test/graphql', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['query' => '{ __typename }']));

        $response = $controller->handle('test-project-uuid', $request);

        $this->assertSame(429, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertStringContainsString('Trop de requêtes GraphQL', $data['errors'][0]['message']);
    }

    public function testGraphQLControllerReturnsNoStoreCacheControl(): void
    {
        $projectUuid = (string) Uuid::v4();
        $project = new Project();
        $project->uuid = Uuid::fromString($projectUuid);

        $em = $this->createStub(EntityManagerInterface::class);
        $repo = $this->createStub(\Doctrine\ORM\EntityRepository::class);
        $repo->method('findOneBy')->willReturn($project);
        $em->method('getRepository')->willReturn($repo);

        $schemaGen = $this->createStub(SchemaGenerator::class);
        $schema = new \GraphQL\Type\Schema([
            'query' => new \GraphQL\Type\Definition\ObjectType([
                'name' => 'Query',
                'fields' => [
                    'hello' => [
                        'type' => \GraphQL\Type\Definition\Type::string(),
                        'resolve' => fn () => 'world',
                    ],
                ],
            ]),
        ]);
        $schemaGen->method('buildSchema')->willReturn($schema);

        $controller = new class($em, $schemaGen) extends GraphQLController {
            public function __construct($em, $schemaGen) {
                parent::__construct($em, $schemaGen, null);
            }
            protected function denyAccessUnlessGranted(mixed $attribute, mixed $subject = null, string $message = 'Access Denied.'): void {
                // Bypass for unit testing
            }
            protected function getParameter(string $name): \UnitEnum|array|string|int|float|bool|null {
                if ($name === 'kernel.environment') return 'test';
                if ($name === 'kernel.debug') return false;
                return null;
            }
        };

        $request = Request::create(
            sprintf('/api/projects/%s/graphql', $projectUuid),
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['query' => '{ hello }']),
        );
        $request->attributes->set('_project', $project);

        $response = $controller->handle($projectUuid, $request);

        $this->assertSame(200, $response->getStatusCode());
        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertNotNull($cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
    }
}
