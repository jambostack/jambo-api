<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\CollectionTemplateController;
use App\Controller\ColumnSettingController;
use App\Controller\CommentController;
use App\Controller\Public\SeoPublicController;
use App\Entity\Collection;
use App\Entity\CollectionTemplate;
use App\Entity\Comment;
use App\Entity\ContentEntry;
use App\Entity\Field;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\CollectionRepository;
use App\Repository\CollectionTemplateRepository;
use App\Repository\ColumnSettingRepository;
use App\Repository\ContentEntryRepository;
use App\Repository\CommentRepository;
use App\Repository\ProjectRepository;
use App\Service\Seo\SitemapGenerator;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Cycle 23 — Hardening Tests
 *
 * Covers:
 *  1. CommentController multi-tenant authorization (project.view on index, content.update on create/update/resolve)
 *  2. ColumnSettingController access control (project.view on show and update)
 *  3. CollectionTemplateController admin restriction (ROLE_ADMIN) & field limit capping (max 100)
 *  4. SitemapGenerator strict XML escaping (ENT_XML1) and anti-CRLF URL defense
 *  5. SeoPublicController anti-CRLF defense in robots.txt
 */
class Cycle23HardeningTest extends TestCase
{
    private function createContainer(
        ?User $user = null,
        bool $isGranted = true,
        ?callable $isGrantedCallback = null
    ): Container {
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
        if ($isGrantedCallback !== null) {
            $authChecker->method('isGranted')->willReturnCallback($isGrantedCallback);
        } else {
            $authChecker->method('isGranted')->willReturn($isGranted);
        }
        $container->set('security.authorization_checker', $authChecker);

        return $container;
    }

    // ─────────────────────────────────────────────────────────────────
    // 1. CommentController Authorization Tests
    // ─────────────────────────────────────────────────────────────────

    public function testCommentIndexDeniesAccessWithoutProjectView(): void
    {
        $projectUuidStr = '00000000-0000-0000-0000-000000000001';
        $project = new Project();
        $project->uuid = Uuid::fromString($projectUuidStr);

        $collection = new Collection();
        $collection->slug = 'articles';
        $collection->project = $project;

        $entry = new ContentEntry();
        $entry->id = 42;
        $entry->project = $project;
        $entry->collection = $collection;

        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $entryRepo->expects($this->once())->method('find')->with(42)->willReturn($entry);

        $controller = new CommentController(
            $this->createMock(ProjectRepository::class),
            $this->createMock(CollectionRepository::class),
            $entryRepo,
            $this->createMock(CommentRepository::class),
            $this->createMock(EntityManagerInterface::class)
        );

        // Deny 'project.view'
        $controller->setContainer($this->createContainer(new User(), false));

        $this->expectException(AccessDeniedException::class);
        $controller->index($projectUuidStr, 'articles', 42, new Request());
    }

    public function testCommentCreateDeniesAccessWithoutContentUpdate(): void
    {
        $projectUuidStr = '00000000-0000-0000-0000-000000000001';
        $project = new Project();
        $project->uuid = Uuid::fromString($projectUuidStr);

        $collection = new Collection();
        $collection->slug = 'articles';
        $collection->project = $project;

        $entry = new ContentEntry();
        $entry->id = 42;
        $entry->project = $project;
        $entry->collection = $collection;

        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $entryRepo->expects($this->once())->method('find')->with(42)->willReturn($entry);

        $controller = new CommentController(
            $this->createMock(ProjectRepository::class),
            $this->createMock(CollectionRepository::class),
            $entryRepo,
            $this->createMock(CommentRepository::class),
            $this->createMock(EntityManagerInterface::class)
        );

        $user = new User();
        // Deny 'content.update'
        $controller->setContainer($this->createContainer($user, false));

        $req = new Request([], [], [], [], [], [], json_encode(['body' => 'Hello test']));

        $this->expectException(AccessDeniedException::class);
        $controller->create($projectUuidStr, 'articles', 42, $req);
    }

    public function testCommentUpdateDeniesAccessWithoutContentUpdate(): void
    {
        $projectUuidStr = '00000000-0000-0000-0000-000000000001';
        $project = new Project();
        $project->uuid = Uuid::fromString($projectUuidStr);

        $collection = new Collection();
        $collection->slug = 'articles';
        $collection->project = $project;

        $entry = new ContentEntry();
        $entry->id = 42;
        $entry->project = $project;
        $entry->collection = $collection;

        $user = new User();
        $user->id = 10;

        $comment = new Comment();
        $comment->id = 100;
        $comment->entry = $entry;
        $comment->author = $user;

        $commentRepo = $this->createMock(CommentRepository::class);
        $commentRepo->expects($this->once())->method('find')->with(100)->willReturn($comment);

        $controller = new CommentController(
            $this->createMock(ProjectRepository::class),
            $this->createMock(CollectionRepository::class),
            $this->createMock(ContentEntryRepository::class),
            $commentRepo,
            $this->createMock(EntityManagerInterface::class)
        );

        // Deny 'content.update'
        $controller->setContainer($this->createContainer($user, false));

        $req = new Request([], [], [], [], [], [], json_encode(['body' => 'Updated comment']));

        $this->expectException(AccessDeniedException::class);
        $controller->update($projectUuidStr, 'articles', 42, 100, $req);
    }

