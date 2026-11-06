<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Permission;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\Role;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use App\Service\TusServer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Cycle #117 — Upload & TUS Security Hardening:
 * - Tus protocol checksum extension (Tus-Extension, Tus-Checksum-Algorithm, 400 invalid format/algo, 460 mismatch)
 * - Tus ownership isolation (non-owner cannot PATCH or DELETE upload without project.manage)
 * - Tus DELETE resumable version validation (412 for invalid version)
 * - Direct upload payload safety (preflight Content-Length, Content-Type 415, control char filenames, invalid modes)
 * - Direct upload cross-tenant isolation (403 ACCESS_DENIED)
 * - 4-locale i18n parity ({ar, en, es, fr}) for all Cycle #117 translation keys
 */
class Cycle117UploadAndTusSecurityHardeningTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $ownerUser;
    private User $otherUser;
    private User $adminUser;
    private Project $project;
    private string $projectUuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get(EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(4));

        $this->ownerUser = new User();
        $this->ownerUser->email = "tus_owner_{$suffix}@example.com";
        $this->ownerUser->password = 'hashed_pass_123';
        $this->ownerUser->roles = ['ROLE_USER'];
        $this->em->persist($this->ownerUser);

        $this->otherUser = new User();
        $this->otherUser->email = "tus_other_{$suffix}@example.com";
        $this->otherUser->password = 'hashed_pass_123';
        $this->otherUser->roles = ['ROLE_USER'];
        $this->em->persist($this->otherUser);

        $this->adminUser = new User();
        $this->adminUser->email = "tus_admin_{$suffix}@example.com";
        $this->adminUser->password = 'hashed_pass_123';
        $this->adminUser->roles = ['ROLE_SUPER_ADMIN'];
        $this->em->persist($this->adminUser);

        $this->project = new Project();
        $this->project->uuid = Uuid::v4();
        $this->project->name = "Tus Hardened Project C117 {$suffix}";
        $this->project->publicApi = true;
        $this->project->defaultLocale = 'en';
        $this->project->locales = ['en', 'fr'];
        $this->em->persist($this->project);

        $roleViewer = new Role();
        $roleViewer->name = "Viewer {$suffix}";
        $roleViewer->slug = "viewer_{$suffix}";
        $roleViewer->project = $this->project;

        foreach (['content.create', 'project.view'] as $pName) {
            $perm = $this->em->getRepository(Permission::class)->findOneBy(['name' => $pName]);
            if (!$perm) {
                $perm = new Permission();
                $perm->name = $pName;
                $this->em->persist($perm);
            }
            $roleViewer->permissions->add($perm);
        }
        $this->em->persist($roleViewer);

        $member1 = new ProjectMember();
        $member1->project = $this->project;
        $member1->user = $this->ownerUser;
        $member1->email = $this->ownerUser->email;
        $member1->role = $roleViewer;
        $member1->status = ProjectMemberStatus::Active;
        $member1->joinedAt = new \DateTimeImmutable();
        $this->em->persist($member1);

        $member2 = new ProjectMember();
        $member2->project = $this->project;
        $member2->user = $this->otherUser;
        $member2->email = $this->otherUser->email;
        $member2->role = $roleViewer;
        $member2->status = ProjectMemberStatus::Active;
        $member2->joinedAt = new \DateTimeImmutable();
        $this->em->persist($member2);

        $this->em->flush();
        $this->projectUuid = (string) $this->project->uuid;
    }

    public function testTusOptionsAdvertisesChecksumExtension(): void
    {
        $this->client->request('OPTIONS', "/api/projects/{$this->projectUuid}/files/tus");
        $response = $this->client->getResponse();

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('1.0.0', $response->headers->get('Tus-Resumable'));
        $this->assertStringContainsString('checksum', (string) $response->headers->get('Tus-Extension'));
        $this->assertSame('sha256,sha1,md5', $response->headers->get('Tus-Checksum-Algorithm'));
    }

    public function testTusChecksumValidationAndMismatch(): void
    {
        $this->client->loginUser($this->ownerUser);

        $validMeta = 'filename ' . base64_encode('checksum_test.txt');
        $this->client->request('POST', "/api/projects/{$this->projectUuid}/files/tus", [], [], [
            'HTTP_TUS_RESUMABLE'   => '1.0.0',
            'HTTP_UPLOAD_LENGTH'   => '24',
            'HTTP_UPLOAD_METADATA' => $validMeta,
        ]);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $location = (string) $this->client->getResponse()->headers->get('Location');
        $this->assertNotEmpty($location);

        $chunk = 'Hello TUS Checksum Test!';
        $this->assertSame(24, strlen($chunk));

        // 1. Invalid checksum format (missing hash)
        $this->client->request(
            'PATCH',
            $location,
            [],
            [],
            [
                'HTTP_TUS_RESUMABLE'  => '1.0.0',
                'HTTP_UPLOAD_OFFSET' => '0',
                'HTTP_UPLOAD_CHECKSUM' => 'sha256',
                'CONTENT_TYPE'        => 'application/offset+octet-stream',
            ],
            $chunk
        );
        $this->assertSame(400, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_CHECKSUM_FORMAT', $data['code'] ?? null);

        // 2. Unsupported checksum algorithm
        $this->client->request(
            'PATCH',
            $location,
            [],
            [],
            [
                'HTTP_TUS_RESUMABLE'  => '1.0.0',
                'HTTP_UPLOAD_OFFSET' => '0',
                'HTTP_UPLOAD_CHECKSUM' => 'blake2b ' . base64_encode('randomhash'),
                'CONTENT_TYPE'        => 'application/offset+octet-stream',
            ],
            $chunk
        );
        $this->assertSame(400, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('CHECKSUM_ALGORITHM_NOT_SUPPORTED', $data['code'] ?? null);

        // 3. Mismatched checksum
        $badHash = base64_encode(hash('sha256', 'completely different content', true));
        $this->client->request(
            'PATCH',
            $location,
            [],
            [],
            [
                'HTTP_TUS_RESUMABLE'  => '1.0.0',
                'HTTP_UPLOAD_OFFSET' => '0',
                'HTTP_UPLOAD_CHECKSUM' => "sha256 {$badHash}",
                'CONTENT_TYPE'        => 'application/offset+octet-stream',
            ],
            $chunk
        );
        $this->assertSame(460, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('CHECKSUM_MISMATCH', $data['code'] ?? null);

        // 4. Matching checksum succeeds
        $correctHash = base64_encode(hash('sha256', $chunk, true));
        $this->client->request(
            'PATCH',
            $location,
            [],
            [],
            [
                'HTTP_TUS_RESUMABLE'  => '1.0.0',
                'HTTP_UPLOAD_OFFSET' => '0',
                'HTTP_UPLOAD_CHECKSUM' => "sha256 {$correctHash}",
                'CONTENT_TYPE'        => 'application/offset+octet-stream',
            ],
            $chunk
        );
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->assertSame('24', $this->client->getResponse()->headers->get('Upload-Offset'));
    }

    public function testTusOwnershipIsolation(): void
    {
        $this->client->loginUser($this->ownerUser);

        $validMeta = 'filename ' . base64_encode('owner_test.txt');
        $this->client->request('POST', "/api/projects/{$this->projectUuid}/files/tus", [], [], [
            'HTTP_TUS_RESUMABLE'   => '1.0.0',
            'HTTP_UPLOAD_LENGTH'   => '10',
            'HTTP_UPLOAD_METADATA' => $validMeta,
        ]);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $location = (string) $this->client->getResponse()->headers->get('Location');

        // Other user attempts to PATCH
        $this->client->loginUser($this->otherUser);
        $this->client->request(
            'PATCH',
            $location,
            [],
            [],
            [
                'HTTP_TUS_RESUMABLE'  => '1.0.0',
                'HTTP_UPLOAD_OFFSET' => '0',
                'CONTENT_TYPE'        => 'application/offset+octet-stream',
            ],
            '0123456789'
        );
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('FORBIDDEN_NON_OWNER', $data['code'] ?? null);

        // Other user attempts to DELETE
        $this->client->request('DELETE', $location, [], [], [
            'HTTP_TUS_RESUMABLE' => '1.0.0',
        ]);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('FORBIDDEN_NON_OWNER', $data['code'] ?? null);

        // Admin can DELETE
        $this->client->loginUser($this->adminUser);
        $this->client->request('DELETE', $location, [], [], [
            'HTTP_TUS_RESUMABLE' => '1.0.0',
        ]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
    }

    public function testTusDeleteInvalidResumableVersion(): void
    {
        $this->client->loginUser($this->ownerUser);

        $validMeta = 'filename ' . base64_encode('version_test.txt');
        $this->client->request('POST', "/api/projects/{$this->projectUuid}/files/tus", [], [], [
            'HTTP_TUS_RESUMABLE'   => '1.0.0',
            'HTTP_UPLOAD_LENGTH'   => '10',
            'HTTP_UPLOAD_METADATA' => $validMeta,
        ]);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $location = (string) $this->client->getResponse()->headers->get('Location');

        // Invalid version returns 412
        $this->client->request('DELETE', $location, [], [], [
            'HTTP_TUS_RESUMABLE' => '9.9.9',
        ]);
        $this->assertSame(412, $this->client->getResponse()->getStatusCode());
        $this->assertSame('1.0.0', $this->client->getResponse()->headers->get('Tus-Version'));

        // Valid version deletes successfully
        $this->client->request('DELETE', $location, [], [], [
            'HTTP_TUS_RESUMABLE' => '1.0.0',
        ]);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());
    }

    public function testDirectUploadPayloadValidationAndSafety(): void
    {
        $this->client->loginUser($this->ownerUser);
        $url = "/api/projects/{$this->projectUuid}/media/direct-upload/intent";

        // 1. Content-Length header exceeding 512KB pre-flight
        $this->client->request('POST', $url, [], [], [
            'HTTP_CONTENT_LENGTH' => (string) (600 * 1024),
            'CONTENT_TYPE'        => 'application/json',
        ], json_encode(['filename' => 'large.png']));
        $this->assertSame(413, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('PAYLOAD_TOO_LARGE', $data['code'] ?? null);

        // 2. Unsupported Content-Type
        $this->client->request('POST', $url, [], [], [
            'CONTENT_TYPE' => 'text/plain',
        ], 'filename=test.png');
        $this->assertSame(415, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('UNSUPPORTED_MEDIA_TYPE', $data['code'] ?? null);

        // 3. Filename with control character
        $this->client->request('POST', $url, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['filename' => "bad\x00file.png"]));
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('FILENAME_CONTROL_CHARS', $data['code'] ?? null);

        // 4. Invalid mode
        $this->client->request('POST', $url, [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['filename' => 'valid.png', 'mode' => 'invalid_stream_mode']));
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_MODE', $data['code'] ?? null);
    }

    public function testDirectUploadSignPartRejectsSinglePutIntent(): void
    {
        $this->client->loginUser($this->ownerUser);

        $intent = new \App\Entity\AssetUploadIntent();
        $intent->uuid = Uuid::v4();
        $intent->project = $this->project;
        $intent->storageKey = 'uploads/test.png';
        $intent->originalFilename = 'test.png';
        $intent->mode = \App\Entity\AssetUploadIntent::MODE_SINGLE_PUT;
        $intent->status = \App\Entity\AssetUploadIntent::STATUS_PENDING;
        $intent->expiresAt = new \DateTimeImmutable('+1 hour');
        $this->em->persist($intent);
        $this->em->flush();

        $this->client->request(
            'POST',
            "/api/projects/{$this->projectUuid}/media/direct-upload/sign-part",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'intent_uuid' => (string) $intent->uuid,
                'part_number' => 1,
            ])
        );

        $this->assertSame(400, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_INTENT_MODE', $data['code'] ?? null);
    }

    public function testDirectUploadCompleteMultipartRequiresParts(): void
    {
        $this->client->loginUser($this->ownerUser);

        $intent = new \App\Entity\AssetUploadIntent();
        $intent->uuid = Uuid::v4();
        $intent->project = $this->project;
        $intent->storageKey = 'uploads/multipart.bin';
        $intent->originalFilename = 'multipart.bin';
        $intent->mode = \App\Entity\AssetUploadIntent::MODE_MULTIPART;
        $intent->s3MultipartUploadId = 'test-upload-id-123';
        $intent->status = \App\Entity\AssetUploadIntent::STATUS_PENDING;
        $intent->expiresAt = new \DateTimeImmutable('+1 hour');
        $this->em->persist($intent);
        $this->em->flush();

        $this->client->request(
            'POST',
            "/api/projects/{$this->projectUuid}/media/direct-upload/complete",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'intent_uuid' => (string) $intent->uuid,
                'parts'       => [],
            ])
        );

        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('PARTS_REQUIRED', $data['code'] ?? null);
    }

    public function testDirectUploadCleanupOrphansRequiresAuth(): void
    {
        $this->client->request('POST', "/api/projects/{$this->projectUuid}/media/direct-upload/cleanup-orphans");
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('AUTH_REQUIRED', $data['code'] ?? null);

        $this->client->loginUser($this->ownerUser);
        $this->client->request('POST', "/api/projects/{$this->projectUuid}/media/direct-upload/cleanup-orphans");
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('success', $data['status'] ?? null);
    }

    public function testCycle117TranslationKeysParityAcrossAllLocales(): void
    {
        $keys = [
            'direct_upload.access_denied',
            'direct_upload.unsupported_media_type',
            'direct_upload.filename_control_chars',
            'direct_upload.invalid_mode',
            'direct_upload.invalid_intent_mode',
            'direct_upload.parts_required_multipart',
            'tus.forbidden_non_owner',
            'tus.invalid_checksum_format',
            'tus.unsupported_checksum_algorithm',
            'tus.checksum_mismatch',
        ];

        $locales = ['en', 'fr', 'es', 'ar'];
        foreach ($locales as $locale) {
            $filePath = __DIR__ . "/../../translations/messages.{$locale}.php";
            $this->assertFileExists($filePath);
            $catalog = require $filePath;

            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $catalog, "Missing translation key '{$key}' in '{$locale}'");
                $this->assertNotEmpty($catalog[$key], "Translation key '{$key}' is empty in '{$locale}'");
            }
        }
    }
}
