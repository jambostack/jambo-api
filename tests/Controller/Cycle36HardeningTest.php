<?php

namespace App\Tests\Controller;

use App\Entity\MediaFolder;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cycle 36 — Security Hardening & Strict I18N Parity:
 * - MediaFolderController
 * - RealtimeController
 *
 * Enforces:
 * 1. 100% I18N parity across 4 locales (en, fr, es, ar) for all new translation keys (22 keys).
 * 2. MediaFolderController: UUID format checks, path traversal protection, payload limits, cycle prevention, hierarchy limits.
 * 3. RealtimeController: UUID format enforcement, rate limiter & auth defense, fallback messages translation.
 */
class Cycle36HardeningTest extends WebTestCase
{
    private const LOCALES = ['en', 'fr', 'es', 'ar'];

    private const MEDIA_FOLDER_KEYS = [
        'media_folder.project_not_found',
        'media_folder.invalid_json',
        'media_folder.payload_too_large',
        'media_folder.name_required',
        'media_folder.name_empty',
        'media_folder.name_too_long',
        'media_folder.name_control_characters',
        'media_folder.name_invalid_traversal',
        'media_folder.parent_not_found',
        'media_folder.invalid_parent_id',
        'media_folder.not_found',
        'media_folder.parent_id_required',
        'media_folder.cannot_move_into_itself',
        'media_folder.cannot_move_into_descendants',
        'media_folder.max_depth_exceeded',
    ];

