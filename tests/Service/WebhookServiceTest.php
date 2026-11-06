<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Project;
use App\Entity\Webhook;
use App\Repository\WebhookRepository;
use App\Service\Security\SsrfProtectionService;
use App\Service\WebhookSecretService;
use App\Service\WebhookService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class WebhookServiceTest extends TestCase
{
    private WebhookRepository $webhookRepo;
    private EntityManagerInterface $em;
    private WebhookSecretService $secretService;
    private SsrfProtectionService $ssrf;

    protected function setUp(): void
    {
        $this->webhookRepo = $this->createMock(WebhookRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->secretService = new WebhookSecretService('test-secret-key-32-bytes-long!!!');
        $this->ssrf = new SsrfProtectionService();
    }

    public function testValidateUrlAcceptsValidPublicUrls(): void
    {
        $client = new MockHttpClient();
        $service = new WebhookService($this->webhookRepo, $this->em, $client, $this->secretService, $this->ssrf);

        $this->assertNull($service->validateUrl('https://example.com/webhook'));
        $this->assertNull($service->validateUrl('http://example.com:8080/hook'));
    }

    public function testValidateUrlRejectsEmptyOrOversizedUrls(): void
    {
        $client = new MockHttpClient();
        $service = new WebhookService($this->webhookRepo, $this->em, $client, $this->secretService, $this->ssrf);

        $this->assertNotNull($service->validateUrl(''));
        $this->assertNotNull($service->validateUrl('   '));
        $this->assertNotNull($service->validateUrl('https://example.com/' . str_repeat('a', 2050)));
    }

    public function testValidateUrlRejectsDisallowedSchemes(): void
    {
        $client = new MockHttpClient();
        $service = new WebhookService($this->webhookRepo, $this->em, $client, $this->secretService, $this->ssrf);

        $this->assertNotNull($service->validateUrl('gopher://example.com/'));
        $this->assertNotNull($service->validateUrl('file:///etc/passwd'));
        $this->assertNotNull($service->validateUrl('ftp://example.com/'));
        $this->assertNotNull($service->validateUrl('javascript:alert(1)'));
    }

    public function testValidateUrlRejectsPrivateAndSsrfIps(): void
    {
        $client = new MockHttpClient();
        $service = new WebhookService($this->webhookRepo, $this->em, $client, $this->secretService, $this->ssrf);

        $this->assertNotNull($service->validateUrl('http://127.0.0.1/webhook'));
        $this->assertNotNull($service->validateUrl('http://localhost:8080/webhook'));
        $this->assertNotNull($service->validateUrl('http://169.254.169.254/latest/meta-data'));
        $this->assertNotNull($service->validateUrl('http://10.0.0.1/hook'));
        $this->assertNotNull($service->validateUrl('http://192.168.1.10/hook'));
        $this->assertNotNull($service->validateUrl('http://[::1]/hook'));
        $this->assertNotNull($service->validateUrl('http://0x7f000001/hook'));
        $this->assertNotNull($service->validateUrl('http://2130706433/hook'));
    }

    public function testSendSkipsInactiveWebhook(): void
    {
        $client = new MockHttpClient();
        $service = new WebhookService($this->webhookRepo, $this->em, $client, $this->secretService, $this->ssrf);

        $webhook = new Webhook();
        $webhook->isActive = false;

        $log = $service->send($webhook, 'test.event', ['key' => 'val']);
        $this->assertNull($log);
    }

    public function testSendBlocksSsrfDestination(): void
    {
        $client = new MockHttpClient();
        $service = new WebhookService($this->webhookRepo, $this->em, $client, $this->secretService, $this->ssrf);

        $webhook = new Webhook();
        $webhook->url = 'http://127.0.0.1:8000/internal';
        $webhook->isActive = true;

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $log = $service->send($webhook, 'ping', ['msg' => 'test']);
        $this->assertNotNull($log);
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('Blocked outbound request', $log->errorMessage ?? '');
    }

    public function testSendDeliversSuccessfullyWithHmacSignature(): void
    {
        $mockResponse = new MockResponse('{"received": true}', ['http_code' => 200]);
        $client = new MockHttpClient($mockResponse);
        $service = new WebhookService($this->webhookRepo, $this->em, $client, $this->secretService, $this->ssrf);

        $webhook = new Webhook();
        $webhook->url = 'https://example.com/receiver';
        $webhook->isActive = true;
        $webhook->secret = $this->secretService->encrypt('my-custom-test-secret-123456');

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $log = $service->send($webhook, 'content.created', ['id' => 42]);
        $this->assertNotNull($log);
        $this->assertSame('succeeded', $log->status);
        $this->assertSame(200, $log->statusCode);
        $this->assertSame('{"received": true}', $log->responseBody);
    }

    public function testSendIncludesMultiFormatSignaturesAndStandardHeaders(): void
    {
        $capturedRequest = null;
        $client = new MockHttpClient(function ($method, $url, $options) use (&$capturedRequest) {
            $capturedRequest = ['method' => $method, 'url' => $url, 'options' => $options];
            return new MockResponse('{"status":"ok"}', ['http_code' => 200]);
        });
        $service = new WebhookService($this->webhookRepo, $this->em, $client, $this->secretService, $this->ssrf);

        $webhook = new Webhook();
        $webhook->url = 'https://example.com/receiver';
        $webhook->isActive = true;
        $webhook->secret = $this->secretService->encrypt('signature-verification-secret');

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $log = $service->send($webhook, 'order.paid', ['amount' => 15000]);
        $this->assertNotNull($log);
        $this->assertSame('succeeded', $log->status);

        $this->assertNotNull($capturedRequest);
        $headers = $capturedRequest['options']['headers'] ?? [];
        $headersCombined = implode("\n", $headers);

        $this->assertStringContainsString('X-JamboApi-Signature: sha256=', $headersCombined);
        $this->assertStringContainsString('X-JamboApi-Signature-256: t=', $headersCombined);
        $this->assertStringContainsString('X-Hub-Signature-256: sha256=', $headersCombined);
        $this->assertStringContainsString('Webhook-Id:', $headersCombined);
        $this->assertStringContainsString('Webhook-Timestamp:', $headersCombined);
        $this->assertStringContainsString('X-JamboApi-Event: order.paid', $headersCombined);
    }

    public function testSendEnforcesOutboundRateLimit(): void
    {
        $limiterFactory = new \Symfony\Component\RateLimiter\RateLimiterFactory(
            ['id' => 'wh_out_test', 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '60 seconds'],
            new \Symfony\Component\RateLimiter\Storage\InMemoryStorage()
        );

        $project = new Project();
        $project->name = 'Rate Limited Project';
        $project->uuid = \Symfony\Component\Uid\Uuid::v4();
        $projectUuidStr = $project->uuid->toRfc4122();

        // Exhaust the 1-request quota
        $limiterFactory->create('wh_out_' . $projectUuidStr)->consume(1);

        $client = new MockHttpClient();
        $service = new WebhookService(
            $this->webhookRepo,
            $this->em,
            $client,
            $this->secretService,
            $this->ssrf,
            null,
            $limiterFactory
        );

        $webhook = new Webhook();
        $webhook->project = $project;
        $webhook->url = 'https://example.com/receiver';
        $webhook->isActive = true;

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $log = $service->send($webhook, 'user.created', ['email' => 'throttled@example.com']);
        $this->assertNotNull($log);
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('Outbound webhook rate limit exceeded', $log->errorMessage ?? '');
    }
}
