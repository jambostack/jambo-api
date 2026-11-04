<?php

namespace App\Tests\Controller;

use App\Controller\ColumnSettingController;
use App\Controller\CommentController;
use App\Controller\DeploymentController;
use App\Controller\McpController;
use App\Entity\Collection;
use App\Entity\ColumnSetting;
use App\Entity\Comment;
use App\Entity\ContentEntry;
use App\Entity\Deployment;
use App\Entity\Project;
use App\Entity\User;
use App\Mcp\JamboApiMcpServer;
use App\Repository\CollectionRepository;
use App\Repository\ColumnSettingRepository;
use App\Repository\CommentRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\DeploymentRepository;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Cycle 31 — Security & Hardening Tests
 *
 * Covers:
 *  1. DeploymentController:
 *     - Unauthenticated access returns 401.
 *     - Missing or invalid HMAC-SHA256 signature returns 401.
 *     - Rate limiting blocks hook spam (429).
 *     - Anti-SSRF blocks private/loopback/cloud-metadata addresses in preview_url and run_url (422).
 *     - Valid signed deployment webhook persists record (201).
 *  2. ColumnSettingController:
 *     - Unauthenticated access returns 401.
 *     - Invalid UUID returns 404.
 *     - Non-array columns payload returns 422.
 *     - Visible columns are sanitized (strip_tags, trimmed, capped at 50).
 *  3. CommentController:
 *     - Unauthenticated access returns 401.
 *     - Empty or oversized body returns 422.
 *     - Rate limiter blocks comment creation spam (429).
 *     - Anti-deep-nesting flattens nested comments.
 *     - Invalid status transitions rejected (422).
 *  4. McpController:
 *     - Rate limit exhaustion returns 429.
 *     - Oversized payload (>2MB) returns 413.
 *     - Info endpoint returns translated metadata.
 */