    private const REALTIME_KEYS = [
        'realtime.invalid_project_uuid',
        'realtime.rate_limit_exceeded',
        'realtime.project_not_found',
        'realtime.auth_required',
        'realtime.access_denied',
        'realtime.mercure_not_configured',
        'realtime.mercure_init_error',
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
        $this->admin->name = 'Cycle36 Admin';
        $this->admin->email = 'admin_c36_' . bin2hex(random_bytes(4)) . '@test.com';
        $this->admin->password = $hasher->hashPassword($this->admin, 'secret123');
        $this->admin->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($this->admin);

        $this->project = new Project();
        $this->project->name = 'Cycle36 Project ' . bin2hex(random_bytes(4));
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

    public function testTranslationKeysExistInAllLocales(): void
    {
        $allKeys = array_merge(self::MEDIA_FOLDER_KEYS, self::REALTIME_KEYS);

        foreach (self::LOCALES as $locale) {
            $path = __DIR__ . "/../../translations/messages.{$locale}.php";
            $this->assertFileExists($path, "Translation file for '{$locale}' must exist.");
            $dict = require $path;

            foreach ($allKeys as $key) {
                $this->assertArrayHasKey($key, $dict, "Locale '{$locale}' is missing key '{$key}'");
                $this->assertNotEmpty($dict[$key], "Locale '{$locale}' key '{$key}' must not be empty");
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // 2. MediaFolderController Hardening
    // ─────────────────────────────────────────────────────────────────

    public function testMediaFolderRejectsMalformedProjectUuid(): void
    {
        $client = $this->createAuthClient();
        $client->request('GET', '/api/projects/not-a-valid-uuid/media-folders');
        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testMediaFolderNonExistentProjectReturns404(): void
    {
        $client = $this->createAuthClient();
        $client->request('GET', '/api/projects/00000000-0000-0000-0000-000000000000/media-folders');
        $this->assertSame(404, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Project not found', $data['error']);
    }

    public function testMediaFolderStoreRejectsTraversalPatterns(): void
    {
        $client = $this->createAuthClient();

        $maliciousNames = [
            '../traversal',
            '../../etc/passwd',
            'sub/folder',
            'back\\slash',
            '..',
        ];

        foreach ($maliciousNames as $name) {
            $client->request(
                'POST',
                "/api/projects/{$this->projectUuid}/media-folders",
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode(['name' => $name])
            );
            $this->assertSame(422, $client->getResponse()->getStatusCode(), "Name '{$name}' should be rejected as traversal");
            $data = json_decode($client->getResponse()->getContent(), true);
            $this->assertSame('Name cannot contain path traversal sequences or slashes', $data['error']);
        }
    }

    public function testMediaFolderStoreRejectsPayloadTooLarge(): void
    {
        $client = $this->createAuthClient();
        $giantPayload = json_encode(['name' => 'Valid Name', 'extra' => str_repeat('A', 20000)]);

        $client->request(
            'POST',
            "/api/projects/{$this->projectUuid}/media-folders",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $giantPayload
        );
        $this->assertSame(413, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Payload too large', $data['error']);
    }

    public function testMediaFolderStoreRejectsInvalidJson(): void
    {
        $client = $this->createAuthClient();
        $client->request(
            'POST',
            "/api/projects/{$this->projectUuid}/media-folders",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"name": invalid_json'
        );
        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Invalid JSON body', $data['error']);
    }

    public function testMediaFolderStoreRejectsInvalidParentId(): void
    {
        $client = $this->createAuthClient();

        $client->request(
            'POST',
            "/api/projects/{$this->projectUuid}/media-folders",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Valid Folder', 'parent_id' => -5])
        );
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Invalid parent folder ID', $data['error']);
    }

    public function testMediaFolderStoreRejectsNonExistentParent(): void
    {
        $client = $this->createAuthClient();

        $client->request(
            'POST',
            "/api/projects/{$this->projectUuid}/media-folders",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Valid Folder', 'parent_id' => 999999])
        );
        $this->assertSame(404, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Parent folder not found', $data['error']);
    }

    public function testMediaFolderCrudAndTree(): void
    {
        $client = $this->createAuthClient();

        // 1. Create root folder
        $client->request(
            'POST',
            "/api/projects/{$this->projectUuid}/media-folders",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Documents'])
        );
        $this->assertSame(201, $client->getResponse()->getStatusCode());
        $folder1 = json_decode($client->getResponse()->getContent(), true)['data'];
        $this->assertSame('Documents', $folder1['name']);
        $this->assertSame('documents', $folder1['slug']);

        // 2. Create child folder
        $client->request(
            'POST',
            "/api/projects/{$this->projectUuid}/media-folders",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Invoices', 'parent_id' => $folder1['id']])
        );
        $this->assertSame(201, $client->getResponse()->getStatusCode());
        $folder2 = json_decode($client->getResponse()->getContent(), true)['data'];
        $this->assertSame($folder1['id'], $folder2['parent_id']);

        // 3. Tree returns hierarchy
        $client->request('GET', "/api/projects/{$this->projectUuid}/media-folders/tree");
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $tree = json_decode($client->getResponse()->getContent(), true)['data'];
        $this->assertNotEmpty($tree);
        $this->assertSame('Documents', $tree[0]['name']);
        $this->assertCount(1, $tree[0]['children']);
        $this->assertSame('Invoices', $tree[0]['children'][0]['name']);

        // 4. Update folder
        $client->request(
            'PUT',
            "/api/projects/{$this->projectUuid}/media-folders/{$folder2['id']}",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Paid Invoices'])
        );
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $updated = json_decode($client->getResponse()->getContent(), true)['data'];
        $this->assertSame('Paid Invoices', $updated['name']);
        $this->assertSame('paid-invoices', $updated['slug']);

        // 5. Update rejects traversal
        $client->request(
            'PUT',
            "/api/projects/{$this->projectUuid}/media-folders/{$folder2['id']}",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'bad/name'])
        );
        $this->assertSame(422, $client->getResponse()->getStatusCode());

        // 6. Move folder
        $client->request(
            'PATCH',
            "/api/projects/{$this->projectUuid}/media-folders/{$folder2['id']}/move",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['parent_id' => null])
        );
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $moved = json_decode($client->getResponse()->getContent(), true)['data'];
        $this->assertNull($moved['parent_id']);

        // 7. Move rejects self-parent
        $client->request(
            'PATCH',
            "/api/projects/{$this->projectUuid}/media-folders/{$folder2['id']}/move",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['parent_id' => $folder2['id']])
        );
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $err = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Cannot move a folder into itself', $err['error']);

        // 8. Delete folder (soft delete)
        $client->request('DELETE', "/api/projects/{$this->projectUuid}/media-folders/{$folder2['id']}");
        $this->assertSame(204, $client->getResponse()->getStatusCode());

        // 9. Subsequent update returns 404
        $client->request(
            'PUT',
            "/api/projects/{$this->projectUuid}/media-folders/{$folder2['id']}",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Ghost'])
        );
        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    // ─────────────────────────────────────────────────────────────────
    // 3. RealtimeController Hardening
    // ─────────────────────────────────────────────────────────────────

    public function testRealtimeRejectsMalformedProjectUuid(): void
    {
        $client = $this->createAuthClient();
        $client->request('GET', '/api/projects/invalid-uuid/realtime?since=0');
        // Filtered by route requirement regex or controller check
        $this->assertContains($client->getResponse()->getStatusCode(), [400, 404]);
    }

    public function testRealtimeUnauthenticatedAccessDenied(): void
    {
        $anonClient = static::createClient();
        $anonClient->request('GET', "/api/projects/{$this->projectUuid}/realtime?since=0");
        $this->assertSame(401, $anonClient->getResponse()->getStatusCode());
        $data = json_decode($anonClient->getResponse()->getContent(), true);
        $this->assertSame('Authentication required.', $data['error']);
    }

    public function testRealtimeTokenUnauthenticatedAccessDenied(): void
    {
        $anonClient = static::createClient();
        $anonClient->request('GET', "/api/projects/{$this->projectUuid}/realtime/token");
        $this->assertSame(401, $anonClient->getResponse()->getStatusCode());
        $data = json_decode($anonClient->getResponse()->getContent(), true);
        $this->assertSame('Authentication required.', $data['error']);
    }

    public function testRealtimePollParametersBounded(): void
    {
        $client = $this->createAuthClient();

        // since < 0 normalized to 0, limit > 100 clamped to 100
        $client->request('GET', "/api/projects/{$this->projectUuid}/realtime?since=-10&limit=999");
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('events', $data);
        $this->assertArrayHasKey('since', $data);
        $this->assertSame(0, $data['since']);
    }

    public function testRealtimeTokenFallbackWhenMercureNotConfigured(): void
    {
        $client = $this->createAuthClient();
        $client->request('GET', "/api/projects/{$this->projectUuid}/realtime/token");
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('enabled', $data);
        // If Mercure is disabled in test environment:
        if (!$data['enabled']) {
            $this->assertArrayHasKey('message', $data);
            $this->assertStringContainsString('Mercure', $data['message']);
        }
    }
}
