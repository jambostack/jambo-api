<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Automation;
use App\Entity\AutomationRun;
use App\Message\Attachment;
use App\Message\ExecuteAutomationMessage;
use App\Message\ExecuteSubFlowMessage;
use App\Message\SendProjectEmailMessage;
use App\Message\SendWebhookMessage;
use App\MessageHandler\ExecuteAutomationMessageHandler;
use App\MessageHandler\SendWebhookMessageHandler;
use App\Repository\AutomationRepository;
use App\Repository\AutomationRunRepository;
use App\Repository\WebhookRepository;
use App\Service\Automation\AutomationEngine;
use App\Service\Error\JsonErrorHandler;
use App\Service\Flow\FlowContext;
use App\Service\Flow\FlowInterpreter;
use App\Service\Flow\Handlers\Logic\DelayHandler;
use App\Service\Security\SsrfProtectionService;
use App\Service\WebhookSecretService;
use App\Service\WebhookService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class MessengerHardeningTest extends TestCase
{
    public function testExecuteAutomationMessageValidatesIds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ExecuteAutomationMessage(0, 1, [], 'uuid-1', false);
    }

    public function testExecuteAutomationMessageValidatesRunId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ExecuteAutomationMessage(1, -5, [], 'uuid-1', false);
    }

    public function testExecuteAutomationMessageSetsTimestamp(): void
    {
        $msg = new ExecuteAutomationMessage(1, 10, ['k' => 'v'], 'uuid-1', false);
        $this->assertGreaterThan(0, $msg->dispatchedAt);
        $this->assertSame(86400, $msg->ttlSeconds);
    }

    public function testExecuteAutomationHandlerExpiresStaleMessage(): void
    {
        $run = new AutomationRun();
        $run->status = 'pending';

        $runRepo = $this->createMock(AutomationRunRepository::class);
        $runRepo->method('find')->with(123)->willReturn($run);

        $autoRepo = $this->createMock(AutomationRepository::class);
        $engine = $this->createMock(AutomationEngine::class);
        $engine->expects($this->never())->method('execute');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Message expired.');

        $handler = new ExecuteAutomationMessageHandler($autoRepo, $runRepo, $engine, $em, $translator);

        // Dispatched 2 hours ago with 1 hour TTL
        $staleMsg = new ExecuteAutomationMessage(1, 123, [], 'uuid-1', false, time() - 7200, 3600);
        $handler($staleMsg);

        $this->assertSame('failed', $run->status);
        $this->assertSame('Message expired.', $run->errorMessage);
        $this->assertNotNull($run->finishedAt);
    }

    public function testExecuteAutomationHandlerIdempotentOnCompletedRun(): void
    {
        $run = new AutomationRun();
        $run->status = 'success';

        $runRepo = $this->createMock(AutomationRunRepository::class);
        $runRepo->method('find')->with(456)->willReturn($run);

        $autoRepo = $this->createMock(AutomationRepository::class);
        $engine = $this->createMock(AutomationEngine::class);
        $engine->expects($this->never())->method('execute');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $handler = new ExecuteAutomationMessageHandler($autoRepo, $runRepo, $engine, $em);
        $msg = new ExecuteAutomationMessage(1, 456, [], 'uuid-1', false);
        $handler($msg);

        $this->assertSame('success', $run->status);
    }

    public function testExecuteSubFlowMessageValidatesDepth(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ExecuteSubFlowMessage('parent-1', 1, [], [], [], 'node-1', 'uuid-1', false, depth: 6);
    }

    public function testAttachmentSanitizesFilenameAndValidatesMime(): void
    {
        $att = new Attachment('test content', "../../bad\r\nname.pdf\0", "image/png\r\ninjected");
        $this->assertSame('badname.pdf', $att->filename);
        $this->assertSame('application/octet-stream', $att->mimeType);

        $serialized = $att->jsonSerialize();
        $this->assertSame('badname.pdf', $serialized['filename']);
        $this->assertSame(base64_encode('test content'), $serialized['content']);

        $restored = Attachment::fromArray($serialized);
        $this->assertSame('test content', $restored->content);
        $this->assertSame('badname.pdf', $restored->filename);
    }

    public function testAttachmentRejectsExcessiveFileSize(): void
    {
        $this->expectException(\LengthException::class);
        $huge = str_repeat('A', Attachment::MAX_FILE_SIZE + 10);
        new Attachment($huge, 'test.pdf');
    }

    public function testSendProjectEmailMessageRejectsTotalExcessiveAttachmentSize(): void
    {
        $this->expectException(\LengthException::class);
        // 3 attachments of 9MB each = 27MB > 25MB limit
        $payload = str_repeat('X', 9 * 1024 * 1024);
        $att1 = new Attachment($payload, 'file1.bin');
        $att2 = new Attachment($payload, 'file2.bin');
        $att3 = new Attachment($payload, 'file3.bin');

        new SendProjectEmailMessage(
            host: 'smtp.example.com',
            port: 587,
            username: 'user',
            encryptedPassword: 'secret',
            encryption: 'tls',
            fromEmail: 'noreply@example.com',
            fromName: 'Jambo',
            to: 'target@example.com',
            subject: 'Hello',
            body: 'Body',
            attachments: [$att1, $att2, $att3],
        );
    }

    public function testDelayHandlerCapsMaxDelay(): void
    {
        $handler = new DelayHandler();
        $ctx = new FlowContext(automationId: 1, projectUuid: 'uuid-1');
        $ctx->variables['_node_config'] = ['delay_ms' => 999999]; // 1000 seconds requested

        $start = microtime(true);
        // DelayHandler caps to 30000ms. But to not wait 30s in test, test with small delay
        $ctx->variables['_node_config'] = ['delay_ms' => 5];
        $output = $handler->execute([], $ctx);
        $this->assertSame(5, $output->data['delayed_ms']);
        $this->assertLessThan(1.0, microtime(true) - $start);
        $this->assertSame(30000, DelayHandler::MAX_DELAY_MS);
    }

    public function testJsonErrorHandlerEnforcesStrictSecurityHeaders(): void
    {
        $handler = new JsonErrorHandler('prod');
        $request = Request::create('/api/projects');
        $exception = new \RuntimeException('Database credentials leaked in test!');

        $response = $handler->createResponse($exception, $request);

        $this->assertSame(500, $response->getStatusCode());
        // Message is masked in prod
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('An unexpected error occurred.', $data['error']);
        $this->assertSame('INTERNAL_ERROR', $data['code']);

        // Anti-caching headers
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertSame('no-cache', $response->headers->get('Pragma'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('none', $response->headers->get('X-Permitted-Cross-Domain-Policies'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
    }
}