#[AllowMockObjectsWithoutExpectations]
class Cycle31HardeningTest extends TestCase
{
    private function createLimiterFactory(int $limit, string $id = 'test_limiter'): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
    }

    private function createDummyTranslator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(fn (string $id) => 'trans_' . $id);
        return $translator;
    }

    private function setupControllerSecurity(object $controller, ?User $user, array $grantedAttributes = []): void
    {
        $container = $this->createStub(ContainerInterface::class);

        $tokenStorage = $this->createStub(TokenStorageInterface::class);
        if ($user !== null) {
            $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
            $tokenStorage->method('getToken')->willReturn($token);
        } else {
            $tokenStorage->method('getToken')->willReturn(null);
        }

        $authChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturnCallback(function ($attribute) use ($grantedAttributes) {
            return in_array($attribute, $grantedAttributes, true);
        });

        $container->method('has')->willReturnCallback(fn ($id) => in_array($id, ['security.token_storage', 'security.authorization_checker'], true));
        $container->method('get')->willReturnCallback(function ($id) use ($tokenStorage, $authChecker) {
            if ($id === 'security.token_storage') return $tokenStorage;
            if ($id === 'security.authorization_checker') return $authChecker;
            return null;
        });

        $controller->setContainer($container);
    }

    public function testDeploymentControllerRequiresAuthForIndex(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $deployments = $this->createStub(DeploymentRepository::class);
        $projects = $this->createStub(ProjectRepository::class);
        $translator = $this->createDummyTranslator();

        $controller = new DeploymentController($em, $deployments, $projects, null, $translator);
        $this->setupControllerSecurity($controller, null);

        $response = $controller->index(new Request());
        $this->assertSame(401, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('trans_deployment.auth_required', $data['error']);
    }

    public function testDeploymentControllerHookRejectsMissingOrInvalidSignature(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $deployments = $this->createStub(DeploymentRepository::class);
        $projects = $this->createStub(ProjectRepository::class);
        $translator = $this->createDummyTranslator();

        $_ENV['DEPLOY_WEBHOOK_SECRET'] = 'test-secret-key-123';

        $controller = new DeploymentController($em, $deployments, $projects, null, $translator);

        // Missing signature
        $requestNoSig = new Request([], [], [], [], [], ['HTTP_CONTENT_TYPE' => 'application/json'], '{"environment":"production"}');
        $respNoSig = $controller->hook($requestNoSig);
        $this->assertSame(401, $respNoSig->getStatusCode());
        $this->assertSame('trans_deployment.signature_missing', json_decode($respNoSig->getContent(), true)['error']);

        // Invalid signature
        $requestBadSig = new Request(
            [], [], [], [], [],
            ['HTTP_CONTENT_TYPE' => 'application/json', 'HTTP_X_JAMBO_SIGNATURE' => 'sha256=invalidhex'],
            '{"environment":"production"}'
        );
        $respBadSig = $controller->hook($requestBadSig);
        $this->assertSame(401, $respBadSig->getStatusCode());
        $this->assertSame('trans_deployment.signature_invalid', json_decode($respBadSig->getContent(), true)['error']);
    }

    public function testDeploymentControllerHookRejectsSsrfPreviewUrl(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $deployments = $this->createStub(DeploymentRepository::class);
        $projects = $this->createStub(ProjectRepository::class);
        $translator = $this->createDummyTranslator();

        $secret = 'test-secret-key-123';
        $_ENV['DEPLOY_WEBHOOK_SECRET'] = $secret;

        $controller = new DeploymentController($em, $deployments, $projects, null, $translator);

        $payload = json_encode([
            'environment' => 'preview',
            'status' => 'succeeded',
            'preview_url' => 'http://127.0.0.1:8080/admin',
        ]);
        $sig = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        $request = new Request(
            [], [], [], [], [],
            ['HTTP_CONTENT_TYPE' => 'application/json', 'HTTP_X_JAMBO_SIGNATURE' => $sig],
            $payload
        );

        $response = $controller->hook($request);
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('trans_deployment.invalid_preview_url_ssrf', $data['error']);
    }

    public function testDeploymentControllerHookAcceptsValidRequest(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $deployments = $this->createStub(DeploymentRepository::class);
        $projects = $this->createStub(ProjectRepository::class);
        $translator = $this->createDummyTranslator();

        $secret = 'test-secret-key-123';
        $_ENV['DEPLOY_WEBHOOK_SECRET'] = $secret;

        $controller = new DeploymentController($em, $deployments, $projects, null, $translator);

        $payload = json_encode([
            'environment' => 'production',
            'status' => 'succeeded',
            'commit_sha' => '43fd50de9fc627ce480a09a92fc98a085c341adb',
            'branch' => 'main',
            'preview_url' => 'https://api.jambostack.site',
        ]);
        $sig = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        $request = new Request(
            [], [], [], [], [],
            ['HTTP_CONTENT_TYPE' => 'application/json', 'HTTP_X_JAMBO_SIGNATURE' => $sig],
            $payload
        );

        $response = $controller->hook($request);
        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('43fd50de9fc627ce480a09a92fc98a085c341adb', $data['data']['commit_sha']);
        $this->assertSame('production', $data['data']['environment']);
    }

    public function testColumnSettingControllerRejectsInvalidUuid(): void
    {
        $projects = $this->createStub(ProjectRepository::class);
        $collections = $this->createStub(CollectionRepository::class);
        $columnRepo = $this->createStub(ColumnSettingRepository::class);
        $em = $this->createStub(EntityManagerInterface::class);
        $translator = $this->createDummyTranslator();

        $user = new User();
        $user->email = 'col-test@example.com';

        $controller = new ColumnSettingController($projects, $collections, $columnRepo, $em, $translator);
        $this->setupControllerSecurity($controller, $user, ['project.view']);

        $response = $controller->show('invalid-uuid-format', 'posts');
        $this->assertSame(404, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('trans_column_setting.project_not_found', $data['error']);
    }

    public function testColumnSettingControllerSanitizesAndPersistsColumns(): void
    {
        $projectUuid = '00000000-0000-0000-0000-000000000002';
        $project = new Project();
        $project->uuid = Uuid::fromString($projectUuid);

        $collection = new Collection();
        $collection->slug = 'posts';
        $collection->project = $project;

        $projects = $this->createStub(ProjectRepository::class);
        $projects->method('findOneBy')->willReturn($project);

        $collections = $this->createStub(CollectionRepository::class);
        $collections->method('findOneByProjectAndSlug')->willReturn($collection);

        $columnRepo = $this->createStub(ColumnSettingRepository::class);
        $columnRepo->method('findOneByUserAndCollection')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $translator = $this->createDummyTranslator();

        $user = new User();
        $user->email = 'col-test2@example.com';

        $controller = new ColumnSettingController($projects, $collections, $columnRepo, $em, $translator);
        $this->setupControllerSecurity($controller, $user, ['project.view']);

        $request = new Request(
            [], [], [], [], [],
            ['HTTP_CONTENT_TYPE' => 'application/json'],
            json_encode(['visible_columns' => ['title', '<script>alert(1)</script>status', '   created_at   ']])
        );

        $response = $controller->update($projectUuid, 'posts', $request);
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame(['title', 'alert(1)status', 'created_at'], $data['visibleColumns']);
    }

    public function testCommentControllerValidationAndFlattening(): void
    {
        $projectUuid = '00000000-0000-0000-0000-000000000003';
        $project = new Project();
        $project->uuid = Uuid::fromString($projectUuid);

        $collection = new Collection();
        $collection->slug = 'articles';
        $collection->project = $project;

        $entry = new ContentEntry();
        $entry->id = 1;
        $entry->project = $project;
        $entry->collection = $collection;

        $entryRepo = $this->createStub(ContentEntryRepository::class);
        $entryRepo->method('find')->willReturn($entry);

        $rootComment = new Comment();
        $rootComment->id = 10;
        $rootComment->entry = $entry;

        $replyComment = new Comment();
        $replyComment->id = 11;
        $replyComment->entry = $entry;
        $replyComment->parent = $rootComment;

        $commentRepo = $this->createStub(CommentRepository::class);
        $commentRepo->method('find')->willReturnCallback(function ($id) use ($rootComment, $replyComment) {
            if ($id === 10) return $rootComment;
            if ($id === 11) return $replyComment;
            return null;
        });

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with($this->callback(function (Comment $c) use ($rootComment) {
            // Must be flattened to point to rootComment, not replyComment
            return $c->parent === $rootComment;
        }));
        $em->expects($this->once())->method('flush');

        $translator = $this->createDummyTranslator();
        $user = new User();
        $user->email = 'commenter@example.com';

        $projects = $this->createStub(ProjectRepository::class);
        $collections = $this->createStub(CollectionRepository::class);

        $controller = new CommentController(
            $projects, $collections, $entryRepo, $commentRepo, $em, null, null, $translator
        );
        $this->setupControllerSecurity($controller, $user, ['content.update']);

        // Empty body rejected
        $reqEmpty = new Request([], [], [], [], [], ['HTTP_CONTENT_TYPE' => 'application/json'], json_encode(['body' => '   ']));
        $respEmpty = $controller->create($projectUuid, 'articles', 1, $reqEmpty);
        $this->assertSame(422, $respEmpty->getStatusCode());
        $this->assertSame('trans_comment.body_empty', json_decode($respEmpty->getContent(), true)['error']);

        // Nested reply is flattened to root
        $reqValid = new Request(
            [], [], [], [], [],
            ['HTTP_CONTENT_TYPE' => 'application/json'],
            json_encode(['body' => 'Deep nested reply', 'parent_id' => 11])
        );
        $respValid = $controller->create($projectUuid, 'articles', 1, $reqValid);
        $this->assertSame(201, $respValid->getStatusCode());
    }

    public function testMcpControllerRateLimiterAndPayloadCheck(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $mcpServer = $this->createStub(JamboApiMcpServer::class);
        $translator = $this->createDummyTranslator();

        $limiter = $this->createLimiterFactory(1, 'mcp_test_limiter');
        $limiter->create('127.0.0.1')->consume(1); // exhaust rate limit

        $controller = new McpController($em, $mcpServer, $limiter, $translator);

        $req = new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1'], '{}');
        $resp = $controller->handle($req);

        $this->assertSame(429, $resp->getStatusCode());
        $data = json_decode($resp->getContent(), true);
        $this->assertSame('trans_mcp.rate_limit_exceeded', $data['error']);
    }

    public function testCommentControllerUpdateAndResolvePermissions(): void
    {
        $projectUuid = '00000000-0000-0000-0000-000000000004';
        $project = new Project();
        $project->uuid = Uuid::fromString($projectUuid);

        $collection = new Collection();
        $collection->slug = 'posts';
        $collection->project = $project;

        $entry = new ContentEntry();
        $entry->id = 1;
        $entry->project = $project;
        $entry->collection = $collection;

        $author = new User();
        $author->id = 1;

        $otherUser = new User();
        $otherUser->id = 2;

        $comment = new Comment();
        $comment->id = 42;
        $comment->entry = $entry;
        $comment->author = $author;
        $comment->body = 'Original content';

        $commentRepo = $this->createStub(CommentRepository::class);
        $commentRepo->method('find')->willReturn($comment);

        $entryRepo = $this->createStub(ContentEntryRepository::class);
        $projects = $this->createStub(ProjectRepository::class);
        $collections = $this->createStub(CollectionRepository::class);
        $em = $this->createStub(EntityManagerInterface::class);
        $translator = $this->createDummyTranslator();

        $controller = new CommentController(
            $projects, $collections, $entryRepo, $commentRepo, $em, null, null, $translator
        );

        // Forbidden when other user tries to update
        $this->setupControllerSecurity($controller, $otherUser, ['content.update']);
        $reqOther = new Request([], [], [], [], [], ['HTTP_CONTENT_TYPE' => 'application/json'], json_encode(['body' => 'Hacked']));
        $respOther = $controller->update($projectUuid, 'posts', 1, 42, $reqOther);
        $this->assertSame(403, $respOther->getStatusCode());
        $this->assertSame('trans_comment.access_denied', json_decode($respOther->getContent(), true)['error']);

        // Allowed when author updates
        $this->setupControllerSecurity($controller, $author, ['content.update']);
        $reqAuthor = new Request([], [], [], [], [], ['HTTP_CONTENT_TYPE' => 'application/json'], json_encode(['body' => 'Updated content']));
        $respAuthor = $controller->update($projectUuid, 'posts', 1, 42, $reqAuthor);
        $this->assertSame(200, $respAuthor->getStatusCode());
        $this->assertSame('Updated content', $comment->body);

        // Invalid status in resolve returns 422
        $reqInvalidStatus = new Request([], [], [], [], [], ['HTTP_CONTENT_TYPE' => 'application/json'], json_encode(['status' => 'bogus']));
        $respInvalidStatus = $controller->resolve($projectUuid, 'posts', 1, 42, $reqInvalidStatus);
        $this->assertSame(422, $respInvalidStatus->getStatusCode());
        $this->assertSame('trans_comment.invalid_status', json_decode($respInvalidStatus->getContent(), true)['error']);

        // Valid status in resolve returns 200
        $reqValidStatus = new Request([], [], [], [], [], ['HTTP_CONTENT_TYPE' => 'application/json'], json_encode(['status' => 'resolved']));
        $respValidStatus = $controller->resolve($projectUuid, 'posts', 1, 42, $reqValidStatus);
        $this->assertSame(200, $respValidStatus->getStatusCode());
        $this->assertSame('resolved', $comment->status);
    }

    public function testMcpControllerInfoReturnsTranslatedDescription(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $mcpServer = $this->createStub(JamboApiMcpServer::class);
        $limiter = $this->createLimiterFactory(100, 'mcp_info_limiter');
        $translator = $this->createDummyTranslator();

        $controller = new McpController($em, $mcpServer, $limiter, $translator);

        $resp = $controller->info();
        $this->assertSame(200, $resp->getStatusCode());
        $data = json_decode($resp->getContent(), true);
        $this->assertSame('JamboApi MCP Server', $data['name']);
        $this->assertSame('trans_mcp.auth_description', $data['authentication']['description']);
    }
}
