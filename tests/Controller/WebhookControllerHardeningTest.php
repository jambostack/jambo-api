<?php

namespace App\Tests\Controller;

use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\User;
use App\Entity\Webhook;
use App\Enum\ProjectMemberStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class WebhookControllerHardeningTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $superAdmin;
    private User $nonMemberUser;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get(EntityManagerInterface::class);
        $hasher = $this->client->getContainer()->get(UserPasswordHasherInterface::class);

        $suffix = bin2hex(random_bytes(4));

        $this->superAdmin = new User();
        $this->superAdmin->name = 'Super Admin';
        $this->superAdmin->email = "superadmin_{$suffix}@test.com";
        $this->superAdmin->password = $hasher->hashPassword($this->superAdmin, 'SecurePass123!');
        $this->superAdmin->roles = ['ROLE_SUPER_ADMIN'];
        $this->em->persist($this->superAdmin);

        $this->nonMemberUser = new User();
        $this->nonMemberUser->name = 'Non Member';
        $this->nonMemberUser->email = "nonmember_{$suffix}@test.com";
        $this->nonMemberUser->password = $hasher->hashPassword($this->nonMemberUser, 'SecurePass123!');
        $this->nonMemberUser->roles = ['ROLE_USER'];
        $this->em->persist($this->nonMemberUser);

        $this->project = new Project();
        $this->project->name = "Webhook Test Project {$suffix}";
        $this->em->persist($this->project);

        $this->em->flush();
    }

    public function testCreateWebhookAutoGeneratesSecureSecret(): void
    {
        $this->client->loginUser($this->superAdmin);
        $projectUuid = $this->project->uuid->toString();

        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/webhooks",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'Auto Secret Webhook',
                'url'  => 'https://example.com/webhook-receiver',
            ])
        );

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('plain_secret', $data);
        $this->assertStringStartsWith('whsec_', $data['plain_secret']);
        $this->assertGreaterThanOrEqual(48, strlen($data['plain_secret']));
        $this->assertSame('***', $data['data']['secret']);
        $this->assertSame('Auto Secret Webhook', $data['data']['name']);
        $this->assertSame('https://example.com/webhook-receiver', $data['data']['url']);
    }

    public function testCreateWebhookRejectsWeakSecret(): void
    {
        $this->client->loginUser($this->superAdmin);
        $projectUuid = $this->project->uuid->toString();

        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/webhooks",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name'   => 'Weak Secret Webhook',
                'url'    => 'https://example.com/receiver',
                'secret' => 'short', // less than 16 characters
            ])
        );

        $this->assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertStringContainsString('Webhook secret must be between 16 and 256 characters long', $data['error']);
    }

    public function testCreateWebhookRejectsSsrfUrl(): void
    {
        $this->client->loginUser($this->superAdmin);
        $projectUuid = $this->project->uuid->toString();

        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/webhooks",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'SSRF Webhook',
                'url'  => 'http://169.254.169.254/latest/meta-data',
            ])
        );

        $this->assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertNotEmpty($data['error']);
    }

    public function testRotateWebhookSecretGeneratesNewSecret(): void
    {
        $this->client->loginUser($this->superAdmin);
        $projectUuid = $this->project->uuid->toString();

        // 1. Create a webhook first
        $webhook = new Webhook();
        $webhook->project = $this->project;
        $webhook->name = 'Rotation Target';
        $webhook->url = 'https://example.com/events';
        $webhook->secret = 'initial-encrypted-secret';
        $this->em->persist($webhook);
        $this->em->flush();

        $webhookUuid = $webhook->uuid->toString();

        // 2. Rotate secret
        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/webhooks/{$webhookUuid}/rotate-secret",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([])
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('plain_secret', $data);
        $this->assertStringStartsWith('whsec_', $data['plain_secret']);
        $this->assertSame('***', $data['data']['secret']);
    }

    public function testNonMemberCannotAccessWebhooks(): void
    {
        $this->client->loginUser($this->nonMemberUser);
        $projectUuid = $this->project->uuid->toString();

        $this->client->request('GET', "/api/projects/{$projectUuid}/webhooks");

        // Should return 403 Forbidden
        $this->assertResponseStatusCodeSame(403);
    }
}
