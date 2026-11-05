<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\ImageTransformController;
use App\Controller\McpController;
use App\Controller\PreviewController;
use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Media;
use App\Entity\Project;
use App\Entity\User;
use App\Mcp\JamboApiMcpServer;
use App\Repository\ContentEntryRepository;
use App\Repository\ProjectRepository;
use App\Service\EavDataFormatterService;
use App\Service\ImageTransformService;
use App\Service\PreviewTokenService;
use App\Service\Security\SsrfProtectionService;
use App\Service\StorageDriverFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

class Cycle44HardeningTest extends TestCase
{
    private function createContainerWithUser(?User $user = null): Container
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

        $authChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturn(true);
        $container->set('security.authorization_checker', $authChecker);

        return $container;
    }

    private function createAcceptingLimiter(): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'test_preview', 'policy' => 'sliding_window', 'limit' => 100, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
    }

    // =========================================================================
    // 1. ImageTransformController Tests
    // =========================================================================

    public function testImageTransformRejectsInvalidUuidWith404(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $transform = $this->createMock(ImageTransformService::class);
        $controller = new ImageTransformController($em, $transform);
        $controller->setContainer($this->createContainerWithUser(null));

        $response = $controller->serve('invalid-uuid-format', new Request());
        $this->assertSame(404, $response->getStatusCode());

        $infoResponse = $controller->info('not-a-valid-uuid', new Request());
        $this->assertSame(404, $infoResponse->getStatusCode());
    }

    public function testImageTransformRejectsOutOfBoundParameters(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $mediaRepo = $this->createMock(EntityRepository::class);
        $transform = $this->createMock(ImageTransformService::class);

        $project = new Project();
        $project->publicApi = true;
        $project->uuid = Uuid::v4();

        $media = new Media();
        $media->project = $project;
        $media->uuid = Uuid::v4();
        $media->fileName = 'sample.jpg';
        $media->mimeType = 'image/jpeg';

        $mediaRepo->method('findOneBy')->willReturn($media);
        $em->method('getRepository')->with(Media::class)->willReturn($mediaRepo);

        $tempDir = sys_get_temp_dir() . '/jambo_cdn_test_' . bin2hex(random_bytes(4));
        @mkdir($tempDir . '/public/uploads/media/' . $project->uuid->toRfc4122(), 0755, true);
        file_put_contents($tempDir . '/public/uploads/media/' . $project->uuid->toRfc4122() . '/sample.jpg', 'fake-jpg-content');

        $controller = new ImageTransformController($em, $transform);
        $container = $this->createContainerWithUser(null);
        $container->set('parameter_bag', new class($tempDir) {
            public function __construct(private string $dir) {}
            public function get(string $name): string {
                return $name === 'kernel.project_dir' ? $this->dir : '';
            }
        });
        $controller->setContainer($container);

        // Width exceeds 4000
        $req1 = new Request(['w' => '5000']);
        $res1 = $controller->serve($media->uuid->toRfc4122(), $req1);
        $this->assertSame(422, $res1->getStatusCode());

        // Quality exceeds 100
        $req2 = new Request(['q' => '150']);
        $res2 = $controller->serve($media->uuid->toRfc4122(), $req2);
        $this->assertSame(422, $res2->getStatusCode());

        // Invalid fit
        $req3 = new Request(['fit' => 'evil-fit-script']);
        $res3 = $controller->serve($media->uuid->toRfc4122(), $req3);
        $this->assertSame(422, $res3->getStatusCode());

        // Invalid format
        $req4 = new Request(['fmt' => 'exe']);
        $res4 = $controller->serve($media->uuid->toRfc4122(), $req4);
        $this->assertSame(422, $res4->getStatusCode());

        // Invalid bg color
        $req5 = new Request(['bg' => 'blue; drop table;']);
        $res5 = $controller->serve($media->uuid->toRfc4122(), $req5);
        $this->assertSame(422, $res5->getStatusCode());

        // Clean up
        @unlink($tempDir . '/public/uploads/media/' . $project->uuid->toRfc4122() . '/sample.jpg');
        @rmdir($tempDir . '/public/uploads/media/' . $project->uuid->toRfc4122());
        @rmdir($tempDir . '/public/uploads/media');
        @rmdir($tempDir . '/public/uploads');
        @rmdir($tempDir . '/public');
        @rmdir($tempDir);
    }

    public function testImageTransformPreventsInternalErrorLeakageOnFailure(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $mediaRepo = $this->createMock(EntityRepository::class);
        $transform = $this->createMock(ImageTransformService::class);

        $project = new Project();
        $project->publicApi = true;
        $project->uuid = Uuid::v4();

        $media = new Media();
        $media->project = $project;
        $media->uuid = Uuid::v4();
        $media->fileName = 'corrupt.jpg';
        $media->mimeType = 'image/jpeg';

        $mediaRepo->method('findOneBy')->willReturn($media);
        $em->method('getRepository')->with(Media::class)->willReturn($mediaRepo);

        $tempDir = sys_get_temp_dir() . '/jambo_cdn_err_' . bin2hex(random_bytes(4));
        @mkdir($tempDir . '/public/uploads/media/' . $project->uuid->toRfc4122(), 0755, true);
        file_put_contents($tempDir . '/public/uploads/media/' . $project->uuid->toRfc4122() . '/corrupt.jpg', 'corrupt');

        $transform->method('transform')->willThrowException(new \RuntimeException('GD error at /var/www/internal/path.c:429'));

        $controller = new ImageTransformController($em, $transform);
        $container = $this->createContainerWithUser(null);
        $container->set('parameter_bag', new class($tempDir) {
            public function __construct(private string $dir) {}
            public function get(string $name): string {
                return $name === 'kernel.project_dir' ? $this->dir : '';
            }
        });
        $controller->setContainer($container);

        $req = new Request(['w' => '200', 'h' => '200']);
        $res = $controller->serve($media->uuid->toRfc4122(), $req);

        $this->assertSame(422, $res->getStatusCode());
        // Must NOT leak the internal exception message
        $this->assertStringNotContainsString('/var/www/internal/path.c', (string) $res->getContent());
        $this->assertStringNotContainsString('GD error', (string) $res->getContent());

        @unlink($tempDir . '/public/uploads/media/' . $project->uuid->toRfc4122() . '/corrupt.jpg');
        @rmdir($tempDir . '/public/uploads/media/' . $project->uuid->toRfc4122());
        @rmdir($tempDir . '/public/uploads/media');
        @rmdir($tempDir . '/public/uploads');
        @rmdir($tempDir . '/public');
        @rmdir($tempDir);
    }

    // =========================================================================
    // 2. PreviewController Tests
    // =========================================================================

    public function testPreviewControllerRejectsInvalidUuids(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $tokenService = $this->createMock(PreviewTokenService::class);
        $formatter = $this->createMock(EavDataFormatterService::class);
        $ssrf = $this->createMock(SsrfProtectionService::class);

        $controller = new PreviewController(
            $projectRepo,
            $entryRepo,
            $tokenService,
            $formatter,
            $ssrf,
            $this->createAcceptingLimiter()
        );
        $user = new User();
        $controller->setContainer($this->createContainerWithUser($user));

        // Invalid projectUuid on token
        $res1 = $controller->token('invalid-project-uuid', '00000000-0000-0000-0000-000000000001', new Request());
        $this->assertSame(404, $res1->getStatusCode());

        // Invalid entryUuid on token
        $res2 = $controller->token('00000000-0000-0000-0000-000000000001', 'invalid-entry-uuid', new Request());
        $this->assertSame(404, $res2->getStatusCode());

        // Invalid projectUuid on validateUrl
        $res3 = $controller->validateUrl('invalid-project-uuid', new Request(['url' => 'https://example.com']));
        $this->assertSame(404, $res3->getStatusCode());

        // Invalid collection on content
        $res4 = $controller->content(
            '00000000-0000-0000-0000-000000000001',
            'bad collection! slug',
            '00000000-0000-0000-0000-000000000002',
            new Request([], [], [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer token'])
        );
        $this->assertSame(400, $res4->getStatusCode());
    }

    public function testPreviewControllerRejectsOversizedOrMalformedToken(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $tokenService = $this->createMock(PreviewTokenService::class);
        $formatter = $this->createMock(EavDataFormatterService::class);
        $ssrf = $this->createMock(SsrfProtectionService::class);

        $controller = new PreviewController(
            $projectRepo,
            $entryRepo,
            $tokenService,
            $formatter,
            $ssrf,
            $this->createAcceptingLimiter()
        );
        $controller->setContainer($this->createContainerWithUser(null));

        $hugeToken = str_repeat('a', 5000);
        $req = new Request([], [], [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $hugeToken]);

        $res = $controller->content(
            '00000000-0000-0000-0000-000000000001',
            'articles',
            '00000000-0000-0000-0000-000000000002',
            $req
        );
        $this->assertSame(401, $res->getStatusCode());
    }

    // =========================================================================
    // 3. McpController Tests
    // =========================================================================

    public function testMcpControllerRejectsEmptyOrOversizedPayload(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $mcpServer = $this->createMock(JamboApiMcpServer::class);
        $limiter = $this->createAcceptingLimiter();

        $controller = new McpController($em, $mcpServer, $limiter);
        $controller->setContainer($this->createContainerWithUser(null));

        // Empty payload
        $reqEmpty = new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1'], '');
        $resEmpty = $controller->handle($reqEmpty);
        $this->assertSame(400, $resEmpty->getStatusCode());

        // Oversized payload (> 2MB)
        $hugeContent = str_repeat('x', 2 * 1024 * 1024 + 10);
        $reqHuge = new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1'], $hugeContent);
        $resHuge = $controller->handle($reqHuge);
        $this->assertSame(413, $resHuge->getStatusCode());
    }

    public function testMcpControllerRejectsInvalidProjectUuid(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $mcpServer = $this->createMock(JamboApiMcpServer::class);
        $limiter = $this->createAcceptingLimiter();

        $controller = new McpController($em, $mcpServer, $limiter);
        $controller->setContainer($this->createContainerWithUser(null));

        $req = new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1'], '{"jsonrpc":"2.0"}');
        $res = $controller->handleProject('not-a-valid-uuid', $req);

        $this->assertSame(404, $res->getStatusCode());
    }

    public function testMcpServerStrictJsonRpcValidation(): void
    {
        $server = new \App\Mcp\McpServer('TestServer', '1.0.0');

        // Invalid JSON-RPC version
        $res1 = $server->handleRequest('{"jsonrpc":"1.0","id":1,"method":"ping"}');
        $data1 = json_decode((string) $res1->getContent(), true);
        $this->assertSame(-32600, $data1['error']['code']);
        $this->assertStringContainsString('jsonrpc version must be "2.0"', $data1['error']['message']);

        // Non-string method
        $res2 = $server->handleRequest('{"jsonrpc":"2.0","id":1,"method":12345}');
        $data2 = json_decode((string) $res2->getContent(), true);
        $this->assertSame(-32600, $data2['error']['code']);
        $this->assertStringContainsString('method required', $data2['error']['message']);

        // Non-array params
        $res3 = $server->handleRequest('{"jsonrpc":"2.0","id":1,"method":"ping","params":"string-not-array"}');
        $data3 = json_decode((string) $res3->getContent(), true);
        $this->assertSame(-32600, $data3['error']['code']);
        $this->assertStringContainsString('params must be an object or array', $data3['error']['message']);

        // Invalid id type (array instead of scalar/null)
        $res4 = $server->handleRequest('{"jsonrpc":"2.0","id":[1, 2],"method":"ping"}');
        $data4 = json_decode((string) $res4->getContent(), true);
        $this->assertSame(-32600, $data4['error']['code']);
        $this->assertStringContainsString('id must be a string, integer or null', $data4['error']['message']);

        // Valid ping request
        $res5 = $server->handleRequest('{"jsonrpc":"2.0","id":42,"method":"ping"}');
        $data5 = json_decode((string) $res5->getContent(), true);
        $this->assertSame(42, $data5['id']);
        $this->assertTrue($data5['result']['pong']);
    }

    public function testPreviewControllerClaimMismatch(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $tokenService = $this->createMock(PreviewTokenService::class);
        $formatter = $this->createMock(EavDataFormatterService::class);
        $ssrf = $this->createMock(SsrfProtectionService::class);

        $controller = new PreviewController(
            $projectRepo,
            $entryRepo,
            $tokenService,
            $formatter,
            $ssrf,
            $this->createAcceptingLimiter()
        );
        $controller->setContainer($this->createContainerWithUser(null));

        $tokenService->method('validateToken')->willReturn([
            'pid' => '00000000-0000-0000-0000-000000000001',
            'eid' => '00000000-0000-0000-0000-000000000002',
            'col' => 'articles',
        ]);

        $req = new Request([], [], [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer sample-token']);

        // Project mismatch
        $res1 = $controller->content(
            '00000000-0000-0000-0000-000000000099',
            'articles',
            '00000000-0000-0000-0000-000000000002',
            $req
        );
        $this->assertSame(403, $res1->getStatusCode());

        // Entry mismatch
        $res2 = $controller->content(
            '00000000-0000-0000-0000-000000000001',
            'articles',
            '00000000-0000-0000-0000-000000000099',
            $req
        );
        $this->assertSame(403, $res2->getStatusCode());

        // Collection mismatch
        $res3 = $controller->content(
            '00000000-0000-0000-0000-000000000001',
            'products',
            '00000000-0000-0000-0000-000000000002',
            $req
        );
        $this->assertSame(403, $res3->getStatusCode());
    }

    public function testImageTransformServeSvgSetsCspHeaders(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $mediaRepo = $this->createMock(EntityRepository::class);
        $transform = $this->createMock(ImageTransformService::class);

        $project = new Project();
        $project->publicApi = true;
        $project->uuid = Uuid::v4();

        $media = new Media();
        $media->project = $project;
        $media->uuid = Uuid::v4();
        $media->fileName = 'logo.svg';
        $media->mimeType = 'image/svg+xml';

        $mediaRepo->method('findOneBy')->willReturn($media);
        $em->method('getRepository')->with(Media::class)->willReturn($mediaRepo);

        $tempDir = sys_get_temp_dir() . '/jambo_cdn_svg_' . bin2hex(random_bytes(4));
        @mkdir($tempDir . '/public/uploads/media/' . $project->uuid->toRfc4122(), 0755, true);
        file_put_contents($tempDir . '/public/uploads/media/' . $project->uuid->toRfc4122() . '/logo.svg', '<svg></svg>');

        $controller = new ImageTransformController($em, $transform);
        $container = $this->createContainerWithUser(null);
        $container->set('parameter_bag', new class($tempDir) {
            public function __construct(private string $dir) {}
            public function get(string $name): string {
                return $name === 'kernel.project_dir' ? $this->dir : '';
            }
        });
        $controller->setContainer($container);

        $response = $controller->serve($media->uuid->toRfc4122(), new Request(['w' => '100']));

        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->assertSame('image/svg+xml', $response->headers->get('Content-Type'));
        $this->assertSame("default-src 'none'; frame-ancestors 'none';", $response->headers->get('Content-Security-Policy'));

        @unlink($tempDir . '/public/uploads/media/' . $project->uuid->toRfc4122() . '/logo.svg');
        @rmdir($tempDir . '/public/uploads/media/' . $project->uuid->toRfc4122());
        @rmdir($tempDir . '/public/uploads/media');
        @rmdir($tempDir . '/public/uploads');
        @rmdir($tempDir . '/public');
        @rmdir($tempDir);
    }
}
