<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\Form;
use App\Entity\FormSubmission;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use App\Event\FormSubmittedEvent;
use App\EventSubscriber\FormSubmissionSubscriber;
use App\Message\SendWebhookMessage;
use App\Repository\UserRepository;
use App\Service\MercurePublisher;
use App\Service\NotificationService;
use App\Service\Security\SsrfProtectionService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

class FormSubmissionSubscriberTest extends TestCase
{
    public function testSubscribedEventsListensToFormSubmitted(): void
    {
        $events = FormSubmissionSubscriber::getSubscribedEvents();
        $this->assertArrayHasKey(FormSubmittedEvent::SUBMITTED, $events);
        $this->assertSame('onFormSubmitted', $events[FormSubmittedEvent::SUBMITTED]);
    }

    public function testOnFormSubmittedPublishesRealtimeAndNotifiesProjectMembers(): void
    {
        $mercurePublisher = $this->createMock(MercurePublisher::class);
        $notificationService = $this->createMock(NotificationService::class);

        $subscriber = new FormSubmissionSubscriber(
            mercurePublisher: $mercurePublisher,
            notificationService: $notificationService,
            logger: new NullLogger(),
        );

        $project = new Project();
        $project->uuid = Uuid::v4();
        $project->name = 'Test Project';

        $user = new User();
        $user->id = 42;
        $user->name = 'Alice';
        $user->email = 'alice@example.com';

        $member = new ProjectMember();
        $member->user = $user;
        $member->project = $project;
        $member->status = ProjectMemberStatus::Active;
        $project->projectMembers = new ArrayCollection([$member]);

        $form = new Form();
        $form->id = 7;
        $form->name = 'Contact Us';
        $form->slug = 'contact-us';
        $form->project = $project;
        $form->settings = [];

        $submission = new FormSubmission();
        $submission->id = 101;
        $submission->form = $form;
        $submission->isSpam = false;
        $submission->createdAt = new \DateTimeImmutable('2026-11-06T15:00:00Z');

        $data = ['email' => 'visitor@example.com', 'message' => 'Hello Jambo!'];
        $event = new FormSubmittedEvent($form, $submission, $project, $data);

        // Expectation 1: Mercure formSubmitted is called
        $mercurePublisher->expects($this->once())
            ->method('formSubmitted')
            ->with(
                $project->uuid->toRfc4122(),
                'contact-us',
                $this->callback(function (array $s) {
                    return ($s['id'] ?? null) === 101 && ($s['isSpam'] ?? null) === false;
                }),
                'Contact Us'
            );

        // Expectation 2: NotificationService notifyFormSubmission is called for Alice
        $notificationService->expects($this->once())
            ->method('notifyFormSubmission')
            ->with(
                $user,
                'Contact Us',
                'contact-us',
                $project,
                101
            );

        $subscriber->onFormSubmitted($event);
    }

    public function testOnFormSubmittedDispatchesWebhookWhenConfiguredAndAllowed(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $ssrf = $this->createMock(SsrfProtectionService::class);

        $subscriber = new FormSubmissionSubscriber(
            bus: $bus,
            ssrfProtection: $ssrf,
            logger: new NullLogger(),
        );

        $project = new Project();
        $project->uuid = Uuid::v4();
        $project->name = 'Webhook Project';

        $form = new Form();
        $form->id = 8;
        $form->name = 'Lead Capture';
        $form->slug = 'lead-capture';
        $form->project = $project;
        $form->settings = [
            'webhook' => [
                'enabled' => true,
                'url' => 'https://example.com/webhook/forms',
                'webhook_id' => 99,
            ],
        ];

        $submission = new FormSubmission();
        $submission->id = 102;
        $submission->form = $form;
        $submission->isSpam = false;
        $submission->createdAt = new \DateTimeImmutable();

        $ssrf->expects($this->once())
            ->method('isSafeUrl')
            ->with('https://example.com/webhook/forms')
            ->willReturn(true);

        $bus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($msg) use ($project) {
                return $msg instanceof SendWebhookMessage
                    && $msg->webhookId === 99
                    && $msg->event === 'form.submitted'
                    && $msg->projectUuid === $project->uuid->toRfc4122();
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $event = new FormSubmittedEvent($form, $submission, $project, ['name' => 'Bob']);
        $subscriber->onFormSubmitted($event);
    }

    public function testOnFormSubmittedBlocksPrivateWebhookUnderSsrfProtection(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $ssrf = $this->createMock(SsrfProtectionService::class);

        $subscriber = new FormSubmissionSubscriber(
            bus: $bus,
            ssrfProtection: $ssrf,
            logger: new NullLogger(),
        );

        $project = new Project();
        $project->uuid = Uuid::v4();

        $form = new Form();
        $form->name = 'Intranet Attack Form';
        $form->slug = 'intranet-attack';
        $form->project = $project;
        $form->settings = [
            'webhook' => [
                'enabled' => true,
                'url' => 'http://169.254.169.254/latest/meta-data',
                'webhook_id' => 99,
            ],
        ];

        $submission = new FormSubmission();
        $submission->id = 103;
        $submission->createdAt = new \DateTimeImmutable();

        $ssrf->expects($this->once())
            ->method('isSafeUrl')
            ->with('http://169.254.169.254/latest/meta-data')
            ->willReturn(false);

        // Bus must NEVER be called when SSRF fails
        $bus->expects($this->never())->method('dispatch');

        $event = new FormSubmittedEvent($form, $submission, $project, []);
        $subscriber->onFormSubmitted($event);
    }
}
