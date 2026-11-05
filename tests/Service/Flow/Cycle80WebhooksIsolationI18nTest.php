<?php

declare(strict_types=1);

namespace App\Tests\Service\Flow;

use App\Entity\Project;
use App\Entity\Webhook;
use App\Entity\WebhookLog;
use App\Message\SendWebhookMessage;
use App\MessageHandler\SendWebhookMessageHandler;
use App\Repository\WebhookRepository;
use App\Service\Flow\FlowContext;
use App\Service\Flow\Handlers\Action\CallWebhookHandler;
use App\Service\Flow\Handlers\Http\HttpRequestHandler;
use App\Service\Flow\NodeOutput;
use App\Service\Security\SsrfProtectionService;
use App\Service\WebhookSecretService;
use App\Service\WebhookService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class Cycle80WebhooksIsolationI18nTest extends TestCase
{
    private SsrfProtectionService $ssrf;
    private TranslatorInterface $translator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ssrf = new SsrfProtectionService();
        $this->translator = new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                $enTranslations = require __DIR__ . '/../../../translations/messages.en.php';
                $message = $enTranslations[$id] ?? $id;
                return strtr($message, $parameters);
            }
            public function getLocale(): string { return 'en'; }
        };
    }

    public function testCallWebhookRejectsMissingProjectContext(): void
    {
        $handler = new CallWebhookHandler($this->ssrf, null, null, $this->translator);
        $ctx = new FlowContext(automationId: 42, projectUuid: '   ');
        $ctx->setCurrentNodeConfig(['url' => 'https://api.example.com/hook']);

        $output = $handler->execute([], $ctx);

        $this->assertFalse($output->data['called']);
        $this->assertSame(0, $output->data['status_code']);
        $this->assertSame('Missing project context for outbound webhook.', $output->data['error']);
    }

    public function testCallWebhookRejectsEmptyAndControlCharsUrl(): void
    {
        $handler = new CallWebhookHandler($this->ssrf, null, null, $this->translator);
        $ctx = new FlowContext(automationId: 42, projectUuid: 'proj-123');

        // Empty URL
        $ctx->setCurrentNodeConfig(['url' => '   ']);
        $outEmpty = $handler->execute([], $ctx);
        $this->assertFalse($outEmpty->data['called']);
        $this->assertSame('Webhook URL is empty.', $outEmpty->data['error']);

        // Control characters / CRLF injection
        $ctx->setCurrentNodeConfig(['url' => "https://api.example.com/hook\r\nInjected-Header: bad"]);
        $outCrlf = $handler->execute([], $ctx);
        $this->assertFalse($outCrlf->data['called']);
        $this->assertSame('Webhook URL contains forbidden control characters.', $outCrlf->data['error']);
    }

    public function testCallWebhookRejectsCredentialsInUrl(): void
    {
        $handler = new CallWebhookHandler($this->ssrf, null, null, $this->translator);
        $ctx = new FlowContext(automationId: 42, projectUuid: 'proj-123');

        $ctx->setCurrentNodeConfig(['url' => 'https://admin:secretPass@api.example.com/hook']);
        $output = $handler->execute([], $ctx);

        $this->assertFalse($output->data['called']);
        $this->assertSame('Credentials in webhook URL are strictly forbidden.', $output->data['error']);
    }

    public function testCallWebhookStripsSpoofedHeadersAndInjectsTenantContext(): void
    {
        $mockHttpClient = $this->createMock(HttpClientInterface::class);
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getContent')->willReturn('{"ok":true}');

        $capturedHeaders = [];
        $mockHttpClient->expects($this->once())
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use ($mockResponse, &$capturedHeaders) {
                $capturedHeaders = $options['headers'] ?? [];
                return $mockResponse;
            });

        $mockSsrf = $this->createMock(SsrfProtectionService::class);
        $mockSsrf->method('buildSafeHttpClientOptions')->willReturnCallback(function ($url, $options) {
            return $options;
        });
        $mockSsrf->method('readSafeResponseBody')->willReturn('{"ok":true}');

        $handler = new CallWebhookHandler($mockSsrf, $mockHttpClient, null, $this->translator);
        $ctx = new FlowContext(automationId: 99, projectUuid: 'tenant-project-uuid-001');

        $ctx->setCurrentNodeConfig([
            'url' => 'https://api.example.com/webhook',
            'method' => 'POST',
            'body' => '{"foo":"bar"}',
            'headers' => [
                'X-Custom-Header' => 'SafeValue',
                'X-JamboApi-Project' => 'malicious-spoofed-tenant-id',
                'X-JamboApi-Automation-Id' => '666',
                'Host' => 'evil.com',
                'Connection' => 'close',
            ],
        ]);

        $output = $handler->execute([], $ctx);

        $this->assertTrue($output->data['called']);
        $this->assertSame(200, $output->data['status_code']);

        // Verify spoofed headers were stripped and platform authentic headers were injected
        $this->assertSame('tenant-project-uuid-001', $capturedHeaders['X-JamboApi-Project']);
        $this->assertSame('99', $capturedHeaders['X-JamboApi-Automation-Id']);
        $this->assertSame($ctx->runId, $capturedHeaders['X-JamboApi-Run-Id']);
        $this->assertSame('SafeValue', $capturedHeaders['X-Custom-Header']);
        $this->assertArrayNotHasKey('Host', $capturedHeaders);
        $this->assertArrayNotHasKey('Connection', $capturedHeaders);
    }

    public function testCallWebhookDecryptsEncryptedSecret(): void
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

        $mockSecretService = $this->createMock(WebhookSecretService::class);
        $mockSecretService->expects($this->once())
            ->method('decrypt')
            ->with('ciphertext123')
            ->willReturn('plainSecretKey');

        $handler = new CallWebhookHandler($mockSsrf, $mockHttpClient, $mockSecretService, $this->translator);
        $ctx = new FlowContext(automationId: 10, projectUuid: 'tenant-uuid');

        $ctx->setCurrentNodeConfig([
            'url' => 'https://api.example.com/webhook',
            'method' => 'POST',
            'body' => '{"alert":"disk_full"}',
            'secret' => 'enc:ciphertext123',
        ]);

        $output = $handler->execute([], $ctx);

        $this->assertTrue($output->data['called']);
        $this->assertArrayHasKey('X-JamboApi-Signature', $capturedHeaders);
        $this->assertArrayHasKey('X-JamboApi-Signature-256', $capturedHeaders);
        $expectedHmac = 'sha256=' . hash_hmac('sha256', '{"alert":"disk_full"}', 'plainSecretKey');
        $this->assertSame($expectedHmac, $capturedHeaders['X-JamboApi-Signature']);
    }

    public function testCallWebhookSecretDecryptionFailureHandledGracefully(): void
    {
        $mockSecretService = $this->createMock(WebhookSecretService::class);
        $mockSecretService->expects($this->once())
            ->method('decrypt')
            ->willThrowException(new \RuntimeException('Decryption failed'));

        $handler = new CallWebhookHandler($this->ssrf, null, $mockSecretService, $this->translator);
        $ctx = new FlowContext(automationId: 10, projectUuid: 'tenant-uuid');

        $ctx->setCurrentNodeConfig([
            'url' => 'https://api.example.com/webhook',
            'secret' => 'enc:corruptedCipher',
        ]);

        $output = $handler->execute([], $ctx);

        $this->assertFalse($output->data['called']);
        $this->assertSame('Failed to decrypt webhook secret.', $output->data['error']);
    }

    public function testHttpRequestRejectsMissingProjectContext(): void
    {
        $handler = new HttpRequestHandler($this->ssrf, null, $this->translator);
        $ctx = new FlowContext(automationId: 42, projectUuid: '');
        $ctx->setCurrentNodeConfig(['url' => 'https://api.example.com/data']);

        $output = $handler->execute([], $ctx);

        $this->assertFalse($output->data['called']);
        $this->assertSame(0, $output->data['status_code']);
        $this->assertSame('Missing project context for outbound HTTP request.', $output->data['error']);
    }

    public function testHttpRequestRejectsCredentialsInUrlAndControlChars(): void
    {
        $handler = new HttpRequestHandler($this->ssrf, null, $this->translator);
        $ctx = new FlowContext(automationId: 42, projectUuid: 'tenant-abc');

        // Credentials
        $ctx->setCurrentNodeConfig(['url' => 'https://admin:pass@api.example.com/test']);
        $outCreds = $handler->execute([], $ctx);
        $this->assertFalse($outCreds->data['called']);
        $this->assertSame('Credentials in HTTP URL are strictly forbidden.', $outCreds->data['error']);

        // Control characters
        $ctx->setCurrentNodeConfig(['url' => "https://api.example.com/test\x00inject"]);
        $outChars = $handler->execute([], $ctx);
        $this->assertFalse($outChars->data['called']);
        $this->assertSame('HTTP URL contains forbidden control characters.', $outChars->data['error']);
    }

    public function testHttpRequestStripsSpoofedHeadersAndInjectsTenantContext(): void
    {
        $mockHttpClient = $this->createMock(HttpClientInterface::class);
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getContent')->willReturn('{"result":"ok"}');
        $mockResponse->method('getHeaders')->willReturn(['content-type' => ['application/json']]);

        $capturedHeaders = [];
        $mockHttpClient->expects($this->once())
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use ($mockResponse, &$capturedHeaders) {
                $capturedHeaders = $options['headers'] ?? [];
                return $mockResponse;
            });

        $mockSsrf = $this->createMock(SsrfProtectionService::class);
        $mockSsrf->method('buildSafeHttpClientOptions')->willReturnCallback(function ($url, $options) {
            return $options;
        });
        $mockSsrf->method('readSafeResponseBody')->willReturn('{"result":"ok"}');

        $handler = new HttpRequestHandler($mockSsrf, $mockHttpClient, $this->translator);
        $ctx = new FlowContext(automationId: 77, projectUuid: 'tenant-proj-456');

        $ctx->setCurrentNodeConfig([
            'url' => 'https://api.example.com/fetch',
            'method' => 'GET',
            'headers' => [
                'Authorization' => 'Bearer sample-token',
                'X-JamboApi-Project' => 'impersonated-uuid',
                'Transfer-Encoding' => 'chunked',
            ],
        ]);

        $output = $handler->execute([], $ctx);

        $this->assertTrue($output->data['called']);
        $this->assertSame(200, $output->data['status_code']);
        $this->assertSame('tenant-proj-456', $capturedHeaders['X-JamboApi-Project']);
        $this->assertSame('77', $capturedHeaders['X-JamboApi-Automation-Id']);
        $this->assertSame($ctx->runId, $capturedHeaders['X-JamboApi-Run-Id']);
        $this->assertSame('Bearer sample-token', $capturedHeaders['Authorization']);
        $this->assertArrayNotHasKey('Transfer-Encoding', $capturedHeaders);
    }

    public function testSendWebhookMessageValidatesTenantProject(): void
    {
        $projectA = new Project();
        $projectA->uuid = Uuid::fromString('11111111-1111-1111-1111-111111111111');

        $webhook = new Webhook();
        $webhook->id = 5;
        $webhook->isActive = true;
        $webhook->project = $projectA;
        $webhook->url = 'https://api.example.com/webhook';

        $webhookRepo = $this->createMock(WebhookRepository::class);
        $webhookRepo->method('find')->with(5)->willReturn($webhook);

        $em = $this->createMock(EntityManagerInterface::class);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $secretService = $this->createMock(WebhookSecretService::class);
        $webhookService = $this->createMock(WebhookService::class);
        $logger = $this->createMock(LoggerInterface::class);

        // Expectation: webhookService->send must NEVER be called when tenant mismatches
        $webhookService->expects($this->never())->method('send');
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Webhook delivery rejected due to tenant project mismatch',
                $this->callback(function (array $context) {
                    return $context['webhook_id'] === 5
                        && $context['expected_project'] === '22222222-2222-2222-2222-222222222222'
                        && $context['actual_project'] === '11111111-1111-1111-1111-111111111111';
                })
            );

        $handler = new SendWebhookMessageHandler(
            $webhookRepo,
            $em,
            $httpClient,
            $secretService,
            $this->ssrf,
            $webhookService,
            $logger,
            $this->translator
        );

        // Message claims project B (mismatch with webhook's project A)
        $msg = new SendWebhookMessage(
            webhookId: 5,
            event: 'content.created',
            payload: ['data' => 'test'],
            projectUuid: '22222222-2222-2222-2222-222222222222'
        );

        $handler($msg);
    }

    public function testSendWebhookMessageProceedsWhenTenantMatches(): void
    {
        $projectA = new Project();
        $projectA->uuid = Uuid::fromString('11111111-1111-1111-1111-111111111111');

        $webhook = new Webhook();
        $webhook->id = 5;
        $webhook->isActive = true;
        $webhook->project = $projectA;
        $webhook->url = 'https://api.example.com/webhook';

        $webhookRepo = $this->createMock(WebhookRepository::class);
        $webhookRepo->method('find')->with(5)->willReturn($webhook);

        $em = $this->createMock(EntityManagerInterface::class);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $secretService = $this->createMock(WebhookSecretService::class);
        $webhookService = $this->createMock(WebhookService::class);
        $logger = $this->createMock(LoggerInterface::class);

        // Expectation: webhookService->send IS called
        $webhookService->expects($this->once())
            ->method('send')
            ->with($webhook, 'content.created', ['data' => 'test']);

        $handler = new SendWebhookMessageHandler(
            $webhookRepo,
            $em,
            $httpClient,
            $secretService,
            $this->ssrf,
            $webhookService,
            $logger,
            $this->translator
        );

        $msg = new SendWebhookMessage(
            webhookId: 5,
            event: 'content.created',
            payload: ['data' => 'test'],
            projectUuid: '11111111-1111-1111-1111-111111111111'
        );

        $handler($msg);
    }

    public function testWebhookServiceInjectsTenantHeaderAndTranslatesErrors(): void
    {
        $project = new Project();
        $project->uuid = Uuid::fromString('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');

        $webhook = new Webhook();
        $webhook->id = 12;
        $webhook->isActive = true;
        $webhook->project = $project;
        $webhook->url = 'https://api.example.com/hook';

        $webhookRepo = $this->createMock(WebhookRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $mockHttpClient = $this->createMock(HttpClientInterface::class);
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getContent')->willReturn('OK');

        $capturedHeaders = [];
        $mockHttpClient->expects($this->once())
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $options) use ($mockResponse, &$capturedHeaders) {
                $capturedHeaders = $options['headers'] ?? [];
                return $mockResponse;
            });

        $secretService = $this->createMock(WebhookSecretService::class);
        $mockSsrf = $this->createMock(SsrfProtectionService::class);
        $mockSsrf->method('isSafeUrl')->willReturn(true);
        $mockSsrf->method('buildSafeHttpClientOptions')->willReturnCallback(function ($url, $options) {
            return $options;
        });
        $mockSsrf->method('readSafeResponseBody')->willReturn('OK');

        $service = new WebhookService(
            $webhookRepo,
            $em,
            $mockHttpClient,
            $secretService,
            $mockSsrf,
            $this->translator
        );

        $log = $service->send($webhook, 'entry.published', ['id' => 101]);

        $this->assertNotNull($log);
        $this->assertSame('succeeded', $log->status);
        $this->assertSame('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $capturedHeaders['X-JamboApi-Project']);
        $this->assertSame('entry.published', $capturedHeaders['X-JamboApi-Event']);
    }

    public function testWebhookServiceTranslatesSsrfAndJsonErrors(): void
    {
        $project = new Project();
        $project->uuid = Uuid::fromString('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');

        $webhook = new Webhook();
        $webhook->id = 15;
        $webhook->isActive = true;
        $webhook->project = $project;
        $webhook->url = 'http://169.254.169.254/latest/meta-data';

        $webhookRepo = $this->createMock(WebhookRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $secretService = $this->createMock(WebhookSecretService::class);

        $service = new WebhookService(
            $webhookRepo,
            $em,
            $httpClient,
            $secretService,
            $this->ssrf,
            $this->translator
        );

        // 1. SSRF Blocked translation
        $logSsrf = $service->send($webhook, 'alert', ['msg' => 'test']);
        $this->assertNotNull($logSsrf);
        $this->assertSame('failed', $logSsrf->status);
        $this->assertStringContainsString('Blocked outbound request to private/internal IP:', $logSsrf->errorMessage);

        // 2. JSON encoding error translation
        $validWebhook = new Webhook();
        $validWebhook->id = 16;
        $validWebhook->isActive = true;
        $validWebhook->project = $project;
        $validWebhook->url = 'https://example.com/webhook';

        $badPayload = ['bad_utf8' => "\xB1\x31"];
        $logJson = $service->send($validWebhook, 'event', $badPayload);
        $this->assertNotNull($logJson);
        $this->assertSame('failed', $logJson->status);
        $this->assertStringContainsString('JSON encoding error:', $logJson->errorMessage);
    }

    public function testInterpolationMasksCookiesSessionsAndSecretsInCallWebhookAndHttp(): void
    {
        $mockHttpClient = $this->createMock(HttpClientInterface::class);
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getContent')->willReturn('{}');

        $capturedBodyWebhook = '';
        $mockHttpClient->expects($this->once())
            ->method('request')
            ->willReturnCallback(function ($method, $url, $options) use ($mockResponse, &$capturedBodyWebhook) {
                $capturedBodyWebhook = $options['body'] ?? '';
                return $mockResponse;
            });

        $mockSsrf = $this->createMock(SsrfProtectionService::class);
        $mockSsrf->method('buildSafeHttpClientOptions')->willReturnCallback(fn($u, $o) => $o);
        $mockSsrf->method('readSafeResponseBody')->willReturn('{}');

        $handler = new CallWebhookHandler($mockSsrf, $mockHttpClient, null, $this->translator);
        $ctx = new FlowContext(1, 'proj-uuid');

        $input = [
            'prev' => new NodeOutput([
                'user_name' => 'JohnDoe',
                'cookie' => 'PHPSESSID=attacker-session',
                'session_id' => 'sess_123456',
                'private_key' => 'MIICXAIBAAKCAQEA0',
            ]),
        ];

        $ctx->setCurrentNodeConfig([
            'url' => 'https://example.com/webhook',
            'body' => 'User: {{ user_name }}, Cookie: {{ cookie }}, Sess: {{ session_id }}, Key: {{ private_key }}, Env: {{ APP_SECRET }}',
        ]);

        $output = $handler->execute($input, $ctx);
        $this->assertTrue($output->data['called']);
        $this->assertStringContainsString('User: JohnDoe', $capturedBodyWebhook);
        $this->assertStringNotContainsString('PHPSESSID', $capturedBodyWebhook);
        $this->assertStringNotContainsString('sess_123456', $capturedBodyWebhook);
        $this->assertStringNotContainsString('MIICXAIBAAKCAQEA0', $capturedBodyWebhook);
        $this->assertStringNotContainsString('APP_SECRET', $capturedBodyWebhook);
    }

    public function testCycle80TranslationsArePresentInAllDictionaries(): void
    {
        $languages = ['en', 'fr', 'es', 'ar'];
        $expectedKeys = [
            'flow.call_webhook.unsupported_method',
            'flow.call_webhook.url_empty',
            'flow.call_webhook.url_too_long',
            'flow.call_webhook.invalid_url',
            'flow.call_webhook.unsupported_protocol',
            'flow.call_webhook.url_control_chars',
            'flow.call_webhook.credentials_forbidden',
            'flow.call_webhook.payload_too_large',
            'flow.call_webhook.missing_project_context',
            'flow.call_webhook.secret_decrypt_error',
            'flow.http_request.unsupported_method',
            'flow.http_request.url_empty',
            'flow.http_request.url_too_long',
            'flow.http_request.invalid_url',
            'flow.http_request.unsupported_protocol',
            'flow.http_request.url_control_chars',
            'flow.http_request.credentials_forbidden',
            'flow.http_request.payload_too_large',
            'flow.http_request.missing_project_context',
            'webhook.tenant_mismatch',
            'webhook.json_encode_error',
            'webhook.secret_decrypt_error',
            'webhook.ssrf_blocked',
        ];

        foreach ($languages as $lang) {
            $file = __DIR__ . "/../../../translations/messages.{$lang}.php";
            $this->assertFileExists($file, "Translation file messages.{$lang}.php must exist.");
            $dict = require $file;
            $this->assertIsArray($dict, "Translation file messages.{$lang}.php must return an array.");

            foreach ($expectedKeys as $key) {
                $this->assertArrayHasKey($key, $dict, "Missing translation key '{$key}' in messages.{$lang}.php");
                $this->assertNotEmpty($dict[$key], "Empty translation value for '{$key}' in messages.{$lang}.php");
            }
        }
    }
}
