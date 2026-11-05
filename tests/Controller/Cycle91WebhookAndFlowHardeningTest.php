<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Automation;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\Webhook;
use App\Entity\WebhookLog;
use App\Repository\WebhookLogRepository;
use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowValidator;
use App\Service\Flow\Handlers\Action\CallWebhookHandler;
use App\Service\Flow\Handlers\Http\HttpRequestHandler;
use App\Service\Flow\NodeRegistry;
use App\Service\Security\SsrfProtectionService;
use App\Service\WebhookSecretService;
use App\Service\WebhookService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class Cycle91WebhookAndFlowHardeningTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $admin;
    private Project $project;
    private SsrfProtectionService $ssrf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get(EntityManagerInterface::class);
        $this->ssrf = new SsrfProtectionService();
        $hasher = $this->client->getContainer()->get(UserPasswordHasherInterface::class);

        $suffix = bin2hex(random_bytes(4));

        $this->admin = new User();
        $this->admin->name = 'Cycle91 Admin';
        $this->admin->email = "cycle91_admin_{$suffix}@test.com";
        $this->admin->password = $hasher->hashPassword($this->admin, 'SecurePass123!');
        $this->admin->roles = ['ROLE_SUPER_ADMIN'];
        $this->em->persist($this->admin);

        $this->project = new Project();
        $this->project->name = "Cycle91 Project {$suffix}";
        $this->em->persist($this->project);

        $this->em->flush();
    }

    public function testFlowValidatorRejectsPrivateIpsAndMetadata(): void
    {
        $validator = new FlowValidator($this->ssrf);
        $registry = $this->createStub(NodeRegistry::class);
        $registry->method('resolve')->willReturn($this->createStub(\App\Service\Flow\FlowNodeHandler::class));

        // RFC 1918 Private IP
        $graphPrivate = [
            'nodes' => [
                ['id' => 't1', 'type' => 'trigger.manual', 'data' => ['label' => 'T1']],
                ['id' => 'a1', 'type' => 'action.call_webhook', 'data' => [
                    'label' => 'A1',
                    'config' => ['url' => 'http://192.168.1.100/webhook'],
                ]],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 't1', 'target' => 'a1'],
            ],
        ];
        $res = $validator->validate($graphPrivate, $registry);
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('SSRF', $res['errors'][0]);

        // Cloud Metadata 169.254.169.254
        $graphMetadata = [
            'nodes' => [
                ['id' => 't1', 'type' => 'trigger.manual', 'data' => ['label' => 'T1']],
                ['id' => 'a1', 'type' => 'action.call_webhook', 'data' => [
                    'label' => 'A1',
                    'config' => ['url' => 'http://169.254.169.254/latest/meta-data/'],
                ]],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 't1', 'target' => 'a1'],
            ],
        ];
        $res2 = $validator->validate($graphMetadata, $registry);
        $this->assertFalse($res2['valid']);
        $this->assertStringContainsString('SSRF', $res2['errors'][0]);

        // CRLF injection in URL
        $graphCrlf = [
            'nodes' => [
                ['id' => 't1', 'type' => 'trigger.manual', 'data' => ['label' => 'T1']],
                ['id' => 'a1', 'type' => 'action.call_webhook', 'data' => [
                    'label' => 'A1',
                    'config' => ['url' => "https://example.com/api\r\nHost: evil.com"],
                ]],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 't1', 'target' => 'a1'],
            ],
        ];
        $res3 = $validator->validate($graphCrlf, $registry);
        $this->assertFalse($res3['valid']);
        $this->assertStringContainsString('control characters', $res3['errors'][0]);
    }

    public function testCallWebhookHandlerBlocksSsrfWithoutRetry(): void
    {
        $mockHttp = new MockHttpClient(function () {
            $this->fail('HTTP client should never be invoked when URL is blocked by SSRF policy.');
        });

        $handler = new CallWebhookHandler(
            ssrfProtection: $this->ssrf,
            httpClient: $mockHttp
        );

        $ctx = new FlowContext(automationId: 42, projectUuid: $this->project->uuid->toRfc4122());
        $ctx->setCurrentNodeConfig([
            'url' => 'http://10.0.0.1/admin',
            'retries' => 3,
        ]);

        $output = $handler->execute([], $ctx);

        $this->assertFalse($output->data['called']);
        $this->assertSame(0, $output->data['status_code']);
        $this->assertStringContainsString('SSRF', $output->data['error']);
    }

    public function testCallWebhookHandlerEnforcesRecursionLimit(): void
    {
        $mockHttp = new MockHttpClient([new MockResponse('ok', ['http_code' => 200])]);
        $handler = new CallWebhookHandler(
            ssrfProtection: $this->ssrf,
            httpClient: $mockHttp
        );

        $ctx = new FlowContext(automationId: 42, projectUuid: $this->project->uuid->toRfc4122());
        $ctx->variables['_hop_count'] = 5; // Hop limit reached
        $ctx->setCurrentNodeConfig([
            'url' => 'https://example.com/webhook',
        ]);

        $output = $handler->execute([], $ctx);

        $this->assertFalse($output->data['called']);
        $this->assertStringContainsString('recursion depth exceeded', $output->data['error']);
    }

    public function testCallWebhookHandlerPerformsRetryOn503(): void
    {
        $attemptCount = 0;
        $mockHttp = new MockHttpClient([
            function () use (&$attemptCount) {
                $attemptCount++;
                return new MockResponse('Service Unavailable', ['http_code' => 503]);
            },
            function () use (&$attemptCount) {
                $attemptCount++;
                return new MockResponse(json_encode(['status' => 'recovered']), ['http_code' => 200]);
            },
        ]);

        $handler = new CallWebhookHandler(
            ssrfProtection: $this->ssrf,
            httpClient: $mockHttp
        );

        $ctx = new FlowContext(automationId: 42, projectUuid: $this->project->uuid->toRfc4122());
        $ctx->setCurrentNodeConfig([
            'url' => 'https://example.com/service',
            'retries' => 2,
            'timeout' => 2.0,
        ]);

        $output = $handler->execute([], $ctx);

        $this->assertTrue($output->data['called']);
        $this->assertSame(200, $output->data['status_code']);
        $this->assertSame(2, $attemptCount);
        $this->assertSame(2, $output->data['attempts']);
    }

    public function testWebhookInboundRejectsHopCountLimitExceeded(): void
    {
        $automation = new Automation();
        $automation->project = $this->project;
        $automation->name = 'Inbound Recursion Test';
        $automation->isActive = true;
        $automation->flowGraph = [
            'nodes' => [
                ['id' => 't1', 'type' => 'trigger.webhook.inbound', 'data' => ['config' => []]],
            ],
            'edges' => [],
        ];
        $this->em->persist($automation);
        $this->em->flush();

        $this->client->request(
            'POST',
            '/api/webhooks/inbound/' . $automation->uuid->toRfc4122(),
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_JAMBOAPI_HOP_COUNT' => '6', // exceeds limit 5
            ],
            json_encode(['event' => 'ping'])
        );

        $this->assertResponseStatusCodeSame(429);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('AUTOMATION_LOOP_DETECTED', $data['code']);
    }

    public function testWebhookInboundRejectsDirectSelfRecursion(): void
    {
        $automation = new Automation();
        $automation->project = $this->project;
        $automation->name = 'Direct Self Recursion Test';
        $automation->isActive = true;
        $automation->flowGraph = [
            'nodes' => [
                ['id' => 't1', 'type' => 'trigger.webhook.inbound', 'data' => ['config' => []]],
            ],
            'edges' => [],
        ];
        $this->em->persist($automation);
        $this->em->flush();

        $this->client->request(
            'POST',
            '/api/webhooks/inbound/' . $automation->uuid->toRfc4122(),
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_JAMBOAPI_PROJECT' => $this->project->uuid->toRfc4122(),
                'HTTP_X_JAMBOAPI_AUTOMATION_ID' => (string) $automation->id,
            ],
            json_encode(['action' => 'self_ping'])
        );

        $this->assertResponseStatusCodeSame(400);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('DIRECT_RECURSION_DETECTED', $data['code']);
    }

    public function testWebhookServiceRejectsOversizedPayload(): void
    {
        $mockHttp = new MockHttpClient();
        $secretService = $this->client->getContainer()->get(WebhookSecretService::class);
        $webhookRepo = $this->em->getRepository(Webhook::class);

        $webhookService = new WebhookService(
            $webhookRepo,
            $this->em,
            $mockHttp,
            $secretService,
            $this->ssrf
        );

        $webhook = new Webhook();
        $webhook->project = $this->project;
        $webhook->name = 'Payload Test Webhook';
        $webhook->url = 'https://example.com/receiver';
        $webhook->isActive = true;
        $this->em->persist($webhook);
        $this->em->flush();

        // 600 KB payload (limit is 512 KB)
        $oversizedPayload = ['data' => str_repeat('A', 600 * 1024)];

        $log = $webhookService->send($webhook, 'test.oversized', $oversizedPayload);

        $this->assertNotNull($log);
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('exceeds', $log->errorMessage);
    }

    public function testPruneWebhookLogsEndpoint(): void
    {
        $this->client->loginUser($this->admin);

        $webhook = new Webhook();
        $webhook->project = $this->project;
        $webhook->name = 'Prune Test Webhook';
        $webhook->url = 'https://example.com/prune';
        $webhook->isActive = true;
        $this->em->persist($webhook);

        // Crée un log vieux de 45 jours
        $oldLog = new WebhookLog();
        $oldLog->webhook = $webhook;
        $oldLog->event = 'old.event';
        $oldLog->status = 'succeeded';
        $oldLog->createdAt = (new \DateTimeImmutable())->modify('-45 days');
        $this->em->persist($oldLog);

        // Crée un log récent (1 jour)
        $recentLog = new WebhookLog();
        $recentLog->webhook = $webhook;
        $recentLog->event = 'recent.event';
        $recentLog->status = 'succeeded';
        $recentLog->createdAt = (new \DateTimeImmutable())->modify('-1 day');
        $this->em->persist($recentLog);

        $this->em->flush();

        $projectUuid = $this->project->uuid->toString();
        $webhookUuid = $webhook->uuid->toString();

        // Test jours invalides (> 365)
        $this->client->request('POST', "/api/projects/{$projectUuid}/webhooks/{$webhookUuid}/logs/prune?days=999");
        $this->assertResponseStatusCodeSame(422);

        // Test purge valide (days=30)
        $this->client->request('POST', "/api/projects/{$projectUuid}/webhooks/{$webhookUuid}/logs/prune?days=30");
        $this->assertResponseStatusCodeSame(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertTrue($data['success']);
        $this->assertGreaterThanOrEqual(1, $data['deleted_count']);
        $this->assertSame(30, $data['retention_days']);
    }

    public function testHttpRequestHandlerHopCountLimitExceeded(): void
    {
        $translator = $this->client->getContainer()->get('translator');
        $handler = new HttpRequestHandler(ssrfProtection: $this->ssrf, translator: $translator);
        $ctx = new FlowContext(automationId: 1, projectUuid: 'demo-project');
        $ctx->variables['_hop_count'] = 5;
        $ctx->setCurrentNodeConfig([
            'url'    => 'https://example.com/api/notify',
            'method' => 'POST',
        ]);

        $res = $handler->execute([], $ctx);
        $this->assertFalse($res->data['called']);
        $this->assertSame(0, $res->data['status_code']);
        $this->assertStringContainsString('recursion depth exceeded', (string) ($res->data['error'] ?? ''));
    }

    public function testHttpRequestHandlerSsrfBlocked(): void
    {
        $translator = $this->client->getContainer()->get('translator');
        $handler = new HttpRequestHandler(ssrfProtection: $this->ssrf, translator: $translator);
        $ctx = new FlowContext(automationId: 1, projectUuid: 'demo-project');
        $ctx->setCurrentNodeConfig([
            'url'    => 'http://172.16.0.5/internal/status',
            'method' => 'GET',
        ]);

        $res = $handler->execute([], $ctx);
        $this->assertFalse($res->data['called']);
        $this->assertSame(0, $res->data['status_code']);
        $this->assertStringContainsString('SSRF', (string) ($res->data['error'] ?? ''));
    }

    public function testHttpRequestHandlerRetriesOn503(): void
    {
        $responses = [
            new MockResponse('Service Unavailable', ['http_code' => 503]),
            new MockResponse('{"status":"recovered"}', ['http_code' => 200]),
        ];
        $mockHttp = new MockHttpClient($responses);

        $handler = new HttpRequestHandler(ssrfProtection: $this->ssrf, httpClient: $mockHttp);
        $ctx = new FlowContext(automationId: 1, projectUuid: 'demo-project');
        $ctx->setCurrentNodeConfig([
            'url'     => 'https://example.com/api/flaky',
            'method'  => 'GET',
            'retries' => 1,
        ]);

        $res = $handler->execute([], $ctx);
        $this->assertTrue($res->data['called']);
        $this->assertSame(200, $res->data['status_code']);
        $this->assertSame(2, $res->data['attempts']);
    }

    public function testFlowValidatorI18nTranslations(): void
    {
        $translator = $this->client->getContainer()->get('translator');
        $validator = new FlowValidator($this->ssrf, $translator);
        $registry = $this->createStub(NodeRegistry::class);

        // Empty graph
        $res = $validator->validate([], $registry);
        $this->assertFalse($res['valid']);
        $this->assertNotEmpty($res['errors']);
        $this->assertStringContainsString('node', strtolower($res['errors'][0]));
    }
}
