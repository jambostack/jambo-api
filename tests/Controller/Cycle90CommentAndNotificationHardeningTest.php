<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\CommentController;
use App\Controller\NotificationController;
use App\Entity\Collection;
use App\Entity\Comment;
use App\Entity\ContentEntry;
use App\Entity\Notification;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\CollectionRepository;
use App\Repository\CommentRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\NotificationRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Service\AuditService;
use App\Service\NotificationService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

class Cycle90CommentAndNotificationHardeningTest extends TestCase
{
    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            fn (string $id, array $params = []) => strtr($id, $params)
        );
        return $translator;
    }

    private function createMockUser(int $id = 42, array $roles = ['ROLE_USER']): User
    {
        $user = new User();
        $user->id = $id;
        $user->email = "user{$id}@example.com";
        $user->name = "User {$id}";
        $user->roles = $roles;
        return $user;
    }

    private function createMockProject(string $uuid = '00000000-0000-4000-8000-000000000001', array $settings = []): Project
    {
        $project = new Project();
        $project->id = 1;
        $project->uuid = Uuid::fromString($uuid);
        $project->name = 'Test Project';
        $project->settings = $settings;
        return $project;
    }

    private function createMockEntry(Project $project, string $collectionSlug = 'articles', int $entryId = 100): ContentEntry
    {
        $collection = new Collection();
        $collection->id = 10;
        $collection->slug = $collectionSlug;
        $collection->name = 'Articles';

        $entry = new ContentEntry();
        $entry->id = $entryId;
        $entry->project = $project;
        $entry->collection = $collection;
        $entry->locale = 'en';
        $entry->status = 'published';
        return $entry;
    }

    private function setupControllerSecurity(object $controller, ?User $user, bool $granted = true): void
    {
        $authChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturn($granted);

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        if ($user !== null) {
            $token = new UsernamePasswordToken($user, 'main', $user->getRoles());
            $tokenStorage->method('getToken')->willReturn($token);
        } else {
            $tokenStorage->method('getToken')->willReturn(null);
        }

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(fn ($id) => in_array($id, ['security.authorization_checker', 'security.token_storage'], true));
        $container->method('get')->willReturnCallback(function ($id) use ($authChecker, $tokenStorage) {
            if ($id === 'security.authorization_checker') {
                return $authChecker;
            }
            if ($id === 'security.token_storage') {
                return $tokenStorage;
            }
            return null;
        });

        $ref = new \ReflectionClass($controller);
        $parent = $ref->getParentClass();
        while ($parent && $parent->getName() !== 'Symfony\Bundle\FrameworkBundle\Controller\AbstractController') {
            $parent = $parent->getParentClass();
        }
        if ($parent) {
            $prop = $parent->getProperty('container');
            $prop->setValue($controller, $container);
        }
    }

    // ════════════════════ 1. CommentController Security & Sanitization ════════════════════

    public function testCommentSecurityHeadersAndUnauthorized(): void
    {
        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $commentRepo = $this->createMock(CommentRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $controller = new CommentController(
            $this->createMock(ProjectRepository::class),
            $this->createMock(CollectionRepository::class),
            $entryRepo,
            $commentRepo,
            $em,
            null,
            null,
            $this->createTranslator()
        );
        $this->setupControllerSecurity($controller, null);

        $req = new Request([], [], [], [], [], [], json_encode(['body' => 'Hello']));
        $uuid = '00000000-0000-4000-8000-000000000001';
        $res = $controller->create($uuid, 'articles', 100, $req);

        $this->assertSame(401, $res->getStatusCode());
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $res->headers->get('X-Frame-Options'));
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));

        $data = json_decode((string) $res->getContent(), true);
        $this->assertSame('AUTH_REQUIRED', $data['code']);
    }

    public function testCommentSanitizeBodyNeutralizesXssAndDangerousVectors(): void
    {
        $controller = new CommentController(
            $this->createMock(ProjectRepository::class),
            $this->createMock(CollectionRepository::class),
            $this->createMock(ContentEntryRepository::class),
            $this->createMock(CommentRepository::class),
            $this->createMock(EntityManagerInterface::class)
        );

        // 1. Script tags eradication
        $scriptInput = 'Hello <script>alert("XSS")</script> World!';
        $this->assertSame('Hello  World!', $controller->sanitizeCommentBody($scriptInput));

        // 2. Iframe and dangerous tags eradication
        $iframeInput = 'Test <iframe src="https://evil.com"></iframe> Frame';
        $this->assertSame('Test  Frame', $controller->sanitizeCommentBody($iframeInput));

        // 3. Event handlers removal
        $eventInput = '<p onclick="steal()">Click me</p>';
        $this->assertSame('<p>Click me</p>', $controller->sanitizeCommentBody($eventInput));

        // 4. Markdown javascript protocol neutralization
        $mdInput = 'Check this [link](javascript:alert(1)) now';
        $this->assertSame('Check this [link](#blocked) now', $controller->sanitizeCommentBody($mdInput));

        // 5. Data URI protocol in markdown neutralization
        $dataInput = 'Check this [payload](data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==)';
        $this->assertSame('Check this [payload](#blocked)', $controller->sanitizeCommentBody($dataInput));

        // 6. Safe <a> tags validated
        $safeLinkInput = '<a href="https://example.com/test">Valid Link</a>';
        $sanitizedSafeLink = $controller->sanitizeCommentBody($safeLinkInput);
        $this->assertStringContainsString('href="https://example.com/test"', $sanitizedSafeLink);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $sanitizedSafeLink);

        // 7. Dangerous href in <a> tag neutralized
        $badLinkInput = '<a href="javascript:alert(1)">Evil Link</a>';
        $this->assertSame('<a>Evil Link</a>', $controller->sanitizeCommentBody($badLinkInput));

        // 8. Line break bombing collapsed
        $bombInput = "Line 1\n\n\n\n\n\n\nLine 2";
        $this->assertSame("Line 1\n\n\nLine 2", $controller->sanitizeCommentBody($bombInput));
    }

    public function testCommentCreationRejectsEmptyOrAllStrippedBody(): void
    {
        $project = $this->createMockProject();
        $entry = $this->createMockEntry($project);
        $user = $this->createMockUser();

        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $entryRepo->method('find')->willReturn($entry);

        $commentRepo = $this->createMock(CommentRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $controller = new CommentController(
            $this->createMock(ProjectRepository::class),
            $this->createMock(CollectionRepository::class),
            $entryRepo,
            $commentRepo,
            $em,
            null,
            null,
            $this->createTranslator()
        );
        $this->setupControllerSecurity($controller, $user);

        // Body with only malicious script which gets completely stripped
        $req = new Request([], [], [], [], [], [], json_encode(['body' => '<script>alert(1)</script>']));
        $uuid = $project->uuid->toRfc4122();
        $res = $controller->create($uuid, 'articles', 100, $req);

        $this->assertSame(422, $res->getStatusCode());
        $data = json_decode((string) $res->getContent(), true);
        $this->assertSame('BODY_EMPTY', $data['code']);
    }

    public function testCommentCreationRejectsBodyTooLong(): void
    {
        $project = $this->createMockProject();
        $entry = $this->createMockEntry($project);
        $user = $this->createMockUser();

        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $entryRepo->method('find')->willReturn($entry);

        $commentRepo = $this->createMock(CommentRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $controller = new CommentController(
            $this->createMock(ProjectRepository::class),
            $this->createMock(CollectionRepository::class),
            $entryRepo,
            $commentRepo,
            $em,
            null,
            null,
            $this->createTranslator()
        );
        $this->setupControllerSecurity($controller, $user);

        $longBody = str_repeat('A', 10001);
        $req = new Request([], [], [], [], [], [], json_encode(['body' => $longBody]));
        $uuid = $project->uuid->toRfc4122();
        $res = $controller->create($uuid, 'articles', 100, $req);

        $this->assertSame(422, $res->getStatusCode());
        $data = json_decode((string) $res->getContent(), true);
        $this->assertSame('BODY_TOO_LONG', $data['code']);
    }

    // ════════════════════ 2. Comment Quotas & Tier Limits ════════════════════

    public function testCommentEntryQuotaExceeded(): void
    {
        $project = $this->createMockProject('00000000-0000-4000-8000-000000000001', ['tier' => 'free']);
        $entry = $this->createMockEntry($project);
        $user = $this->createMockUser();

        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $entryRepo->method('find')->willReturn($entry);

        $commentRepo = $this->createMock(CommentRepository::class);
        // Free tier entry comment limit is 100; simulate 100 already present
        $commentRepo->method('countByEntry')->willReturn(100);

        $em = $this->createMock(EntityManagerInterface::class);

        $controller = new CommentController(
            $this->createMock(ProjectRepository::class),
            $this->createMock(CollectionRepository::class),
            $entryRepo,
            $commentRepo,
            $em,
            null,
            null,
            $this->createTranslator()
        );
        $this->setupControllerSecurity($controller, $user);

        $req = new Request([], [], [], [], [], [], json_encode(['body' => 'Another comment']));
        $uuid = $project->uuid->toRfc4122();
        $res = $controller->create($uuid, 'articles', 100, $req);

        $this->assertSame(422, $res->getStatusCode());
        $data = json_decode((string) $res->getContent(), true);
        $this->assertSame('ENTRY_COMMENT_LIMIT_EXCEEDED', $data['code']);
    }

    public function testCommentProjectTierQuotaExceeded(): void
    {
        $project = $this->createMockProject('00000000-0000-4000-8000-000000000001', ['tier' => 'free']);
        $entry = $this->createMockEntry($project);
        $user = $this->createMockUser();

        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $entryRepo->method('find')->willReturn($entry);

        $commentRepo = $this->createMock(CommentRepository::class);
        $commentRepo->method('countByEntry')->willReturn(10); // Entry count is fine
        // Free tier project comment limit is 250; simulate 250 comments already present in project
        $commentRepo->method('countByProject')->willReturn(250);

        $em = $this->createMock(EntityManagerInterface::class);

        $controller = new CommentController(
            $this->createMock(ProjectRepository::class),
            $this->createMock(CollectionRepository::class),
            $entryRepo,
            $commentRepo,
            $em,
            null,
            null,
            $this->createTranslator()
        );
        $this->setupControllerSecurity($controller, $user);

        $req = new Request([], [], [], [], [], [], json_encode(['body' => 'Project quota test']));
        $uuid = $project->uuid->toRfc4122();
        $res = $controller->create($uuid, 'articles', 100, $req);

        $this->assertSame(422, $res->getStatusCode());
        $data = json_decode((string) $res->getContent(), true);
        $this->assertSame('PROJECT_COMMENT_LIMIT_EXCEEDED', $data['code']);
    }

    public function testCommentCustomSettingsQuotaOverride(): void
    {
        // Custom override via project settings: max_comments_per_entry = 5
        $project = $this->createMockProject('00000000-0000-4000-8000-000000000001', [
            'tier' => 'enterprise',
            'max_comments_per_entry' => 5,
        ]);
        $entry = $this->createMockEntry($project);
        $user = $this->createMockUser();

        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $entryRepo->method('find')->willReturn($entry);

        $commentRepo = $this->createMock(CommentRepository::class);
        $commentRepo->method('countByEntry')->willReturn(5);

        $em = $this->createMock(EntityManagerInterface::class);

        $controller = new CommentController(
            $this->createMock(ProjectRepository::class),
            $this->createMock(CollectionRepository::class),
            $entryRepo,
            $commentRepo,
            $em,
            null,
            null,
            $this->createTranslator()
        );
        $this->setupControllerSecurity($controller, $user);

        $req = new Request([], [], [], [], [], [], json_encode(['body' => 'Sixth comment']));
        $uuid = $project->uuid->toRfc4122();
        $res = $controller->create($uuid, 'articles', 100, $req);

        $this->assertSame(422, $res->getStatusCode());
        $data = json_decode((string) $res->getContent(), true);
        $this->assertSame('ENTRY_COMMENT_LIMIT_EXCEEDED', $data['code']);
    }

    // ════════════════════ 3. Comment Nesting Depth & Hierarchy Security ════════════════════

    public function testCommentNestingDepthRejectsExcessiveDepth(): void
    {
        $project = $this->createMockProject();
        $entry = $this->createMockEntry($project);
        $user = $this->createMockUser();

        // Build hierarchy: root (depth 1) -> child (depth 2) -> subchild (depth 3)
        $root = new Comment();
        $root->id = 1;
        $root->entry = $entry;

        $child = new Comment();
        $child->id = 2;
        $child->entry = $entry;
        $child->parent = $root;

        $subchild = new Comment();
        $subchild->id = 3;
        $subchild->entry = $entry;
        $subchild->parent = $child;

        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $entryRepo->method('find')->willReturn($entry);

        $commentRepo = $this->createMock(CommentRepository::class);
        $commentRepo->method('countByEntry')->willReturn(3);
        $commentRepo->method('countByProject')->willReturn(3);
        $commentRepo->method('find')->with(3)->willReturn($subchild);

        $em = $this->createMock(EntityManagerInterface::class);

        $controller = new CommentController(
            $this->createMock(ProjectRepository::class),
            $this->createMock(CollectionRepository::class),
            $entryRepo,
            $commentRepo,
            $em,
            null,
            null,
            $this->createTranslator()
        );
        $this->setupControllerSecurity($controller, $user);

        // Attempt to create a 4th level reply: depth > MAX_NESTING_DEPTH (3)
        $req = new Request([], [], [], [], [], [], json_encode([
            'body' => 'Level 4 reply',
            'parent_id' => 3,
        ]));
        $uuid = $project->uuid->toRfc4122();
        $res = $controller->create($uuid, 'articles', 100, $req);

        $this->assertSame(422, $res->getStatusCode());
        $data = json_decode((string) $res->getContent(), true);
        $this->assertSame('MAX_DEPTH_EXCEEDED', $data['code']);
    }

    public function testCommentParentBelongingToDifferentEntryRejected(): void
    {
        $project = $this->createMockProject();
        $entry1 = $this->createMockEntry($project, 'articles', 100);
        $entry2 = $this->createMockEntry($project, 'articles', 200);
        $user = $this->createMockUser();

        $foreignComment = new Comment();
        $foreignComment->id = 999;
        $foreignComment->entry = $entry2; // Belongs to entry 200

        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $entryRepo->method('find')->willReturn($entry1);

        $commentRepo = $this->createMock(CommentRepository::class);
        $commentRepo->method('countByEntry')->willReturn(0);
        $commentRepo->method('countByProject')->willReturn(0);
        $commentRepo->method('find')->with(999)->willReturn($foreignComment);

        $em = $this->createMock(EntityManagerInterface::class);

        $controller = new CommentController(
            $this->createMock(ProjectRepository::class),
            $this->createMock(CollectionRepository::class),
            $entryRepo,
            $commentRepo,
            $em,
            null,
            null,
            $this->createTranslator()
        );
        $this->setupControllerSecurity($controller, $user);

        $req = new Request([], [], [], [], [], [], json_encode([
            'body' => 'Attempt cross-entry parent linking',
            'parent_id' => 999,
        ]));
        $uuid = $project->uuid->toRfc4122();
        $res = $controller->create($uuid, 'articles', 100, $req);

        $this->assertSame(422, $res->getStatusCode());
        $data = json_decode((string) $res->getContent(), true);
        $this->assertSame('PARENT_ENTRY_MISMATCH', $data['code']);
    }

    // ════════════════════ 4. Comment Operations & Status ════════════════════

    public function testCommentUpdateAccessDeniedForNonAuthor(): void
    {
        $project = $this->createMockProject();
        $entry = $this->createMockEntry($project);
        $author = $this->createMockUser(42);
        $otherUser = $this->createMockUser(99);

        $comment = new Comment();
        $comment->id = 10;
        $comment->entry = $entry;
        $comment->author = $author;
        $comment->body = 'Original content';

        $commentRepo = $this->createMock(CommentRepository::class);
        $commentRepo->method('find')->willReturn($comment);

        $controller = new CommentController(
            $this->createMock(ProjectRepository::class),
            $this->createMock(CollectionRepository::class),
            $this->createMock(ContentEntryRepository::class),
            $commentRepo,
            $this->createMock(EntityManagerInterface::class),
            null,
            null,
            $this->createTranslator()
        );
        $this->setupControllerSecurity($controller, $otherUser);

        $req = new Request([], [], [], [], [], [], json_encode(['body' => 'Hacked body']));
        $uuid = $project->uuid->toRfc4122();
        $res = $controller->update($uuid, 'articles', 100, 10, $req);

        $this->assertSame(403, $res->getStatusCode());
        $data = json_decode((string) $res->getContent(), true);
        $this->assertSame('ACCESS_DENIED', $data['code']);
    }

    public function testCommentResolveStatusValidation(): void
    {
        $project = $this->createMockProject();
        $entry = $this->createMockEntry($project);
        $user = $this->createMockUser();

        $comment = new Comment();
        $comment->id = 10;
        $comment->entry = $entry;
        $comment->author = $user;
        $comment->status = 'open';

        $commentRepo = $this->createMock(CommentRepository::class);
        $commentRepo->method('find')->willReturn($comment);

        $em = $this->createMock(EntityManagerInterface::class);

        $controller = new CommentController(
            $this->createMock(ProjectRepository::class),
            $this->createMock(CollectionRepository::class),
            $this->createMock(ContentEntryRepository::class),
            $commentRepo,
            $em,
            null,
            null,
            $this->createTranslator()
        );
        $this->setupControllerSecurity($controller, $user);

        $uuid = $project->uuid->toRfc4122();

        // 1. Invalid status
        $reqInvalid = new Request([], [], [], [], [], [], json_encode(['status' => 'archived']));
        $resInvalid = $controller->resolve($uuid, 'articles', 100, 10, $reqInvalid);
        $this->assertSame(422, $resInvalid->getStatusCode());
        $dataInvalid = json_decode((string) $resInvalid->getContent(), true);
        $this->assertSame('INVALID_STATUS', $dataInvalid['code']);

        // 2. Valid status
        $reqValid = new Request([], [], [], [], [], [], json_encode(['status' => 'resolved']));
        $resValid = $controller->resolve($uuid, 'articles', 100, 10, $reqValid);
        $this->assertSame(200, $resValid->getStatusCode());
        $dataValid = json_decode((string) $resValid->getContent(), true);
        $this->assertSame('resolved', $dataValid['data']['status']);
    }

    // ════════════════════ 5. NotificationController Batch & Volume Hardening ════════════════════

    public function testNotificationBatchReadValidation(): void
    {
        $notifRepo = $this->createMock(NotificationRepository::class);
        $user = $this->createMockUser();

        $controller = new NotificationController(
            $notifRepo,
            null,
            null,
            null,
            $this->createTranslator()
        );
        $this->setupControllerSecurity($controller, $user);

        // 1. Empty IDs array -> 422
        $reqEmpty = new Request([], [], [], [], [], [], json_encode(['ids' => []]));
        $resEmpty = $controller->batchRead($reqEmpty);
        $this->assertSame(422, $resEmpty->getStatusCode());
        $dataEmpty = json_decode((string) $resEmpty->getContent(), true);
        $this->assertSame('BATCH_EMPTY', $dataEmpty['code']);

        // 2. Too many IDs (> 100) -> 422
        $tooManyIds = range(1, 101);
        $reqTooMany = new Request([], [], [], [], [], [], json_encode(['ids' => $tooManyIds]));
        $resTooMany = $controller->batchRead($reqTooMany);
        $this->assertSame(422, $resTooMany->getStatusCode());
        $dataTooMany = json_decode((string) $resTooMany->getContent(), true);
        $this->assertSame('BATCH_TOO_LARGE', $dataTooMany['code']);

        // 3. Invalid non-integer IDs -> 422
        $reqInvalid = new Request([], [], [], [], [], [], json_encode(['ids' => [1, 'invalid_id', 3]]));
        $resInvalid = $controller->batchRead($reqInvalid);
        $this->assertSame(422, $resInvalid->getStatusCode());
        $dataInvalid = json_decode((string) $resInvalid->getContent(), true);
        $this->assertSame('INVALID_ID_LIST', $dataInvalid['code']);

        // 4. Valid batch read -> 200
        $notifRepo->expects($this->once())->method('markBatchAsRead')->with($user, [1, 2, 3])->willReturn(3);
        $notifRepo->method('countUnreadByUser')->willReturn(2);

        $reqValid = new Request([], [], [], [], [], [], json_encode(['ids' => [1, 2, 3]]));
        $resValid = $controller->batchRead($reqValid);
        $this->assertSame(200, $resValid->getStatusCode());
        $dataValid = json_decode((string) $resValid->getContent(), true);
        $this->assertTrue($dataValid['success']);
        $this->assertSame(3, $dataValid['updated']);
        $this->assertSame(2, $dataValid['unread_count']);
    }

    public function testNotificationBatchDeleteValidation(): void
    {
        $notifRepo = $this->createMock(NotificationRepository::class);
        $user = $this->createMockUser();

        $controller = new NotificationController(
            $notifRepo,
            null,
            null,
            null,
            $this->createTranslator()
        );
        $this->setupControllerSecurity($controller, $user);

        // 1. Empty list -> 422
        $reqEmpty = new Request([], [], [], [], [], [], json_encode(['ids' => []]));
        $resEmpty = $controller->batchDelete($reqEmpty);
        $this->assertSame(422, $resEmpty->getStatusCode());

        // 2. Valid batch deletion -> 200
        $notifRepo->expects($this->once())->method('deleteBatch')->with($user, [5, 6])->willReturn(2);

        $reqValid = new Request([], [], [], [], [], [], json_encode(['ids' => [5, 6]]));
        $resValid = $controller->batchDelete($reqValid);
        $this->assertSame(200, $resValid->getStatusCode());
        $dataValid = json_decode((string) $resValid->getContent(), true);
        $this->assertTrue($dataValid['success']);
        $this->assertSame(2, $dataValid['deleted']);
    }

    public function testNotificationPruneValidation(): void
    {
        $notifRepo = $this->createMock(NotificationRepository::class);
        $user = $this->createMockUser();

        $controller = new NotificationController(
            $notifRepo,
            null,
            null,
            null,
            $this->createTranslator()
        );
        $this->setupControllerSecurity($controller, $user);

        // 1. Invalid days (< 1) -> 422
        $reqNegative = new Request([], [], [], [], [], [], json_encode(['days' => 0]));
        $resNegative = $controller->prune($reqNegative);
        $this->assertSame(422, $resNegative->getStatusCode());
        $dataNegative = json_decode((string) $resNegative->getContent(), true);
        $this->assertSame('INVALID_DAYS', $dataNegative['code']);

        // 2. Invalid days (> 365) -> 422
        $reqExcess = new Request([], [], [], [], [], [], json_encode(['days' => 500]));
        $resExcess = $controller->prune($reqExcess);
        $this->assertSame(422, $resExcess->getStatusCode());

        // 3. Valid days -> 200
        $notifRepo->expects($this->once())->method('pruneReadOlderThanForUser')->willReturn(15);
        $reqValid = new Request([], [], [], [], [], [], json_encode(['days' => 60]));
        $resValid = $controller->prune($reqValid);
        $this->assertSame(200, $resValid->getStatusCode());
        $dataValid = json_decode((string) $resValid->getContent(), true);
        $this->assertTrue($dataValid['success']);
        $this->assertSame(15, $dataValid['pruned']);
        $this->assertSame(60, $dataValid['days']);
    }

    // ════════════════════ 6. NotificationService Sanitization & Auto-Pruning ════════════════════

    public function testNotificationServiceAutoPrunesWhenUserQuotaExceeded(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $notifRepo = $this->createMock(NotificationRepository::class);
        $recipient = $this->createMockUser(42);

        // Recipient already has 1000 notifications (max limit)
        $notifRepo->method('countByRecipient')->with($recipient)->willReturn(1000);
        // Expects pruneOldestForUser to be called to keep count below 1000
        $notifRepo->expects($this->once())->method('pruneOldestForUser')->with($recipient, 999)->willReturn(1);

        $service = new NotificationService($em, $this->createTranslator(), new \Psr\Log\NullLogger(), $notifRepo);

        $notif = $service->create(
            recipient: $recipient,
            type: 'comment',
            title: 'New Comment Notification',
            body: 'Comment payload',
            link: '/projects/123/content/10',
            flush: false
        );

        $this->assertInstanceOf(Notification::class, $notif);
        $this->assertSame('New Comment Notification', $notif->title);
    }

    public function testNotificationServiceSanitizesPayloads(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $recipient = $this->createMockUser(42);

        $service = new NotificationService($em, $this->createTranslator(), new \Psr\Log\NullLogger());

        // Title containing script/HTML
        $dirtyTitle = '<b>Important</b> <script>alert(1)</script> Alert!';
        // Body containing iframe and event handlers
        $dirtyBody = 'Look here <iframe src="evil.com"></iframe> <p onclick="evil()">Click</p>';
        // Dangerous link
        $dangerousLink = 'javascript:alert(document.cookie)';

        $notif = $service->create(
            recipient: $recipient,
            type: 'automation',
            title: $dirtyTitle,
            body: $dirtyBody,
            link: $dangerousLink,
            flush: false
        );

        // Title should have all HTML tags stripped
        $this->assertSame('Important  Alert!', $notif->title);
        // Body should have iframe and event handlers stripped
        $this->assertSame('Look here  <p>Click</p>', $notif->body);
        // Dangerous link should be neutralized to empty string or sanitized fallback
        $this->assertStringNotContainsString('javascript:', $notif->link);
    }

    // ════════════════════ 7. I18n Parity Verification ════════════════════

    public function testTranslationsParityAcrossAllLanguages(): void
    {
        $en = require __DIR__ . '/../../translations/messages.en.php';
        $fr = require __DIR__ . '/../../translations/messages.fr.php';
        $es = require __DIR__ . '/../../translations/messages.es.php';
        $ar = require __DIR__ . '/../../translations/messages.ar.php';

        $this->assertCount(count($en), $fr, 'FR translations must have exact same key count as EN');
        $this->assertCount(count($en), $es, 'ES translations must have exact same key count as EN');
        $this->assertCount(count($en), $ar, 'AR translations must have exact same key count as EN');

        $this->assertEmpty(array_diff_key($en, $fr), 'FR has missing keys compared to EN');
        $this->assertEmpty(array_diff_key($en, $es), 'ES has missing keys compared to EN');
        $this->assertEmpty(array_diff_key($en, $ar), 'AR has missing keys compared to EN');

        // Verify specific Cycle 90 keys exist in EN
        $cycle90Keys = [
            'comment.entry_quota_exceeded',
            'comment.project_quota_exceeded',
            'comment.parent_entry_mismatch',
            'comment.invalid_parent_cycle',
            'comment.max_depth_exceeded',
            'comment.body_invalid_characters',
            'notification.default_title',
            'notification.invalid_json',
            'notification.payload_too_large',
            'notification.batch_empty',
            'notification.batch_too_large',
            'notification.invalid_id_list',
            'notification.pruned_success',
            'notification.batch_read_success',
            'notification.batch_delete_success',
            'notification.quota_exceeded',
            'notification.invalid_days',
        ];

        foreach ($cycle90Keys as $key) {
            $this->assertArrayHasKey($key, $en, "Missing Cycle 90 key in EN: {$key}");
            $this->assertArrayHasKey($key, $fr, "Missing Cycle 90 key in FR: {$key}");
            $this->assertArrayHasKey($key, $es, "Missing Cycle 90 key in ES: {$key}");
            $this->assertArrayHasKey($key, $ar, "Missing Cycle 90 key in AR: {$key}");
        }
    }
}
