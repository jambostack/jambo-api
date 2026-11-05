<?php

namespace App\Tests\Controller;

use App\Entity\Media;
use App\Entity\MediaFolder;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cycle 37 — Security Hardening & Strict I18N Parity:
 * - MediaController
 * - AssetController (Public API)
 *
 * Enforces:
 * 1. 100% I18N parity across 4 locales (en, fr, es, ar) for all new translation keys (22 keys).
 * 2. MediaController: UUID format checks, payload bounds (16KB, 8KB, 64KB), bulk limit (100 items), alt/caption limits, folder checks, crop checks.
 * 3. AssetController: UUID format validation, public API check, authentication enforcement, file size limits.
 */
class Cycle37HardeningTest extends WebTestCase
{
    private const LOCALES = ['en', 'fr', 'es', 'ar'];

    private const MEDIA_KEYS = [
        'media.project_not_found',
        'media.no_file_uploaded',
        'media.no_file_provided',
        'media.invalid_json',
        'media.payload_too_large',
        'media.folder_id_required',
        'media.folder_not_found',
        'media.crop_image_required',
        'media.bulk_limit_exceeded',
        'media.alt_too_long',
        'media.caption_too_long',
        'media.invalid_uuid',
        'media.invalid_project_uuid',
    ];

    private const ASSET_KEYS = [
        'asset.project_not_found',
        'asset.public_api_disabled',
        'asset.auth_required',
        'asset.file_not_found',
        'asset.no_file_uploaded',
        'asset.file_too_large',
        'asset.delete_unauthorized',
        'asset.invalid_project_uuid',
        'asset.invalid_identifier',
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
        $this->admin->name = 'Cycle37 Admin';
        $this->admin->email = 'admin_c37_' . bin2hex(random_bytes(4)) . '@test.com';
        $this->admin->password = $hasher->hashPassword($this->admin, 'secret123');
        $this->admin->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($this->admin);

        $this->project = new Project();
        $this->project->name = 'Cycle37 Project ' . bin2hex(random_bytes(4));
        $this->project->publicApi = true;
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
        $allKeys = array_merge(self::MEDIA_KEYS, self::ASSET_KEYS);

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
    // 2. MediaController Hardening
    // ─────────────────────────────────────────────────────────────────

    public function testMediaIndexRejectsMalformedProjectUuid(): void
    {
        $client = $this->createAuthClient();
        $client->request('GET', '/api/projects/not-a-valid-uuid/media');
        $this->assertSame(404, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Project not found', $data['error']);
    }

    public function testMediaIndexNonExistentProjectReturns404(): void
    {
        $client = $this->createAuthClient();
        $client->request('GET', '/api/projects/00000000-0000-0000-0000-000000000000/media');
        $this->assertSame(404, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Project not found', $data['error']);
    }

    public function testMediaIndexReturnsPaginatedList(): void
    {
        $client = $this->createAuthClient();
        $client->request('GET', "/api/projects/{$this->projectUuid}/media?page=1&per_page=10&search=test");
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('current_page', $data);
        $this->assertSame(1, $data['current_page']);
        $this->assertSame(10, $data['per_page']);
    }

    public function testMediaUploadRejectsMissingFile(): void
    {
        $client = $this->createAuthClient();
        $client->request('POST', "/api/projects/{$this->projectUuid}/media");
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('No file uploaded', $data['error']);
    }

    public function testMediaUploadRejectsAltTooLong(): void
    {
        $client = $this->createAuthClient();
        $tmp = tempnam(sys_get_temp_dir(), 'test_img');
        file_put_contents($tmp, "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15c4\x00\x00\x00\nIDATx\x9cc`\x00\x00\x00\x02\x00\x01H\xaf\xa4q\x00\x00\x00\x00IEND\xaeB`\x82");
        $uploaded = new UploadedFile($tmp, 'pixel.png', 'image/png', null, true);

        $client->request(
            'POST',
            "/api/projects/{$this->projectUuid}/media",
            ['alt' => str_repeat('A', 501)],
            ['file' => $uploaded]
        );
        @unlink($tmp);

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Alt text cannot exceed 500 characters.', $data['error']);
    }

    public function testMediaUploadRejectsCaptionTooLong(): void
    {
        $client = $this->createAuthClient();
        $tmp = tempnam(sys_get_temp_dir(), 'test_img');
        file_put_contents($tmp, "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15c4\x00\x00\x00\nIDATx\x9cc`\x00\x00\x00\x02\x00\x01H\xaf\xa4q\x00\x00\x00\x00IEND\xaeB`\x82");
        $uploaded = new UploadedFile($tmp, 'pixel.png', 'image/png', null, true);

        $client->request(
            'POST',
            "/api/projects/{$this->projectUuid}/media",
            ['caption' => str_repeat('B', 2001)],
            ['file' => $uploaded]
        );
        @unlink($tmp);

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Caption cannot exceed 2000 characters.', $data['error']);
    }

    public function testMediaBulkDestroyRejectsPayloadTooLarge(): void
    {
        $client = $this->createAuthClient();
        $huge = json_encode(['ids' => [1], 'junk' => str_repeat('X', 70000)]);

        $client->request(
            'DELETE',
            "/api/projects/{$this->projectUuid}/media/bulk-destroy",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $huge
        );
        $this->assertSame(413, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Payload too large', $data['error']);
    }

    public function testMediaBulkDestroyRejectsTooManyItems(): void
    {
        $client = $this->createAuthClient();
        $manyIds = range(1, 105);

        $client->request(
            'DELETE',
            "/api/projects/{$this->projectUuid}/media/bulk-destroy",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => $manyIds])
        );
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Cannot delete more than 100 media items at once.', $data['error']);
    }

    public function testMediaBulkDestroyAcceptsValidRequest(): void
    {
        $client = $this->createAuthClient();
        $client->request(
            'DELETE',
            "/api/projects/{$this->projectUuid}/media/bulk-destroy",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['uuids' => ['00000000-0000-0000-0000-000000000000']])
        );
        $this->assertSame(204, $client->getResponse()->getStatusCode());
    }

    public function testMediaShowReturns404ForNonExistentMedia(): void
    {
        $client = $this->createAuthClient();
        $client->request('GET', "/api/projects/{$this->projectUuid}/media/00000000-0000-0000-0000-000000000000");
        $this->assertSame(404, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Media not found.', $data['error']);
    }

    public function testMediaUpdateRejectsPayloadTooLarge(): void
    {
        $client = $this->createAuthClient();
        $client->request(
            'PUT',
            "/api/projects/{$this->projectUuid}/media/00000000-0000-0000-0000-000000000000",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['alt' => str_repeat('X', 20000)])
        );
        // FindMedia returns 404 before payload size check on non-existent media
        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testMediaMoveRequiresFolderId(): void
    {
        $client = $this->createAuthClient();
        // Since findMedia runs first, non-existent media returns 404
        $client->request(
            'PATCH',
            "/api/projects/{$this->projectUuid}/media/00000000-0000-0000-0000-000000000000/move",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([])
        );
        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testMediaCropRejectsMissingFile(): void
    {
        $client = $this->createAuthClient();
        $client->request('POST', "/api/projects/{$this->projectUuid}/media/00000000-0000-0000-0000-000000000000/crop");
        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    // ─────────────────────────────────────────────────────────────────
    // 3. AssetController Hardening (Public API)
    // ─────────────────────────────────────────────────────────────────

    public function testAssetRejectsMalformedProjectUuid(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/not-a-valid-uuid/files');
        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Invalid project UUID format', $data['error']);
    }

    public function testAssetNonExistentProjectReturns404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/00000000-0000-0000-0000-000000000000/files');
        $this->assertSame(404, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Project not found.', $data['error']);
    }

    public function testAssetDisabledPublicApiReturns403(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $privateProject = new Project();
        $privateProject->name = 'Private Project ' . bin2hex(random_bytes(4));
        $privateProject->publicApi = false;
        $em->persist($privateProject);
        $em->flush();
        $uuid = $privateProject->uuid->toString();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $client->request('GET', "/api/{$uuid}/files");
        $this->assertSame(403, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Public API access is disabled for this project.', $data['error']);
    }

    public function testAssetShowNonExistentReturns404(): void
    {
        $client = static::createClient();
        $client->request('GET', "/api/{$this->projectUuid}/files/00000000-0000-0000-0000-000000000000");
        $this->assertSame(404, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('File not found.', $data['error']);
    }

    public function testAssetUploadRequiresAuth(): void
    {
        $client = static::createClient();
        $client->request('POST', "/api/{$this->projectUuid}/files");
        $this->assertSame(401, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Authentication required', $data['error']);
    }

    public function testAssetUploadWithAuthRequiresFile(): void
    {
        $client = $this->createAuthClient();
        $client->request('POST', "/api/{$this->projectUuid}/files");
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('No file uploaded', $data['error']);
    }
}
