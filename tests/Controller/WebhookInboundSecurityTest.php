<?php

namespace App\Tests\Controller;

use App\Entity\Automation;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class WebhookInboundSecurityTest extends WebTestCase
{
    private string $secret = 'super-test-secret-key-42';

    private function createClientAndAutomation(): array
    {
        $client = static::createClient();
        $em = $client->getContainer()->get(EntityManagerInterface::class);

        $project = new Project();
        $project->name = 'Inbound Project ' . bin2hex(random_bytes(4));
        $em->persist($project);

        $automation = new Automation();
        $automation->project = $project;
        $automation->name = 'Test Inbound';
        $automation->isActive = true;
        $automation->flowGraph = [
            'nodes' => [
                [
                    'id' => 'trigger_1',
                    'type' => 'trigger.webhook.inbound',
                    'data' => [
                        'config' => [
                            'secret' => $this->secret,
                        ],
                    ],
                ],
            ],
            'edges' => [],
        ];

        $em->persist($automation);
        $em->flush();

        return [$client, $automation];
    }

    public function testAuthenticatesWithDirectSecretHeader(): void
    {
        /** @var KernelBrowser $client */
        [$client, $automation] = $this->createClientAndAutomation();
        $payload = json_encode(['event' => 'user.signup', 'id' => 123]);

        $client->request(
            'POST',
            '/api/webhooks/inbound/' . $automation->uuid->toRfc4122(),
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_WEBHOOK_SECRET' => $this->secret,
            ],
            $payload
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testAuthenticatesWithGitHubHmacHeader(): void
    {
        /** @var KernelBrowser $client */
        [$client, $automation] = $this->createClientAndAutomation();
        $payload = json_encode(['action' => 'push', 'ref' => 'refs/heads/main']);
        $hmac = hash_hmac('sha256', $payload, $this->secret);

        $client->request(
            'POST',
            '/api/webhooks/inbound/' . $automation->uuid->toRfc4122(),
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . $hmac,
            ],
            $payload
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testAuthenticatesWithGenericHmacHeader(): void
    {
        /** @var KernelBrowser $client */
        [$client, $automation] = $this->createClientAndAutomation();
        $payload = json_encode(['type' => 'order.completed', 'amount' => 9900]);
        $hmac = hash_hmac('sha256', $payload, $this->secret);

        $client->request(
            'POST',
            '/api/webhooks/inbound/' . $automation->uuid->toRfc4122(),
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE_SHA256' => $hmac,
            ],
            $payload
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testAuthenticatesWithStripeSignature(): void
    {
        /** @var KernelBrowser $client */
        [$client, $automation] = $this->createClientAndAutomation();
        $payload = json_encode(['type' => 'payment_intent.succeeded']);
        $timestamp = time();
        $hmac = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);

        $client->request(
            'POST',
            '/api/webhooks/inbound/' . $automation->uuid->toRfc4122(),
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$hmac}",
            ],
            $payload
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testRejectsStaleStripeTimestampToMitigateReplay(): void
    {
        /** @var KernelBrowser $client */
        [$client, $automation] = $this->createClientAndAutomation();
        $payload = json_encode(['type' => 'payment_intent.succeeded']);
        $oldTimestamp = time() - 3600; // 1 hour ago
        $hmac = hash_hmac('sha256', "{$oldTimestamp}.{$payload}", $this->secret);

        $client->request(
            'POST',
            '/api/webhooks/inbound/' . $automation->uuid->toRfc4122(),
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => "t={$oldTimestamp},v1={$hmac}",
            ],
            $payload
        );

        $this->assertResponseStatusCodeSame(403);
    }

    public function testRejectsInvalidSecretOrSignature(): void
    {
        /** @var KernelBrowser $client */
        [$client, $automation] = $this->createClientAndAutomation();
        $payload = json_encode(['data' => 'test']);

        $client->request(
            'POST',
            '/api/webhooks/inbound/' . $automation->uuid->toRfc4122(),
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_WEBHOOK_SECRET' => 'wrong-secret',
            ],
            $payload
        );

        $this->assertResponseStatusCodeSame(403);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('Invalid webhook secret or signature', $data['error']);
    }

    public function testAuthenticatesWithGitlabTokenHeader(): void
    {
        /** @var KernelBrowser $client */
        [$client, $automation] = $this->createClientAndAutomation();
        $payload = json_encode(['object_kind' => 'push', 'project' => ['name' => 'gitlab-app']]);

        $client->request(
            'POST',
            '/api/webhooks/inbound/' . $automation->uuid->toRfc4122(),
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_GITLAB_TOKEN' => $this->secret,
            ],
            $payload
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testAuthenticatesWithSlackSignatureHeader(): void
    {
        /** @var KernelBrowser $client */
        [$client, $automation] = $this->createClientAndAutomation();
        $payload = json_encode(['command' => '/deploy', 'user_id' => 'U123']);
        $ts = time();
        $sig = 'v0=' . hash_hmac('sha256', "v0:{$ts}:{$payload}", $this->secret);

        $client->request(
            'POST',
            '/api/webhooks/inbound/' . $automation->uuid->toRfc4122(),
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SLACK_SIGNATURE' => $sig,
                'HTTP_X_SLACK_REQUEST_TIMESTAMP' => (string) $ts,
            ],
            $payload
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testRejectsSlackReplayAttackWithOldTimestamp(): void
    {
        /** @var KernelBrowser $client */
        [$client, $automation] = $this->createClientAndAutomation();
        $payload = json_encode(['command' => '/replayed']);
        $oldTs = time() - 600; // 10 minutes ago
        $sig = 'v0=' . hash_hmac('sha256', "v0:{$oldTs}:{$payload}", $this->secret);

        $client->request(
            'POST',
            '/api/webhooks/inbound/' . $automation->uuid->toRfc4122(),
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SLACK_SIGNATURE' => $sig,
                'HTTP_X_SLACK_REQUEST_TIMESTAMP' => (string) $oldTs,
            ],
            $payload
        );

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAuthenticatesWithBearerAuthorizationHeader(): void
    {
        /** @var KernelBrowser $client */
        [$client, $automation] = $this->createClientAndAutomation();
        $payload = json_encode(['event' => 'custom.event']);

        $client->request(
            'POST',
            '/api/webhooks/inbound/' . $automation->uuid->toRfc4122(),
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->secret,
            ],
            $payload
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testRejectsOversizedPayload(): void
    {
        /** @var KernelBrowser $client */
        [$client, $automation] = $this->createClientAndAutomation();
        // Generate payload larger than 2MB
        $hugePayload = str_repeat('a', 2 * 1024 * 1024 + 10);

        $client->request(
            'POST',
            '/api/webhooks/inbound/' . $automation->uuid->toRfc4122(),
            [],
            [],
            [
                'CONTENT_TYPE' => 'text/plain',
                'HTTP_X_WEBHOOK_SECRET' => $this->secret,
            ],
            $hugePayload
        );

        $this->assertResponseStatusCodeSame(413);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('Payload too large (max 2MB)', $data['error']);
    }

    public function testAuthenticatesWithShopifyHmacHeader(): void
    {
        /** @var KernelBrowser $client */
        [$client, $automation] = $this->createClientAndAutomation();
        $payload = json_encode(['id' => 891234, 'line_items' => [['title' => 'Widget', 'price' => '19.99']]]);
        $expectedB64 = base64_encode(hash_hmac('sha256', $payload, $this->secret, true));

        $client->request(
            'POST',
            '/api/webhooks/inbound/' . $automation->uuid->toRfc4122(),
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SHOPIFY_HMAC_SHA256' => $expectedB64,
                'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'shop-wh-12345',
            ],
            $payload
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testAuthenticatesWithPaddleSignature(): void
    {
        /** @var KernelBrowser $client */
        [$client, $automation] = $this->createClientAndAutomation();
        $payload = json_encode(['event_id' => 'evt_123', 'event_type' => 'transaction.completed']);
        $ts = time();
        $h1 = hash_hmac('sha256', "{$ts}:{$payload}", $this->secret);

        $client->request(
            'POST',
            '/api/webhooks/inbound/' . $automation->uuid->toRfc4122(),
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_PADDLE_SIGNATURE' => "ts={$ts};h1={$h1}",
            ],
            $payload
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testAuthenticatesWithGitHubLegacySha1(): void
    {
        /** @var KernelBrowser $client */
        [$client, $automation] = $this->createClientAndAutomation();
        $payload = json_encode(['action' => 'opened', 'issue' => ['number' => 42]]);
        $hmacSha1 = hash_hmac('sha1', $payload, $this->secret);

        $client->request(
            'POST',
            '/api/webhooks/inbound/' . $automation->uuid->toRfc4122(),
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE' => 'sha1=' . $hmacSha1,
            ],
            $payload
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testRejectsUnsupportedContentTypeWith415(): void
    {
        /** @var KernelBrowser $client */
        [$client, $automation] = $this->createClientAndAutomation();
        $payload = 'binary_data_here';

        $client->request(
            'POST',
            '/api/webhooks/inbound/' . $automation->uuid->toRfc4122(),
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/octet-stream',
                'HTTP_X_WEBHOOK_SECRET' => $this->secret,
            ],
            $payload
        );

        $this->assertResponseStatusCodeSame(415);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('UNSUPPORTED_MEDIA_TYPE', $data['code']);
    }

    public function testDetectsReplayWithStripeSignature(): void
    {
        /** @var KernelBrowser $client */
        [$client, $automation] = $this->createClientAndAutomation();
        $payload = json_encode(['type' => 'charge.succeeded', 'id' => 'ch_123']);
        $timestamp = time();
        $hmac = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->secret);
        $stripeSig = "t={$timestamp},v1={$hmac}";

        // First request succeeds
        $client->request(
            'POST',
            '/api/webhooks/inbound/' . $automation->uuid->toRfc4122(),
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $stripeSig,
            ],
            $payload
        );
        $this->assertResponseIsSuccessful();

        // Immediate identical replay is blocked with 409 REPLAY_DETECTED
        $client->request(
            'POST',
            '/api/webhooks/inbound/' . $automation->uuid->toRfc4122(),
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $stripeSig,
            ],
            $payload
        );
        $this->assertResponseStatusCodeSame(409);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('REPLAY_DETECTED', $data['code']);
    }
}