    public function testCommentResolveDeniesAccessWithoutContentUpdate(): void
    {
        $projectUuidStr = '00000000-0000-0000-0000-000000000001';
        $project = new Project();
        $project->uuid = Uuid::fromString($projectUuidStr);

        $collection = new Collection();
        $collection->slug = 'articles';
        $collection->project = $project;

        $entry = new ContentEntry();
        $entry->id = 42;
        $entry->project = $project;
        $entry->collection = $collection;

        $user = new User();
        $user->id = 10;

        $comment = new Comment();
        $comment->id = 100;
        $comment->entry = $entry;

        $commentRepo = $this->createMock(CommentRepository::class);
        $commentRepo->expects($this->once())->method('find')->with(100)->willReturn($comment);

        $controller = new CommentController(
            $this->createMock(ProjectRepository::class),
            $this->createMock(CollectionRepository::class),
            $this->createMock(ContentEntryRepository::class),
            $commentRepo,
            $this->createMock(EntityManagerInterface::class)
        );

        // Deny 'content.update'
        $controller->setContainer($this->createContainer($user, false));

        $req = new Request([], [], [], [], [], [], json_encode(['status' => 'resolved']));

        $this->expectException(AccessDeniedException::class);
        $controller->resolve($projectUuidStr, 'articles', 42, 100, $req);
    }

    // ─────────────────────────────────────────────────────────────────
    // 2. ColumnSettingController Authorization Tests
    // ─────────────────────────────────────────────────────────────────

