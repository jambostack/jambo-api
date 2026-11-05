<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\User;
use App\Entity\Webhook;
use App\Enum\ProjectMemberStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class Cycle43HardeningTest extends WebTestCase
{
    private function createAdmin(EntityManagerInterface $em): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->name = 'Super Admin Cycle 43';
        $user->email = 'sa_cycle43_' . bin2hex(random_bytes(4)) . '@test.com';
        $user->password = $hasher->hashPassword($user, 'AdminPass123!');
        $user->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($user);
        $em->flush();
        return $user;
    }

    private function createProject(EntityManagerInterface $em, User $owner): Project
    {
        $project = new Project();
        $project->name = 'Cycle 43 Project ' . bin2hex(random_bytes(4));
        $project->defaultLocale = 'en';
        $project->locales = ['en', 'fr'];
        $em->persist($project);

        $member = new ProjectMember();
        $member->project = $project;
        $member->user = $owner;
        $member->status = ProjectMemberStatus::Active;
        $em->persist($member);

        $em->flush();
        return $project;
    }

    private function createCollection(EntityManagerInterface $em, Project $project, string $slug): Collection
    {
        $col = new Collection();
        $col->name = ucfirst($slug);
        $col->slug = $slug;
        $col->project = $project;
        $em->persist($col);
        $em->flush();
        return $col;
    }

    private function createEntry(EntityManagerInterface $em, Collection $col): ContentEntry
    {
        $entry = new ContentEntry();
        $entry->project = $col->project;
        $entry->collection = $col;
        $entry->data = ['title' => 'Test Post'];
        $entry->status = 'draft';
        $entry->currentVersion = 1;
        $em->persist($entry);
        $em->flush();
        return $entry;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. WebhookController Hardening Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function testWebhookControllerInvalidProjectUuidReturns404(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $client->loginUser($admin);

        $client->request('GET', '/api/projects/invalid-project-uuid/webhooks');
        $this->assertSame(404, $client->getResponse()->getStatusCode());

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('PROJECT_NOT_FOUND', $data['code'] ?? null);
    }

    public function testWebhookControllerPayloadTooLargeReturns413(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $project = $this->createProject($em, $admin);
        $client->loginUser($admin);

        $hugePayload = json_encode([
            'name' => 'Huge Webhook',
            'url'  => 'https://example.com/webhook',
            'junk' => str_repeat('X', 600 * 1024),
        ]);

        $client->request(
            'POST',
            sprintf('/api/projects/%s/webhooks', $project->uuid->toRfc4122()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $hugePayload
        );
        $this->assertSame(413, $client->getResponse()->getStatusCode());

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('PAYLOAD_TOO_LARGE', $data['code'] ?? null);
    }

    public function testWebhookControllerEventsValidation(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $project = $this->createProject($em, $admin);
        $client->loginUser($admin);

        // Test > 50 events
        $tooManyEvents = array_map(fn ($i) => "event_{$i}", range(1, 55));
        $client->request(
            'POST',
            sprintf('/api/projects/%s/webhooks', $project->uuid->toRfc4122()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name'   => 'Test Webhook',
                'url'    => 'https://example.com/hook',
                'events' => $tooManyEvents,
            ])
        );
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('TOO_MANY_EVENTS', $data['code'] ?? null);

        // Test invalid event format (dangerous characters)
        $client->request(
            'POST',
            sprintf('/api/projects/%s/webhooks', $project->uuid->toRfc4122()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name'   => 'Test Webhook',
                'url'    => 'https://example.com/hook',
                'events' => ['valid.event', '<script>alert(1)</script>'],
            ])
        );
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_EVENT_FORMAT', $data['code'] ?? null);
    }

    public function testWebhookControllerCrudAndAudit(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $project = $this->createProject($em, $admin);
        $client->loginUser($admin);

        // Create webhook
        $client->request(
            'POST',
            sprintf('/api/projects/%s/webhooks', $project->uuid->toRfc4122()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name'   => 'CI Deployment Hook',
                'url'    => 'https://api.github.com/webhook',
                'events' => ['content.created', 'content.updated'],
            ])
        );
        $this->assertSame(201, $client->getResponse()->getStatusCode());
        $createData = json_decode((string) $client->getResponse()->getContent(), true);
        $webhookUuid = $createData['data']['uuid'] ?? null;
        $this->assertNotNull($webhookUuid);

        // Show webhook
        $client->request('GET', sprintf('/api/projects/%s/webhooks/%s', $project->uuid->toRfc4122(), $webhookUuid));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        // Rotate secret
        $client->request('POST', sprintf('/api/projects/%s/webhooks/%s/rotate-secret', $project->uuid->toRfc4122(), $webhookUuid));
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $rotateData = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($rotateData['success']);
        $this->assertNotEmpty($rotateData['plain_secret']);

        // Delete webhook
        $client->request('DELETE', sprintf('/api/projects/%s/webhooks/%s', $project->uuid->toRfc4122(), $webhookUuid));
        $this->assertSame(204, $client->getResponse()->getStatusCode());

        // Verify audit logs were produced
        $auditLogs = $em->getRepository(AuditLog::class)->findBy([
            'project' => $project,
            'source'  => 'webhook_management',
        ]);
        $this->assertGreaterThanOrEqual(2, count($auditLogs));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. WebhookInboundController Hardening Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function testWebhookInboundControllerUuidAndCodes(): void
    {
        $client = static::createClient();

        // Invalid UUID format
        $client->request('POST', '/api/webhooks/inbound/invalid-endpoint-uuid');
        $this->assertSame(404, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('ENDPOINT_NOT_FOUND', $data['code'] ?? null);

        // Non-existent UUID
        $client->request('POST', '/api/webhooks/inbound/00000000-0000-4000-8000-000000000000');
        $this->assertSame(404, $client->getResponse()->getStatusCode());
        $data2 = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('ENDPOINT_NOT_FOUND', $data2['code'] ?? null);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. CommentController Hardening Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function testCommentControllerUuidAndSlugValidation(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $client->loginUser($admin);

        // Invalid project uuid
        $client->request('GET', '/api/projects/invalid-uuid/collections/posts/content/1/comments');
        $this->assertSame(404, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('ENTRY_NOT_FOUND', $data['code'] ?? null);

        // Invalid collection slug format
        $client->request('GET', '/api/projects/00000000-0000-4000-8000-000000000000/collections/bad%20slug/content/1/comments');
        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testCommentControllerPayloadTooLargeReturns413(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $project = $this->createProject($em, $admin);
        $col = $this->createCollection($em, $project, 'posts');
        $entry = $this->createEntry($em, $col);
        $client->loginUser($admin);

        $hugePayload = json_encode([
            'body' => str_repeat('A', 600 * 1024),
        ]);

        $client->request(
            'POST',
            sprintf('/api/projects/%s/collections/%s/content/%d/comments', $project->uuid->toRfc4122(), $col->slug, $entry->id),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $hugePayload
        );
        $this->assertSame(413, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('PAYLOAD_TOO_LARGE', $data['code'] ?? null);
    }

    public function testCommentControllerCreateUpdateDeleteAndResolve(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $project = $this->createProject($em, $admin);
        $col = $this->createCollection($em, $project, 'articles');
        $entry = $this->createEntry($em, $col);
        $client->loginUser($admin);

        // Create comment
        $client->request(
            'POST',
            sprintf('/api/projects/%s/collections/%s/content/%d/comments', $project->uuid->toRfc4122(), $col->slug, $entry->id),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['body' => 'Initial comment content'])
        );
        $this->assertSame(201, $client->getResponse()->getStatusCode());
        $createData = json_decode((string) $client->getResponse()->getContent(), true);
        $commentId = $createData['data']['id'] ?? null;
        $this->assertNotNull($commentId);

        // Update comment
        $client->request(
            'PUT',
            sprintf('/api/projects/%s/collections/%s/content/%d/comments/%d', $project->uuid->toRfc4122(), $col->slug, $entry->id, $commentId),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['body' => 'Updated comment content'])
        );
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        // Resolve comment with invalid status
        $client->request(
            'POST',
            sprintf('/api/projects/%s/collections/%s/content/%d/comments/%d/resolve', $project->uuid->toRfc4122(), $col->slug, $entry->id, $commentId),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['status' => 'invalid_status'])
        );
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_STATUS', $data['code'] ?? null);

        // Resolve comment with valid status
        $client->request(
            'POST',
            sprintf('/api/projects/%s/collections/%s/content/%d/comments/%d/resolve', $project->uuid->toRfc4122(), $col->slug, $entry->id, $commentId),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['status' => 'resolved'])
        );
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        // Delete comment
        $client->request(
            'DELETE',
            sprintf('/api/projects/%s/collections/%s/content/%d/comments/%d', $project->uuid->toRfc4122(), $col->slug, $entry->id, $commentId)
        );
        $this->assertSame(204, $client->getResponse()->getStatusCode());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. NotificationController Hardening Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function testNotificationControllerUuidAndI18n(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $client->loginUser($admin);

        // Invalid project uuid filter
        $client->request('GET', '/api/notifications?project=invalid-uuid-format');
        $this->assertSame(404, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('PROJECT_NOT_FOUND', $data['code'] ?? null);

        // Non-existent notification read
        $client->request('POST', '/api/notifications/999999/read');
        $this->assertSame(404, $client->getResponse()->getStatusCode());
        $readData = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('NOTIFICATION_NOT_FOUND', $readData['code'] ?? null);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. ColumnSettingController Hardening Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function testColumnSettingControllerBoundsAndCodes(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $project = $this->createProject($em, $admin);
        $col = $this->createCollection($em, $project, 'products');
        $client->loginUser($admin);

        // Invalid project UUID
        $client->request('GET', sprintf('/api/projects/bad-uuid/collections/%s/column-settings', $col->slug));
        $this->assertSame(404, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('PROJECT_NOT_FOUND', $data['code'] ?? null);

        // Payload too large (> 256KB)
        $hugePayload = json_encode([
            'visible_columns' => ['col1'],
            'junk'            => str_repeat('X', 300 * 1024),
        ]);
        $client->request(
            'PUT',
            sprintf('/api/projects/%s/collections/%s/column-settings', $project->uuid->toRfc4122(), $col->slug),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $hugePayload
        );
        $this->assertSame(413, $client->getResponse()->getStatusCode());
        $payloadData = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('PAYLOAD_TOO_LARGE', $payloadData['code'] ?? null);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6. DirectUploadController Hardening Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function testDirectUploadControllerBoundsAndUuid(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = $this->createAdmin($em);
        $project = $this->createProject($em, $admin);
        $client->loginUser($admin);

        // Invalid project UUID
        $client->request('POST', '/api/projects/bad-uuid/media/direct-upload/intent');
        $this->assertSame(404, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('PROJECT_NOT_FOUND', $data['code'] ?? null);

        // Payload too large (> 512KB)
        $hugePayload = json_encode([
            'file_name' => 'test.png',
            'file_size' => 1024,
            'junk'      => str_repeat('X', 600 * 1024),
        ]);
        $client->request(
            'POST',
            sprintf('/api/projects/%s/media/direct-upload/intent', $project->uuid->toRfc4122()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $hugePayload
        );
        $this->assertSame(413, $client->getResponse()->getStatusCode());
        $uploadData = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('PAYLOAD_TOO_LARGE', $uploadData['code'] ?? null);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 7. Strict I18N Parity Across Four Languages
    // ─────────────────────────────────────────────────────────────────────────

    public function testStrictI18nParityAcrossFourLanguages(): void
    {
        $en = require __DIR__ . '/../../translations/messages.en.php';
        $fr = require __DIR__ . '/../../translations/messages.fr.php';
        $es = require __DIR__ . '/../../translations/messages.es.php';
        $ar = require __DIR__ . '/../../translations/messages.ar.php';

        $this->assertIsArray($en);
        $this->assertIsArray($fr);
        $this->assertIsArray($es);
        $this->assertIsArray($ar);

        $enKeys = array_keys($en);
        $frKeys = array_keys($fr);
        $esKeys = array_keys($es);
        $arKeys = array_keys($ar);

        $this->assertSame([], array_diff($enKeys, $frKeys), 'Missing keys in FR dictionary');
        $this->assertSame([], array_diff($frKeys, $enKeys), 'Extra keys in FR dictionary');

        $this->assertSame([], array_diff($enKeys, $esKeys), 'Missing keys in ES dictionary');
        $this->assertSame([], array_diff($esKeys, $enKeys), 'Extra keys in ES dictionary');

        $this->assertSame([], array_diff($enKeys, $arKeys), 'Missing keys in AR dictionary');
        $this->assertSame([], array_diff($arKeys, $enKeys), 'Extra keys in AR dictionary');

        $this->assertGreaterThanOrEqual(2530, count($enKeys));
    }
}
