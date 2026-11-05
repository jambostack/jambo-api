<?php

namespace App\Tests\Controller;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\ContentVersion;
use App\Entity\Media;
use App\Entity\MediaFolder;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use App\Repository\ContentVersionRepository;
use App\Service\VersioningService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class MediaFolderAndVersioningHardeningTest extends WebTestCase
{
    private const LOCALES = ['en', 'fr', 'es', 'ar'];

    private const TRANSLATION_KEYS = [
        'media_folder.duplicate_name',
        'media_folder.reserved_name',
        'media_folder.circular_reference',
        'media_folder.max_depth_exceeded',
        'media_folder.name_invalid_traversal',
        'media_folder.name_empty',
        'versioning.version_deleted_successfully',
        'versioning.versions_pruned_successfully',
        'versioning.cannot_delete_last_version',
        'versioning.keep_invalid',
        'versioning.version_not_found',
        'versioning.entry_not_found',
        'versioning.invalid_version',
    ];

    private string $projectUuid;
    private User $admin;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $this->admin = new User();
        $this->admin->name = 'Phase89 Admin';
        $this->admin->email = 'admin_p89_' . bin2hex(random_bytes(4)) . '@test.com';
        $this->admin->password = $hasher->hashPassword($this->admin, 'secret123');
        $this->admin->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($this->admin);

        $this->project = new Project();
        $this->project->name = 'Phase89 Project ' . bin2hex(random_bytes(4));
        $this->project->publicApi = false;
        $em->persist($this->project);

        $member = new ProjectMember();
        $member->user = $this->admin;
        $member->project = $this->project;
        $member->status = ProjectMemberStatus::Active;
        $em->persist($member);

        $em->flush();

        $this->projectUuid = $this->project->uuid->toString();
        self::ensureKernelShutdown();
    }

    private function createAuthClient(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = static::createClient();
        $client->loginUser($this->admin);
        return $client;
    }

    // ─────────────────────────────────────────────────────────────────
    // 1. Translation Parity
    // ─────────────────────────────────────────────────────────────────

    public function testTranslationParityInAllLocales(): void
    {
        foreach (self::LOCALES as $locale) {
            $path = __DIR__ . "/../../translations/messages.{$locale}.php";
            $this->assertFileExists($path, "Translation file for '{$locale}' must exist.");
            $dict = require $path;

            foreach (self::TRANSLATION_KEYS as $key) {
                $this->assertArrayHasKey($key, $dict, "Locale '{$locale}' is missing key '{$key}'");
                $this->assertNotEmpty($dict[$key], "Locale '{$locale}' key '{$key}' must not be empty");
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // 2. MediaFolder Hardening Tests
    // ─────────────────────────────────────────────────────────────────

    public function testMediaFolderDuplicateSiblingNameRejection(): void
    {
        $client = $this->createAuthClient();

        // 1. Create root folder "Assets"
        $client->request(
            'POST',
            "/api/projects/{$this->projectUuid}/media-folders",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Assets'])
        );
        $this->assertSame(201, $client->getResponse()->getStatusCode());

        // 2. Creating another folder with identical name at root level must fail with DUPLICATE_NAME
        $client->request(
            'POST',
            "/api/projects/{$this->projectUuid}/media-folders",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'assets']) // case insensitive
        );
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('DUPLICATE_NAME', $data['code'] ?? null);
    }

    public function testMediaFolderReservedNamesRejection(): void
    {
        $client = $this->createAuthClient();
        $reserved = ['con', 'prn', 'aux', 'nul', 'com1', 'lpt1'];

        foreach ($reserved as $res) {
            $client->request(
                'POST',
                "/api/projects/{$this->projectUuid}/media-folders",
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode(['name' => $res])
            );
            $this->assertSame(422, $client->getResponse()->getStatusCode(), "Reserved name '{$res}' must be rejected");
        }
    }

    public function testMediaFolderTraversalDotRejection(): void
    {
        $client = $this->createAuthClient();

        $client->request(
            'POST',
            "/api/projects/{$this->projectUuid}/media-folders",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => '.'])
        );
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Name cannot contain path traversal sequences or slashes', $data['error']);
    }

    public function testMediaFolderSubtreeDepthCheckOnMove(): void
    {
        $client = $this->createAuthClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $project = $em->getRepository(Project::class)->findOneBy(['uuid' => $this->projectUuid]);

        // Build a chain of 14 folders under project
        $curr = null;
        for ($i = 1; $i <= 14; $i++) {
            $f = new MediaFolder();
            $f->name = "Level {$i}";
            $f->slug = "level-{$i}";
            $f->project = $project;
            $f->parent = $curr;
            $f->position = 1;
            $em->persist($f);
            $curr = $f;
        }

        // Build a separate 3-level tree: ParentSub -> ChildSub -> GrandChildSub
        $parentSub = new MediaFolder();
        $parentSub->name = "ParentSub";
        $parentSub->slug = "parent-sub";
        $parentSub->project = $project;
        $parentSub->position = 2;
        $em->persist($parentSub);

        $childSub = new MediaFolder();
        $childSub->name = "ChildSub";
        $childSub->slug = "child-sub";
        $childSub->project = $project;
        $childSub->parent = $parentSub;
        $childSub->position = 1;
        $em->persist($childSub);

        $grandChildSub = new MediaFolder();
        $grandChildSub->name = "GrandChildSub";
        $grandChildSub->slug = "grandchild-sub";
        $grandChildSub->project = $project;
        $grandChildSub->parent = $childSub;
        $grandChildSub->position = 1;
        $em->persist($grandChildSub);

        $em->flush();

        // Attempting to move parentSub (height 2) under $curr (depth 14) would produce depth 14 + 1 + 2 = 17 > 15
        $client->request(
            'PATCH',
            "/api/projects/{$this->projectUuid}/media-folders/{$parentSub->id}/move",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['parent_id' => $curr->id])
        );

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Maximum folder hierarchy depth exceeded', $data['error']);
    }

    public function testMediaFolderSoftDeleteDisassociatesMediaAndReparentsChildren(): void
    {
        $client = $this->createAuthClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $project = $em->getRepository(Project::class)->findOneBy(['uuid' => $this->projectUuid]);

        // Create parent folder
        $parent = new MediaFolder();
        $parent->name = "ToDelete";
        $parent->slug = "to-delete";
        $parent->project = $project;
        $em->persist($parent);

        // Create child folder
        $child = new MediaFolder();
        $child->name = "KeptChild";
        $child->slug = "kept-child";
        $child->project = $project;
        $child->parent = $parent;
        $em->persist($child);

        // Create media inside parent folder
        $media = new Media();
        $media->project = $project;
        $media->folder = $parent;
        $media->filename = 'test.jpg';
        $media->originalFilename = 'test.jpg';
        $media->mimeType = 'image/jpeg';
        $media->size = 1024;
        $media->storage = 'local';
        $media->path = 'uploads/test.jpg';
        $em->persist($media);

        $em->flush();
        $parentId = $parent->id;
        $childId = $child->id;
        $mediaId = $media->id;

        // Delete parent folder
        $client->request('DELETE', "/api/projects/{$this->projectUuid}/media-folders/{$parentId}");
        $this->assertSame(204, $client->getResponse()->getStatusCode());

        // Refresh entities from DB
        $em->clear();
        $reloadedParent = $em->getRepository(MediaFolder::class)->find($parentId);
        $reloadedChild = $em->getRepository(MediaFolder::class)->find($childId);
        $reloadedMedia = $em->getRepository(Media::class)->find($mediaId);

        $this->assertNotNull($reloadedParent->deletedAt, "Deleted folder must have deletedAt set");
        $this->assertNull($reloadedChild->parent, "Child folder must be safely re-parented to null");
        $this->assertNull($reloadedMedia->folder, "Media must be disassociated from deleted folder");
    }

    // ─────────────────────────────────────────────────────────────────
    // 3. Versioning Hardening Tests
    // ─────────────────────────────────────────────────────────────────

    private function createEntryWithVersions(): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $project = $em->getRepository(Project::class)->findOneBy(['uuid' => $this->projectUuid]);

        $col = new Collection();
        $col->name = 'Articles';
        $col->slug = 'articles';
        $col->project = $project;
        $em->persist($col);

        $entry = new ContentEntry();
        $entry->collection = $col;
        $entry->project = $project;
        $entry->status = 'published';
        $em->persist($entry);

        $v1 = new ContentVersion();
        $v1->contentEntry = $entry;
        $v1->versionNumber = 1;
        $v1->snapshot = ['title' => 'First Title', 'content' => 'Initial text'];
        $v1->label = 'v1 initial';
        $em->persist($v1);

        $v2 = new ContentVersion();
        $v2->contentEntry = $entry;
        $v2->versionNumber = 2;
        $v2->snapshot = ['title' => 'Second Title', 'content' => 'Updated text'];
        $v2->label = 'v2 updated';
        $em->persist($v2);

        $v3 = new ContentVersion();
        $v3->contentEntry = $entry;
        $v3->versionNumber = 3;
        $v3->snapshot = ['title' => 'Third Title', 'content' => 'Final text'];
        $v3->label = 'v3 final';
        $em->persist($v3);

        $em->flush();

        return [$entry, $col, [$v1, $v2, $v3]];
    }

    public function testVersioningDetailEndpoint(): void
    {
        $client = $this->createAuthClient();
        [$entry, $col, $versions] = $this->createEntryWithVersions();
        $entryUuid = $entry->uuid->toRfc4122();

        // 1. GET specific version detail
        $client->request('GET', "/api/projects/{$this->projectUuid}/collections/{$col->slug}/entries/{$entryUuid}/versions/2");
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(2, $data['versionNumber']);
        $this->assertSame('v2 updated', $data['label']);
        $this->assertSame('Second Title', $data['snapshot']['title'] ?? null);

        // 2. Non-existent version returns 404
        $client->request('GET', "/api/projects/{$this->projectUuid}/collections/{$col->slug}/entries/{$entryUuid}/versions/999");
        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testVersioningDiffEndpoint(): void
    {
        $client = $this->createAuthClient();
        [$entry, $col, $versions] = $this->createEntryWithVersions();
        $entryUuid = $entry->uuid->toRfc4122();

        // Compare v1 and v2
        $client->request('GET', "/api/projects/{$this->projectUuid}/collections/{$col->slug}/entries/{$entryUuid}/versions/diff?v1=1&v2=2");
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(1, $data['version1']);
        $this->assertSame(2, $data['version2']);
        $this->assertArrayHasKey('title', $data['changes']);
        $this->assertSame('First Title', $data['changes']['title']['from']);
        $this->assertSame('Second Title', $data['changes']['title']['to']);

        // Diff with non-existent version returns 404
        $client->request('GET', "/api/projects/{$this->projectUuid}/collections/{$col->slug}/entries/{$entryUuid}/versions/diff?v1=1&v2=999");
        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testVersioningDeleteAndCannotDeleteLastVersion(): void
    {
        $client = $this->createAuthClient();
        [$entry, $col, $versions] = $this->createEntryWithVersions();
        $entryUuid = $entry->uuid->toRfc4122();

        // 1. Delete version 2
        $client->request('DELETE', "/api/projects/{$this->projectUuid}/collections/{$col->slug}/entries/{$entryUuid}/versions/2");
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);

        // 2. Delete version 3
        $client->request('DELETE', "/api/projects/{$this->projectUuid}/collections/{$col->slug}/entries/{$entryUuid}/versions/3");
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        // 3. Attempting to delete version 1 (the last remaining version) must fail with 422
        $client->request('DELETE', "/api/projects/{$this->projectUuid}/collections/{$col->slug}/entries/{$entryUuid}/versions/1");
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $err = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Cannot delete the only existing version of an entry.', $err['error']);
    }

    public function testVersioningPruneEndpoint(): void
    {
        $client = $this->createAuthClient();
        [$entry, $col, $versions] = $this->createEntryWithVersions();
        $entryUuid = $entry->uuid->toRfc4122();

        // 1. Invalid keep parameter (< 1) returns 422
        $client->request('POST', "/api/projects/{$this->projectUuid}/collections/{$col->slug}/entries/{$entryUuid}/versions/prune?keep=0");
        $this->assertSame(422, $client->getResponse()->getStatusCode());

        // 2. Prune keeping 2 versions (should prune 1 out of 3)
        $client->request('POST', "/api/projects/{$this->projectUuid}/collections/{$col->slug}/entries/{$entryUuid}/versions/prune?keep=2");
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame(1, $data['pruned']);

        // 3. Verify remaining versions count via list endpoint
        $client->request('GET', "/api/projects/{$this->projectUuid}/collections/{$col->slug}/entries/{$entryUuid}/versions");
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $list = json_decode($client->getResponse()->getContent(), true);
        $this->assertCount(2, $list);
    }
}
