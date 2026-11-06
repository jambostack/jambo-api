<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Notification;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Service\MercurePublisher;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

class FormSubmissionMercureAndNotificationTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/mercure_form_test_' . bin2hex(random_bytes(4));
        if (!is_dir($this->projectDir . '/var/realtime')) {
            mkdir($this->projectDir . '/var/realtime', 0700, true);
        }
    }

    protected function tearDown(): void
    {
        $realtimeDir = $this->projectDir . '/var/realtime';
        if (is_dir($realtimeDir)) {
            array_map('unlink', glob($realtimeDir . '/*.jsonl'));
            rmdir($realtimeDir);
        }
    }

    public function testMercurePublisherFormSubmittedPublishesToHub(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $publisher = new MercurePublisher($this->projectDir, $hub);
        $projectUuid = 'bafb2021-079e-44b0-8fe9-35af563dc2b2';

        $hub->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (Update $update) use ($projectUuid) {
                $topics = $update->getTopics();
                $data = json_decode($update->getData(), true);

                return in_array("projects/{$projectUuid}/forms", $topics, true)
                    && in_array("projects/{$projectUuid}/forms/newsletter", $topics, true)
                    && ($data['event'] ?? null) === 'form.submitted'
                    && ($data['data']['form'] ?? null) === 'newsletter'
                    && ($data['data']['title'] ?? null) === 'Newsletter Subscription';
            }));

        $publisher->formSubmitted(
            $projectUuid,
            'newsletter',
            ['id' => 12, 'isSpam' => false, 'createdAt' => time()],
            'Newsletter Subscription'
        );

        // Pas d'écriture fallback quand le hub est actif
        $this->assertFileDoesNotExist($this->projectDir . '/var/realtime/' . $projectUuid . '.jsonl');
    }

    public function testMercurePublisherFormSubmittedFallsBackToJsonl(): void
    {
        $publisher = new MercurePublisher($this->projectDir, null);
        $projectUuid = 'bafb2021-079e-44b0-8fe9-35af563dc2b2';

        $publisher->formSubmitted(
            $projectUuid,
            'contact-sales',
            ['id' => 34, 'isSpam' => false, 'createdAt' => time()],
            'Sales Contact'
        );

        $jsonlPath = $this->projectDir . '/var/realtime/' . $projectUuid . '.jsonl';
        $this->assertFileExists($jsonlPath);

        $content = file_get_contents($jsonlPath);
        $this->assertStringContainsString('form.submitted', $content);
        $this->assertStringContainsString('contact-sales', $content);
        $this->assertStringContainsString('Sales Contact', $content);
    }

    public function testNotificationServiceNotifyFormSubmissionCreatesAndPersists(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $notificationRepo = $this->createStub(NotificationRepository::class);

        $translator = new class implements TranslatorInterface {
            public function getLocale(): string { return 'en'; }
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                if ($id === 'notification.form_submission_title') {
                    return 'New submission for form "' . ($parameters['%form%'] ?? '') . '"';
                }
                if ($id === 'notification.form_submission_body') {
                    return 'A new entry was submitted for form "' . ($parameters['%form%'] ?? '') . '" (' . ($parameters['%slug%'] ?? '') . ').';
                }
                return $id;
            }
        };

        $service = new NotificationService(
            em: $em,
            translator: $translator,
            logger: new NullLogger(),
            notificationRepository: $notificationRepo,
        );

        $user = new User();
        $user->id = 55;
        $user->locale = 'en';

        $project = new Project();
        $project->uuid = Uuid::v4();
        $project->name = 'Production Portal';

        $persistedNotification = null;
        $em->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (Notification $n) use (&$persistedNotification, $user, $project) {
                $persistedNotification = $n;
                return $n->recipient === $user
                    && $n->type === NotificationService::TYPE_FORM_SUBMISSION
                    && str_contains($n->title, 'Quote Request')
                    && str_contains($n->body, 'quote-request')
                    && str_contains($n->link, $project->uuid->toRfc4122());
            }));

        $em->expects($this->once())->method('flush');

        $result = $service->notifyFormSubmission(
            recipient: $user,
            formName: 'Quote Request',
            formSlug: 'quote-request',
            project: $project,
            submissionId: 99
        );

        $this->assertSame($persistedNotification, $result);
        $this->assertSame(NotificationService::TYPE_FORM_SUBMISSION, $result->type);
    }
}
