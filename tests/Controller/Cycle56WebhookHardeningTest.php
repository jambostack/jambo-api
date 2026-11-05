<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Automation;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\Webhook;
use App\Service\Flow\FlowContext;
use App\Service\Flow\Handlers\Action\CallWebhookHandler;
use App\Service\Flow\Handlers\Trigger\WebhookInboundHandler;
use App\Service\Security\SsrfProtectionService;
use App\Service\WebhookSecretService;
use App\Service\WebhookService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class Cycle56WebhookHardeningTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $superAdmin;
    private Project $project;
    private string $secret = 'whsec_secret_key_12345678901234567890';

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get(EntityManagerInterface::class);
        $hasher = $this->client->getContainer()->get(UserPasswordHasherInterface::class);

        $suffix = bin2hex(random_bytes(4));

        $this->superAdmin = new User();
        $this->superAdmin->name = 'Cycle 56 Admin';
        $this->superAdmin->email = "cycle56_admin_{$suffix}@test.com";
        $this->superAdmin->password = $hasher->hashPassword($this->superAdmin, 'SecurePass123!');
        $this->superAdmin->roles = ['ROLE_SUPER_ADMIN'];
        $this->em->persist($this->superAdmin);

        $this->project = new Project();
        $this->project->name = "Cycle 56 Project {$suffix}";
        $this->em->persist($this->project);

        $this->em->flush();
    }

    private function createInboundAutomation(array $triggerConfig = []): Automation
    {
        $automation = new Automation();
        $automation->project = $this->project;
        $automation->name = 'Cycle56 Inbound ' . bin2hex(random_bytes(3));
        $automation->isActive = true;
        $automation->flowGraph = [
            'nodes' => [
                [
                    'id' => 'trigger_1',
                    'type' => 'trigger.webhook.inbound',
                    'data' => [
                        'config' => array_merge([
                            'secret' => $this->secret,
                        ], $triggerConfig),
                    ],
                ],
            ],
            'edges' => [],
        ];

        $this->em->persist($automation);
        $this->em->flush();

        return $automation;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. WebhookController : Unauthenticated Access Blocked (401)
    // ─────────────────────────────────────────────────────────────────────────

    public function testUnauthenticatedWebhookAccessReturns401(): void
    {
        $projectUuid = $this->project->uuid->toString();

        $this->client->request('GET', "/api/projects/{$projectUuid}/webhooks");

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertSame('AUTH_REQUIRED', $data['code'] ?? null);
        $this->assertNotEmpty($data['error'] ?? null);
        $this->assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
        $this->assertContains($this->client->getResponse()->headers->get('X-Frame-Options'), ['DENY', 'SAMEORIGIN']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. WebhookController : Content-Length Pre-check Caps (413)
    // ─────────────────────────────────────────────────────────────────────────

    public function testWebhookControllerRejectsExcessiveContentLengthHeader(): void
    {
        $this->client->loginUser($this->superAdmin);
        $projectUuid = $this->project->uuid->toString();

        $this->client->request(
            'POST',
            "/api/projects/{$projectUuid}/webhooks",
            [],
            [],
            [
                'CONTENT_TYPE'   => 'application/json',
                'CONTENT_LENGTH' => '1048576', // 1MB > 512KB cap
            ],
            json_encode(['name' => 'Cap Test', 'url' => 'https://example.com/wh'])
        );

        $this->assertResponseStatusCodeSame(413);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('PAYLOAD_TOO_LARGE', $data['code'] ?? null);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. WebhookInboundController : Content-Length Pre-check Caps (413)
    // ─────────────────────────────────────────────────────────────────────────

    public function testWebhookInboundRejectsExcessiveContentLengthHeader(): void
    {
        $automation = $this->createInboundAutomation();

        $this->client->request(
            'POST',
            '/api/webhooks/inbound/' . $automation->uuid->toRfc4122(),
            [],
            [],
            [
                'CONTENT_TYPE'          => 'application/json',
                'CONTENT_LENGTH'        => '5000000', // 5MB > 2MB cap
                'HTTP_X_WEBHOOK_SECRET' => $this->secret,
            ],
            json_encode(['data' => 'test'])
        );

        $this->assertResponseStatusCodeSame(413);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('PAYLOAD_TOO_LARGE', $data['code'] ?? null);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. WebhookInboundController : IP Allowlist Enforcement (403 vs 200)
    // ─────────────────────────────────────────────────────────────────────────

    public function testWebhookInboundEnforcesIpAllowlistSingleIp(): void
    {
        $automation = $this->createInboundAutomation([
            'ip_allowlist' => ['198.51.100.42', '203.0.113.1'],
        ]);

        // 1. Rejected IP
        $this->client->request(
            'POST',
            '/api/webhooks/inbound/' . $automation->uuid->toRfc4122(),
            [],
            [],
            [
                'CONTENT_TYPE'          => 'application/json',
                'REMOTE_ADDR'           => '192.0.2.99',
                'HTTP_X_WEBHOOK_SECRET' => $this->secret,
            ],
            json_encode(['event' => 'test'])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('IP_FORBIDDEN', $data['code'] ?? null);

        // 2. Allowed IP
        $this->client->request(
            'POST',
            '/api/webhooks/inbound/' . $automation->uuid->toRfc4122(),
            [],
            [],
            [
                'CONTENT_TYPE'          => 'application/json',
                'REMOTE_ADDR'           => '198.51.100.42',
                'HTTP_X_WEBHOOK_SECRET' => $this->secret,
            ],
            json_encode(['event' => 'test'])
        );

        $this->assertResponseIsSuccessful();
    }

    public function testWebhookInboundEnforcesIpAllowlistCidrSubnet(): void
    {
        $automation = $this->createInboundAutomation([
            'ip_allowlist' => '10.50.0.0/16, 172.16.0.0/12',
        ]);

        // Inside 10.50.0.0/16
        $this->client->request(
            'POST',
            '/api/webhooks/inbound/' . $automation->uuid->toRfc4122(),
            [],
            [],
            [
                'CONTENT_TYPE'          => 'application/json',
                'REMOTE_ADDR'           => '10.50.4.12',
                'HTTP_X_WEBHOOK_SECRET' => $this->secret,
            ],
            json_encode(['event' => 'test'])
        );
        $this->assertResponseIsSuccessful();

        // Outside subnet
        $this->client->request(
            'POST',
            '/api/webhooks/inbound/' . $automation->uuid->toRfc4122(),
            [],
            [],
            [
                'CONTENT_TYPE'          => 'application/json',
                'REMOTE_ADDR'           => '10.51.0.1',
                'HTTP_X_WEBHOOK_SECRET' => $this->secret,
            ],
            json_encode(['event' => 'test'])
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. WebhookInboundController : Svix / Standard Webhooks Signature
    // ─────────────────────────────────────────────────────────────────────────

    public function testWebhookInboundAuthenticatesSvixStandardWebhook(): void
    {
        $automation = $this->createInboundAutomation();
        $payload = json_encode(['type' => 'invoice.created', 'amount' => 5000]);
        $msgId = 'msg_' . bin2hex(random_bytes(10));
        $timestamp = (string) time();

        $toSign = "{$msgId}.{$timestamp}.{$payload}";
        $expectedRaw = hash_hmac('sha256', $toSign, $this->secret, true);
        $signature = 'v1,' . base64_encode($expectedRaw);

        $this->client->request(
            'POST',
            '/api/webhooks/inbound/' . $automation->uuid->toRfc4122(),
            [],
            [],
            [
                'CONTENT_TYPE'            => 'application/json',
                'HTTP_WEBHOOK_ID'         => $msgId,
                'HTTP_WEBHOOK_TIMESTAMP'  => $timestamp,
                'HTTP_WEBHOOK_SIGNATURE'  => $signature,
            ],
            $payload
        );

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
    }

    public function testWebhookInboundRejectsSvixWithInvalidSignature(): void
    {
        $automation = $this->createInboundAutomation();
        $payload = json_encode(['type' => 'invoice.created']);
        $msgId = 'msg_' . bin2hex(random_bytes(10));
        $timestamp = (string) time();

        $this->client->request(
            'POST',
            '/api/webhooks/inbound/' . $automation->uuid->toRfc4122(),
            [],
            [],
            [
                'CONTENT_TYPE'            => 'application/json',
                'HTTP_WEBHOOK_ID'         => $msgId,
                'HTTP_WEBHOOK_TIMESTAMP'  => $timestamp,
                'HTTP_WEBHOOK_SIGNATURE'  => 'v1,invalid_b64_signature==',
            ],
            $payload
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('SIGNATURE_INVALID', $data['code'] ?? null);
    }

    public function testWebhookInboundRejectsSvixWithExpiredTimestamp(): void
    {
        $automation = $this->createInboundAutomation();
        $payload = json_encode(['type' => 'invoice.created']);
        $msgId = 'msg_' . bin2hex(random_bytes(10));
        $expiredTs = (string) (time() - 360); // 6 minutes ago (> 300s window)

        $toSign = "{$msgId}.{$expiredTs}.{$payload}";
        $expectedRaw = hash_hmac('sha256', $toSign, $this->secret, true);
        $signature = 'v1,' . base64_encode($expectedRaw);

        $this->client->request(
            'POST',
            '/api/webhooks/inbound/' . $automation->uuid->toRfc4122(),
            [],
            [],
            [
                'CONTENT_TYPE'            => 'application/json',
                'HTTP_WEBHOOK_ID'         => $msgId,
                'HTTP_WEBHOOK_TIMESTAMP'  => $expiredTs,
                'HTTP_WEBHOOK_SIGNATURE'  => $signature,
            ],
            $payload
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6. WebhookInboundController : Replay Attack Detection (409)
    // ─────────────────────────────────────────────────────────────────────────

    public function testWebhookInboundBlocksReplayWithGitHubDeliveryId(): void
    {
        $automation = $this->createInboundAutomation();
        $payload = json_encode(['ref' => 'refs/heads/main', 'commits' => []]);
        $deliveryId = 'deliv-' . bin2hex(random_bytes(16));
        $hmac = hash_hmac('sha256', $payload, $this->secret);

        // 1. Première réception : valide
        $this->client->request(
            'POST',
            '/api/webhooks/inbound/' . $automation->uuid->toRfc4122(),
            [],
            [],
            [
                'CONTENT_TYPE'              => 'application/json',
                'HTTP_X_GITHUB_DELIVERY'    => $deliveryId,
                'HTTP_X_HUB_SIGNATURE_256'  => 'sha256=' . $hmac,
            ],
            $payload
        );
        $this->assertResponseIsSuccessful();

        // 2. Deuxième réception identique (rejeu malveillant) : 409 Conflict
        $this->client->request(
            'POST',
            '/api/webhooks/inbound/' . $automation->uuid->toRfc4122(),
            [],
            [],
            [
                'CONTENT_TYPE'              => 'application/json',
                'HTTP_X_GITHUB_DELIVERY'    => $deliveryId,
                'HTTP_X_HUB_SIGNATURE_256'  => 'sha256=' . $hmac,
            ],
            $payload
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('REPLAY_DETECTED', $data['code'] ?? null);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 7. WebhookService : Outbound Delivery Headers & Anti-Replay Signature
    // ─────────────────────────────────────────────────────────────────────────

    public function testWebhookServiceGeneratesReplayProtectedHeaders(): void
    {
        $secretService = $this->client->getContainer()->get(WebhookSecretService::class);
        $mockHttpClient = $this->createMock(HttpClientInterface::class);
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getContent')->willReturn('{"ok":true}');

        $capturedHeaders = [];
        $mockHttpClient->expects($this->once())
            ->method('request')
            ->willReturnCallback(function ($method, $url, $options) use ($mockResponse, &$capturedHeaders) {
                $capturedHeaders = $options['headers'] ?? [];
                return $mockResponse;
            });

        $mockSsrf = $this->createMock(SsrfProtectionService::class);
        $mockSsrf->method('isSafeUrl')->willReturn(true);
        $mockSsrf->method('buildSafeHttpClientOptions')->willReturnCallback(function ($url, $options) {
            return $options;
        });
        $mockSsrf->method('readSafeResponseBody')->willReturn('{"ok":true}');

        $webhookService = new WebhookService(
            $this->client->getContainer()->get(\App\Repository\WebhookRepository::class),
            $this->em,
            $mockHttpClient,
            $secretService,
            $mockSsrf
        );

        $webhook = new Webhook();
        $webhook->project = $this->project;
        $webhook->name = 'Outbound Test';
        $webhook->url = 'https://customer.example.com/webhook';
        $webhook->secret = $secretService->encrypt('raw-secret-key-123456');
        $this->em->persist($webhook);
        $this->em->flush();

        $log = $webhookService->send($webhook, 'entry.created', ['id' => 42]);

        $this->assertNotNull($log);
        $this->assertSame('succeeded', $log->status);

        $this->assertArrayHasKey('X-JamboApi-Event', $capturedHeaders);
        $this->assertSame('entry.created', $capturedHeaders['X-JamboApi-Event']);
        $this->assertArrayHasKey('X-JamboApi-Delivery', $capturedHeaders);
        $this->assertNotEmpty($capturedHeaders['X-JamboApi-Delivery']);
        $this->assertArrayHasKey('X-JamboApi-Timestamp', $capturedHeaders);
        $this->assertArrayHasKey('X-JamboApi-Signature', $capturedHeaders);
        $this->assertArrayHasKey('X-JamboApi-Signature-256', $capturedHeaders);
        $this->assertStringStartsWith('t=', $capturedHeaders['X-JamboApi-Signature-256']);
        $this->assertStringContainsString('v1=', $capturedHeaders['X-JamboApi-Signature-256']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 8. CallWebhookHandler : HMAC Signing & Payload Caps
    // ─────────────────────────────────────────────────────────────────────────

    public function testCallWebhookHandlerAppliesSecretSignatureAndHeaders(): void
    {
        $mockHttpClient = $this->createMock(HttpClientInterface::class);
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(204);

        $capturedHeaders = [];
        $mockHttpClient->expects($this->once())
            ->method('request')
            ->willReturnCallback(function ($method, $url, $options) use ($mockResponse, &$capturedHeaders) {
                $capturedHeaders = $options['headers'] ?? [];
                return $mockResponse;
            });

        $mockSsrf = $this->createMock(SsrfProtectionService::class);
        $mockSsrf->method('buildSafeHttpClientOptions')->willReturnCallback(function ($url, $options) {
            return $options;
        });
        $mockSsrf->method('readSafeResponseBody')->willReturn('');

        $handler = new CallWebhookHandler($mockSsrf, $mockHttpClient);
        $ctx = new FlowContext(1, $this->project->uuid->toString());
        $ctx->variables = [
            '_node_config' => [
                'url'    => 'https://api.external.com/notify',
                'method' => 'POST',
                'secret' => 'action-secret-789',
                'body'   => '{"event":"user_joined"}',
            ],
        ];

        $output = $handler->execute([], $ctx);

        $this->assertTrue($output->data['called']);
        $this->assertSame(204, $output->data['status_code']);
        $this->assertArrayHasKey('X-JamboApi-Signature', $capturedHeaders);
        $this->assertArrayHasKey('X-JamboApi-Timestamp', $capturedHeaders);
        $this->assertSame('JamboApi-FlowWebhook/1.0', $capturedHeaders['User-Agent']);
    }

    public function testCallWebhookHandlerRejectsOversizedRequestBody(): void
    {
        $handler = new CallWebhookHandler();
        $hugeBody = str_repeat('x', 2097152 + 50);

        $ctx = new FlowContext(1, $this->project->uuid->toString());
        $ctx->variables = [
            '_node_config' => [
                'url'    => 'https://api.external.com/notify',
                'method' => 'POST',
                'body'   => $hugeBody,
            ],
        ];

        $output = $handler->execute([], $ctx);

        $this->assertFalse($output->data['called']);
        $this->assertStringContainsString('Webhook request payload exceeds 2MB limit', $output->data['error']);
    }

    public function testWebhookInboundHandlerConfigSchemaContainsIpAllowlist(): void
    {
        $schema = WebhookInboundHandler::getConfigSchema();

        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('secret', $schema['properties']);
        $this->assertArrayHasKey('ip_allowlist', $schema['properties']);
        $this->assertSame('array', $schema['properties']['ip_allowlist']['type']);
    }
}
