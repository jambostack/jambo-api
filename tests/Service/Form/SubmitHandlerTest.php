<?php
namespace App\Tests\Service\Form;

use App\Entity\Form;
use App\Service\Form\AntiSpamService;
use App\Service\Form\FormBuilder;
use App\Service\Form\SubmitHandler;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Validator\Validation;

class SubmitHandlerTest extends TestCase
{
    private SubmitHandler $handler;
    private Form $form;
    private AntiSpamService $antiSpamService;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $limiterFactory = new RateLimiterFactory(
            ['id' => 'submit_test', 'policy' => 'sliding_window', 'limit' => 100, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );

        $this->antiSpamService = new AntiSpamService($limiterFactory);
        $formBuilder = new FormBuilder();
        $validator = Validation::createValidator();
        $this->em = $this->createMock(EntityManagerInterface::class);

        $this->handler = new SubmitHandler(
            $formBuilder,
            $this->antiSpamService,
            $validator,
            $this->em,
        );

        $this->form = new Form();
        $this->form->fields = [
            ['name' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true],
            ['name' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true],
        ];
        $this->form->settings = [];
    }

    public function testHandleSuccessfulSubmission(): void
    {
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $request = new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'TestAgent']);

        $submission = $this->handler->handle($this->form, [
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ], $request);

        $this->assertTrue($submission->isComplete);
        $this->assertFalse($submission->isSpam);
        $this->assertSame('John Doe', $submission->data['name']);
        $this->assertSame('127.0.0.1', $submission->metadata['ip']);
        $this->assertSame('TestAgent', $submission->metadata['user_agent']);
    }

    public function testHandleThrowsOnValidationFailure(): void
    {
        $this->expectException(\Symfony\Component\Validator\Exception\ValidationFailedException::class);

        $request = new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);

        $this->handler->handle($this->form, [
            'name' => '',
            'email' => 'not-an-email',
        ], $request);
    }

    public function testHandleDetectsHoneypotSpam(): void
    {
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $request = new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);

        $submission = $this->handler->handle($this->form, [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            '_website' => 'http://spam.com',
        ], $request);

        $this->assertTrue($submission->isSpam);
        $this->assertContains('honeypot', $submission->metadata['spam_reasons']);
    }

    public function testHandleDetectsBlocklistedEmail(): void
    {
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $request = new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);

        $submission = $this->handler->handle($this->form, [
            'name' => 'John Doe',
            'email' => 'test@mailinator.com',
        ], $request);

        $this->assertTrue($submission->isSpam);
        $this->assertContains('blocklisted_domain', $submission->metadata['spam_reasons']);
    }

    public function testHandleDetectsSpamPatterns(): void
    {
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $request = new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);

        $submission = $this->handler->handle($this->form, [
            'name' => 'Buy now viagra casino',
            'email' => 'john@example.com',
        ], $request);

        $this->assertTrue($submission->isSpam);
        $this->assertContains('spam_patterns', $submission->metadata['spam_reasons']);
    }

    public function testHandleWithConditionalFields(): void
    {
        $this->form->fields = [
            ['name' => 'contact_method', 'type' => 'select', 'label' => 'Method'],
            ['name' => 'phone', 'type' => 'tel', 'label' => 'Phone', 'required' => true,
                'conditions' => ['field' => 'contact_method', 'operator' => 'equals', 'value' => 'phone']],
        ];

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $request = new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);

        // phone field is hidden because contact_method != phone, so no validation error for missing phone
        $submission = $this->handler->handle($this->form, [
            'contact_method' => 'email',
        ], $request);

        $this->assertTrue($submission->isComplete);
        $this->assertFalse($submission->isSpam);
    }

    public function testHandleCapturesMetadata(): void
    {
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => '192.168.1.50',
                'HTTP_USER_AGENT' => 'Mozilla/5.0',
                'HTTP_REFERER' => 'https://example.com/contact',
            ]
        );

        $submission = $this->handler->handle($this->form, [
            'name' => 'Jane',
            'email' => 'jane@example.com',
        ], $request);

        $this->assertSame('192.168.1.50', $submission->metadata['ip']);
        $this->assertSame('Mozilla/5.0', $submission->metadata['user_agent']);
        $this->assertSame('https://example.com/contact', $submission->metadata['referrer']);
    }

    public function testHandleRateLimit(): void
    {
        $this->em->expects($this->exactly(2))->method('persist');
        $this->em->expects($this->exactly(2))->method('flush');

        // Use a more restrictive limiter: 1 request per minute
        $limiterFactory = new RateLimiterFactory(
            ['id' => 'restrictive', 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '60 seconds'],
            new InMemoryStorage(),
        );
        $restrictiveSpam = new AntiSpamService($limiterFactory);
        $handler = new SubmitHandler(
            new FormBuilder(),
            $restrictiveSpam,
            Validation::createValidator(),
            $this->em,
        );

        $request = new Request([], [], [], [], [], ['REMOTE_ADDR' => '10.0.0.1']);

        // First request should succeed
        $first = $handler->handle($this->form, ['name' => 'John', 'email' => 'john@example.com'], $request);

        // But we passed a shared EM mock, so this second call will also call persist once.
        // Since EM is a mock, just verify the submission is marked as spam
        $this->assertFalse($first->isSpam);

        $second = $handler->handle($this->form, ['name' => 'John', 'email' => 'john@example.com'], $request);
        $this->assertTrue($second->isSpam);
        $this->assertContains('rate_limit', $second->metadata['spam_reasons']);
    }
}