    public function testColumnSettingShowAndUpdateDenyAccessWithoutProjectView(): void
    {
        $projectUuidStr = '00000000-0000-0000-0000-000000000001';
        $project = new Project();
        $project->uuid = Uuid::fromString($projectUuidStr);

        $collection = new Collection();
        $collection->slug = 'posts';
        $collection->project = $project;

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $collectionRepo = $this->createMock(CollectionRepository::class);
        $collectionRepo->method('findOneByProjectAndSlug')->willReturn($collection);

        $controller = new ColumnSettingController(
            $projectRepo,
            $collectionRepo,
            $this->createMock(ColumnSettingRepository::class),
            $this->createMock(EntityManagerInterface::class)
        );

        $user = new User();
        $controller->setContainer($this->createContainer($user, false));

        // Test show denies access
        try {
            $controller->show($projectUuidStr, 'posts');
            $this->fail('Expected AccessDeniedException on show');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(AccessDeniedException::class, $e);
        }

        // Test update denies access
        try {
            $req = new Request([], [], [], [], [], [], json_encode(['visible_columns' => ['title']]));
            $controller->update($projectUuidStr, 'posts', $req);
            $this->fail('Expected AccessDeniedException on update');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(AccessDeniedException::class, $e);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // 3. CollectionTemplateController Tests
    // ─────────────────────────────────────────────────────────────────

    public function testCollectionTemplateCreateRequiresAdminRole(): void
    {
        $controller = new CollectionTemplateController(
            $this->createMock(CollectionTemplateRepository::class),
            $this->createMock(CollectionRepository::class),
            $this->createMock(ProjectRepository::class),
            $this->createMock(EntityManagerInterface::class)
        );

        $user = new User();
        $user->roles = ['ROLE_USER'];

        // isGranted returns false for ROLE_ADMIN
        $controller->setContainer($this->createContainer($user, false));

        $req = new Request([], [], [], [], [], [], json_encode(['name' => 'Custom Template']));

        $this->expectException(AccessDeniedException::class);
        $controller->create($req);
    }

    public function testCollectionTemplateCreateRejectsExcessiveFields(): void
    {
        $controller = new CollectionTemplateController(
            $this->createMock(CollectionTemplateRepository::class),
            $this->createMock(CollectionRepository::class),
            $this->createMock(ProjectRepository::class),
            $this->createMock(EntityManagerInterface::class)
        );

        $admin = new User();
        $admin->roles = ['ROLE_ADMIN'];

        $controller->setContainer($this->createContainer($admin, true));

        // Generate 101 fields
        $fields = [];
        for ($i = 0; $i < 101; $i++) {
            $fields[] = [
                'name' => "Field $i",
                'slug' => "field_$i",
                'type' => 'text',
            ];
        }

        $req = new Request([], [], [], [], [], [], json_encode([
            'name' => 'Massive Template',
            'fields' => $fields,
        ]));

        $res = $controller->create($req);
        $this->assertSame(422, $res->getStatusCode());

        $data = json_decode((string) $res->getContent(), true);
        $this->assertSame('A template cannot have more than 100 fields.', $data['error']);
    }

    public function testCollectionTemplateSaveFromCollectionRejectsExcessiveFields(): void
    {
        $projectUuidStr = '00000000-0000-0000-0000-000000000001';
        $project = new Project();
        $project->uuid = Uuid::fromString($projectUuidStr);

        $collection = new Collection();
        $collection->slug = 'huge-collection';
        $collection->project = $project;

        for ($i = 0; $i < 105; $i++) {
            $f = new Field();
            $f->name = "Field $i";
            $f->slug = "field_$i";
            $f->type = 'text';
            $collection->fields->add($f);
        }

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $collectionRepo = $this->createMock(CollectionRepository::class);
        $collectionRepo->method('findOneByProjectAndSlug')->willReturn($collection);

        $controller = new CollectionTemplateController(
            $this->createMock(CollectionTemplateRepository::class),
            $collectionRepo,
            $projectRepo,
            $this->createMock(EntityManagerInterface::class)
        );

        $admin = new User();
        $controller->setContainer($this->createContainer($admin, true));

        $req = new Request();
        $res = $controller->saveFromCollection($projectUuidStr, 'huge-collection', $req);

        $this->assertSame(422, $res->getStatusCode());
        $data = json_decode((string) $res->getContent(), true);
        $this->assertSame('A template cannot have more than 100 fields.', $data['error']);
    }

    // ─────────────────────────────────────────────────────────────────
    // 4. SitemapGenerator Strict XML Escaping & Anti-CRLF Tests
    // ─────────────────────────────────────────────────────────────────

    public function testSitemapGeneratorEscapesXmlSpecialCharacters(): void
    {
        $project = new Project();
        $project->uuid = Uuid::v4();
        $project->previewUrl = 'https://example.com';

        $collection = new Collection();
        $collection->slug = 'test-slug&evil<>';
        $collection->settings = [
            'seo' => [
                'sitemapChangefreq' => 'weekly<tag>&bad',
                'sitemapPriority' => '0.8"\'',
            ],
        ];

        $entry = new ContentEntry();
        $entry->slug = 'article&param=1<tag>';
        $entry->updatedAt = new \DateTimeImmutable('2026-11-04');

        $collectionRepo = $this->createMock(EntityRepository::class);
        $collectionRepo->method('findBy')->willReturn([$collection]);

        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $entryRepo->method('findByCollectionPaginated')->willReturn([$entry]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('getRepository')->with(Collection::class)->willReturn($collectionRepo);

        $generator = new SitemapGenerator($em, $entryRepo);
        $xml = $generator->generateSitemap($project);

        // Raw unescaped dangerous strings must NOT appear
        $this->assertStringNotContainsString('<tag>', $xml);
        $this->assertStringNotContainsString('&param', $xml);
        $this->assertStringNotContainsString('&evil', $xml);

        // Strict XML1 entities must be present
        $this->assertStringContainsString('&amp;evil&lt;&gt;', $xml);
        $this->assertStringContainsString('&amp;param=1&lt;tag&gt;', $xml);
        $this->assertStringContainsString('&lt;tag&gt;&amp;bad', $xml);
        $this->assertStringContainsString('0.8&quot;&apos;', $xml);
    }

    public function testSitemapGeneratorSanitizesCrlfInBaseUrl(): void
    {
        $project = new Project();
        $project->uuid = Uuid::v4();
        $project->previewUrl = "https://example.com\r\ninjected\r\n";

        $collection = new Collection();
        $collection->slug = 'news';

        $entry = new ContentEntry();
        $entry->slug = 'item-1';
        $entry->updatedAt = new \DateTimeImmutable('2026-11-04');

        $collectionRepo = $this->createMock(EntityRepository::class);
        $collectionRepo->method('findBy')->willReturn([$collection]);

        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $entryRepo->method('findByCollectionPaginated')->willReturn([$entry]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('getRepository')->with(Collection::class)->willReturn($collectionRepo);

        $generator = new SitemapGenerator($em, $entryRepo);
        $xml = $generator->generateSitemap($project);

        $this->assertStringNotContainsString("\r", $xml);
        $this->assertStringContainsString('<loc>https://example.cominjected/news/item-1</loc>', $xml);
    }

    // ─────────────────────────────────────────────────────────────────
    // 5. SeoPublicController Anti-CRLF Robots.txt Tests
    // ─────────────────────────────────────────────────────────────────

    public function testRobotsTxtStripsCrlfAndRefusesInvalidUrl(): void
    {
        $projectUuidStr = '00000000-0000-0000-0000-000000000001';
        $project = new Project();
        $project->name = 'Test Project';
        $project->uuid = Uuid::fromString($projectUuidStr);
        $project->previewUrl = "https://site.example.com\r\nDisallow: /admin\r\n";
        $project->settings = [
            'seo' => [
                'enableSitemap' => true,
            ],
        ];

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $controller = new SeoPublicController($projectRepo, $this->createMock(SitemapGenerator::class));

        $res = $controller->robots($projectUuidStr . "\r\n");
        $content = $res->getContent();

        $this->assertIsString($content);
        $this->assertStringNotContainsString("\r", $content);
        // Ensure CRLF and space injection failed validation and safely defaulted to https://example.com
        $this->assertStringNotContainsString("Disallow: /admin", $content);
        $this->assertStringContainsString("Sitemap: https://example.com/{$projectUuidStr}/sitemap.xml\n", $content);
    }
}
