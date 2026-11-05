<?php

declare(strict_types=1);

namespace App\Tests\Service\Flow\Handlers;

use App\Service\Flow\FlowContext;
use App\Service\Flow\Handlers\Action\CallWebhookHandler;
use App\Service\Flow\Handlers\Http\HttpRequestHandler;
use App\Service\Flow\NodeOutput;
use App\Service\Security\SsrfProtectionService;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class OutgoingWebhooksAndHttpHardeningTest extends TestCase
{
    private SsrfProtectionService $ssrf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ssrf = new SsrfProtectionService();
    }

    public function testCallWebhookRejectsDisallowedMethod(): void
    {
        $handler = new CallWebhookHandler($this->ssrf);
        $ctx = new FlowContext(1, 'proj-uuid', true);
        $ctx->setCurrentNodeConfig([
            'url'    => 'https://api.example.com/webhook',
            'method' => 'CONNECT',
        ]);

        $output = $handler->execute([], $ctx);
        $this->assertFalse($output->data['called']);
        $this->assertStringContainsString('Unsupported HTTP method', $output->data['error']);
    }

    public function testCallWebhookRejectsInvalidScheme(): void
    {
        $handler = new CallWebhookHandler($this->ssrf);
        $ctx = new FlowContext(1, 'proj-uuid', true);
        $ctx->setCurrentNodeConfig([
            'url'    => 'file:///etc/passwd',
            'method' => 'POST',
        ]);

        $output = $handler->execute([], $ctx);
        $this->assertFalse($output->data['called']);
        $this->assertStringContainsString('Unsupported protocol', $output->data['error']);
    }

    public function testCallWebhookRejectsOversizedUrl(): void
    {
        $handler = new CallWebhookHandler($this->ssrf);
        $ctx = new FlowContext(1, 'proj-uuid', true);
        $ctx->setCurrentNodeConfig([
            'url'    => 'https://api.example.com/' . str_repeat('a', 2050),
            'method' => 'POST',
        ]);

        $output = $handler->execute([], $ctx);
        $this->assertFalse($output->data['called']);
        $this->assertStringContainsString('exceeds 2048 characters limit', $output->data['error']);
    }

    public function testCallWebhookRejectsOversizedPayload(): void
    {
        $handler = new CallWebhookHandler($this->ssrf);
        $ctx = new FlowContext(1, 'proj-uuid', true);
        $ctx->setCurrentNodeConfig([
            'url'    => 'https://api.example.com/webhook',
            'method' => 'POST',
            'body'   => str_repeat('B', 2097153),
        ]);

        $output = $handler->execute([], $ctx);
        $this->assertFalse($output->data['called']);
        $this->assertStringContainsString('exceeds 2MB limit', $output->data['error']);
    }

    public function testCallWebhookInterpolationAndEnvBlacklist(): void
    {
        $mockHttpClient = $this->createMock(HttpClientInterface::class);
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getContent')->willReturn('{"ok":true}');

        $capturedOptions = [];
        $mockHttpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://httpbin.org/post/42',
                $this->callback(function (array $options) use (&$capturedOptions) {
                    $capturedOptions = $options;
                    return true;
                })
            )
            ->willReturn($mockResponse);

        $handler = new CallWebhookHandler($this->ssrf, $mockHttpClient);
        $ctx = new FlowContext(1, 'proj-uuid', true);
        $ctx->setVariable('channel', 'general');

        $input = [
            'prev' => new NodeOutput([
                'item_id' => 42,
                'secret_token' => 'leaked_token_xyz',
                'entry' => ['slug' => 'hello-world'],
            ]),
        ];

        $ctx->setCurrentNodeConfig([
            'url'     => 'https://httpbin.org/post/{{ item_id }}',
            'method'  => 'POST',
            'body'    => 'Slug: {{ entry.slug }}, Token: {{ secret_token }}, Env: {{ APP_SECRET }}',
            'headers' => [
                'X-Custom'          => 'value1',
                'Host'              => 'evil.internal', // Should be dropped
                'Transfer-Encoding' => 'chunked',      // Should be dropped
                'X-Injected'        => "val\r\nBad: bad", // Should be sanitized
            ],
        ]);

        $output = $handler->execute($input, $ctx);
        $this->assertTrue($output->data['called']);
        $this->assertSame(200, $output->data['status_code']);

        // Check sanitized headers
        $this->assertArrayNotHasKey('Host', $capturedOptions['headers']);
        $this->assertArrayNotHasKey('host', $capturedOptions['headers']);
        $this->assertArrayNotHasKey('Transfer-Encoding', $capturedOptions['headers']);
        $this->assertSame('value1', $capturedOptions['headers']['X-Custom']);

        // Check body interpolation
        $this->assertStringContainsString('Slug: hello-world', $capturedOptions['body']);
        $this->assertStringNotContainsString('leaked_token_xyz', $capturedOptions['body']);
        $this->assertStringNotContainsString('APP_SECRET', $capturedOptions['body']);
    }

    public function testHttpRequestHandlerSecurityAndSsrf(): void
    {
        $handler = new HttpRequestHandler($this->ssrf);
        $ctx = new FlowContext(1, 'proj-uuid', true);

        // SSRF blocked on AWS metadata
        $ctx->setCurrentNodeConfig([
            'url'    => 'http://169.254.169.254/latest/meta-data',
            'method' => 'GET',
        ]);
        $output = $handler->execute([], $ctx);
        $this->assertArrayHasKey('error', $output->data);
        $this->assertSame(0, $output->data['status_code']);

        // Method validation
        $ctx->setCurrentNodeConfig([
            'url'    => 'https://api.example.com',
            'method' => 'INVALID_METHOD',
        ]);
        $output = $handler->execute([], $ctx);
        $this->assertStringContainsString('Unsupported HTTP method', $output->data['error']);

        // Scheme validation
        $ctx->setCurrentNodeConfig([
            'url'    => 'gopher://127.0.0.1:25',
            'method' => 'GET',
        ]);
        $output = $handler->execute([], $ctx);
        $this->assertStringContainsString('Unsupported protocol', $output->data['error']);
    }

    public function testHttpRequestHandlerJsonHeadersParsing(): void
    {
        $mockHttpClient = $this->createMock(HttpClientInterface::class);
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('getStatusCode')->willReturn(200);
        $mockResponse->method('getContent')->willReturn('ok');
        $mockResponse->method('getHeaders')->willReturn([]);

        $capturedOptions = [];
        $mockHttpClient->expects($this->once())
            ->method('request')
            ->with(
                'GET',
                'https://httpbin.org/get',
                $this->callback(function (array $options) use (&$capturedOptions) {
                    $capturedOptions = $options;
                    return true;
                })
            )
            ->willReturn($mockResponse);

        $handler = new HttpRequestHandler($this->ssrf, $mockHttpClient);
        $ctx = new FlowContext(1, 'proj-uuid', true);
        $ctx->setCurrentNodeConfig([
            'url'     => 'https://httpbin.org/get',
            'method'  => 'GET',
            'headers' => '{"Accept": "application/json", "Host": "evil.org"}',
        ]);

        $output = $handler->execute([], $ctx);
        $this->assertSame(200, $output->data['status_code']);
        $this->assertSame('application/json', $capturedOptions['headers']['Accept']);
        $this->assertArrayNotHasKey('Host', $capturedOptions['headers']);
    }
}
