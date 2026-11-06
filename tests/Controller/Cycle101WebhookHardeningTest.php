<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\User;
use App\Entity\Webhook;
use App\Enum\ProjectMemberStatus;
use App\Service\WebhookSecretService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class Cycle101WebhookHardeningTest extends WebTestCase
{
    private const LOCALES = ['en', 'fr', 'es', 'ar'];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Project $project;
    private User $admin;
    private User $unauthorizedUser;
    private WebhookSecretService $secretService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->secretService = self::getContainer()->get(WebhookSecretService::class);

        $suffix = bin2hex(random_bytes(4));

        $this->admin = new User();
        $this->admin->name = 'Cycle101 Admin';
        $this->admin->email = "admin_c101_{$suffix}@test.com";
        $this->admin->password = $hasher->hashPassword($this->admin, 'secure123');
        $this->admin->roles = ['ROLE_SUPER_ADMIN'];
        $this->em->persist($this->admin);

        $this->unauthorizedUser = new User();
        $this->unauthorizedUser->name = 'Cycle101 Unauthorized';
        $this->unauthorizedUser->email = "unauth_c101_{$suffix}@test.com";
        $this->unauthorizedUser->password = $hasher->hashPassword($this->unauthorizedUser, 'secure123');
        $this->unauthorizedUser->roles = ['ROLE_USER'];
        $this->em->persist($this->unauthorizedUser);

        $this->project = new Project();
        $this->project->name = "Cycle101 Project {$suffix}";
        $this->project->publicApi = true;
        $this->project->settings = ['tier' => 'starter'];
        $this->em->persist($this->project);

        $member = new ProjectMember();
        $member->project = $this->project;
        $member->user = $this->unauthorizedUser;
        $member->email = $this->unauthorizedUser->email;
        $member->status = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $this->em->persist($member);

        $this->em->flush();
    }

    private function createTestWebhook(string $name = 'Test Webhook'): Webhook
    {
        $webhook = new Webhook();
        $webhook->name = $name;
        $webhook->url = 'https://example.com/webhook/test-' . bin2hex(random_bytes(4));
        $webhook->events = ['content.created'];
        $webhook->secret = $this->secretService->encrypt('whsec_test_' . bin2hex(random_bytes(16)));
        $webhook->isActive = true;
        $webhook->project = $this->project;

        $this->em->persist($webhook);
        $this->em->flush();

        return $webhook;
    }

    public function testWebhookAccessDeniedAuditLogging(): void
    {
        $this->client->loginUser($this->unauthorizedUser);
        $projectUuid = $this->project->uuid->toString();

        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/webhooks",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'name' => 'Unauthorized Hook',
                'url' => 'https://example.com/unauth',
                'events' => ['ping'],
                'secret' => 'supersecretpassphrase123',
            ], JSON_THROW_ON_ERROR)
        );
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('ACCESS_DENIED', $data['code']);

        $logs = $this->em->getRepository(AuditLog::class)->findBy([
            'toolName' => 'webhook_access_denied',
        ], ['id' => 'DESC']);

        $this->assertNotEmpty($logs);
        $log = $logs[0];
        $this->assertSame('failed', $log->status);
        $this->assertSame($projectUuid, $log->input['project_uuid'] ?? null);
    }

    public function testWebhookCreateAndAuditLogging(): void
    {
        $this->client->loginUser($this->admin);
        $projectUuid = $this->project->uuid->toString();

        $payload = [
            'name' => 'Automated Webhook',
            'url'  => 'https://example.com/api/inbound',
            'events' => ['content.created', 'content.updated'],
            'secret' => 'supersecretpassphrase123',
        ];

        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/webhooks",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR)
        );

        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertSame('Automated Webhook', $data['data']['name']);
        $this->assertSame('supersecretpassphrase123', $data['plain_secret']);

        $logs = $this->em->getRepository(AuditLog::class)->findBy([
            'toolName' => 'webhook_create',
        ], ['id' => 'DESC']);

        $this->assertNotEmpty($logs);
        $this->assertSame('success', $logs[0]->status);
        $this->assertSame('Automated Webhook', $logs[0]->input['name']);
    }

    public function testWebhookRotateSecretRejectionAuditLogging(): void
    {
        $this->client->loginUser($this->admin);
        $webhook = $this->createTestWebhook('Rotate Reject Hook');
        $projectUuid = $this->project->uuid->toString();
        $webhookUuid = $webhook->uuid->toString();

        // Too short secret (< 16 chars)
        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/webhooks/{$webhookUuid}/rotate-secret",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['secret' => 'tooshort'], JSON_THROW_ON_ERROR)
        );

        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('SECRET_LENGTH_INVALID', $data['code']);

        $logs = $this->em->getRepository(AuditLog::class)->findBy([
            'toolName' => 'webhook_rotate_secret_rejected',
        ], ['id' => 'DESC']);

        $this->assertNotEmpty($logs);
        $this->assertSame('failed', $logs[0]->status);
    }

    public function testWebhookRotateSecretSuccessAndAuditLogging(): void
    {
        $this->client->loginUser($this->admin);
        $webhook = $this->createTestWebhook('Rotate Success Hook');
        $projectUuid = $this->project->uuid->toString();
        $webhookUuid = $webhook->uuid->toString();

        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/webhooks/{$webhookUuid}/rotate-secret",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['secret' => 'validsecretlengthatleast16chars'], JSON_THROW_ON_ERROR)
        );

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame('validsecretlengthatleast16chars', $data['plain_secret']);

        $logs = $this->em->getRepository(AuditLog::class)->findBy([
            'toolName' => 'webhook_rotate_secret',
        ], ['id' => 'DESC']);

        $this->assertNotEmpty($logs);
        $this->assertSame('success', $logs[0]->status);
    }

    public function testWebhookTestOutboundAuditLogging(): void
    {
        $this->client->loginUser($this->admin);
        $webhook = $this->createTestWebhook('Ping Test Hook');
        $projectUuid = $this->project->uuid->toString();
        $webhookUuid = $webhook->uuid->toString();

        $this->client->request('POST', "/api/projects/{$projectUuid}/webhooks/{$webhookUuid}/test");

        $status = $this->client->getResponse()->getStatusCode();
        $this->assertTrue(in_array($status, [200, 503], true));

        $logs = $this->em->getRepository(AuditLog::class)->findBy([
            'toolName' => 'webhook_test',
        ], ['id' => 'DESC']);

        $this->assertNotEmpty($logs);
        $this->assertSame($webhook->uuid->toRfc4122(), $logs[0]->input['webhook_uuid'] ?? null);
    }

    public function testWebhookPruneLogsAndAuditLogging(): void
    {
        $this->client->loginUser($this->admin);
        $webhook = $this->createTestWebhook('Prune Logs Hook');
        $projectUuid = $this->project->uuid->toString();
        $webhookUuid = $webhook->uuid->toString();

        $this->client->request('POST', "/api/projects/{$projectUuid}/webhooks/{$webhookUuid}/logs/prune?days=14");

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertSame(14, $data['retention_days']);

        $logs = $this->em->getRepository(AuditLog::class)->findBy([
            'toolName' => 'webhook_logs_prune',
        ], ['id' => 'DESC']);

        $this->assertNotEmpty($logs);
        $this->assertSame('success', $logs[0]->status);
        $this->assertSame(14, $logs[0]->input['days'] ?? null);
    }

    public function testWebhookDeleteAndAuditLogging(): void
    {
        $this->client->loginUser($this->admin);
        $webhook = $this->createTestWebhook('To Delete Hook');
        $projectUuid = $this->project->uuid->toString();
        $webhookUuid = $webhook->uuid->toString();

        $this->client->request('DELETE', "/api/projects/{$projectUuid}/webhooks/{$webhookUuid}");
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        $logs = $this->em->getRepository(AuditLog::class)->findBy([
            'toolName' => 'webhook_delete',
        ], ['id' => 'DESC']);

        $this->assertNotEmpty($logs);
        $this->assertSame('success', $logs[0]->status);
    }

    public function testWebhookI18nCompletenessAcrossLocales(): void
    {
        $rootDir = dirname(__DIR__, 2);
        $requiredKeys = [
            'webhook.rate_limit_exceeded',
            'webhook.test_rate_limit_exceeded',
            'webhook.access_denied',
            'webhook.secret_length_invalid',
            'webhook.limit_exceeded',
            'webhook.prune_success',
        ];

        foreach (self::LOCALES as $locale) {
            $path = "{$rootDir}/translations/messages.{$locale}.php";
            $this->assertFileExists($path, "Translation catalog for {$locale} must exist.");

            $catalog = require $path;
            $this->assertIsArray($catalog);

            foreach ($requiredKeys as $key) {
                $this->assertArrayHasKey($key, $catalog, "Locale [{$locale}] is missing required key [{$key}].");
                $this->assertNotEmpty($catalog[$key], "Locale [{$locale}] has empty translation for [{$key}].");
            }
        }
    }
}
