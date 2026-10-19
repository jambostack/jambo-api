<?php

namespace App\Tests\EventSubscriber;

use App\Entity\Automation;
use App\Entity\AutomationRun;
use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Project;
use App\Event\ContentEvent;
use App\EventSubscriber\AutomationTriggerSubscriber;
use App\Message\ExecuteAutomationMessage;
use App\MessageHandler\ExecuteAutomationMessageHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class AutomationTriggerSubscriberTest extends KernelTestCase
{
    public function testOnContentEventDispatchesAsyncMessage(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $project = new Project();
        $project->name = 'Auto Proj ' . bin2hex(random_bytes(4));
        $em->persist($project);

        $collection = new Collection();
        $collection->project = $project;
        $collection->name = 'Posts';
        $collection->slug = 'posts';
        $em->persist($collection);

        $automation = new Automation();
        $automation->project = $project;
        $automation->name = 'Test Auto';
        $automation->isActive = true;
        $automation->flowGraph = [
            'nodes' => [
                ['id' => '1', 'type' => 'trigger.content.created', 'data' => []],
            ],
            'edges' => [],
        ];
        $em->persist($automation);

        $entry = new ContentEntry();
        $entry->project = $project;
        $entry->collection = $collection;
        $entry->slug = 'new-entry';
        $entry->status = 'published';
        $em->persist($entry);

        $em->flush();

        // Mock MessageBusInterface to capture dispatched message
        $dispatchedMessage = null;
        $mockBus = $this->createMock(MessageBusInterface::class);
        $mockBus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(function (object $message) use (&$dispatchedMessage) {
                $dispatchedMessage = $message;
                return new Envelope($message);
            });

        $subscriber = new AutomationTriggerSubscriber(
            $em->getRepository(Automation::class),
            $mockBus,
            $em,
            self::getContainer()->get(\Psr\Log\LoggerInterface::class)
        );

        $event = new ContentEvent(ContentEvent::CREATED, $project, $entry);
        $subscriber->onContentEvent($event);

        self::assertInstanceOf(ExecuteAutomationMessage::class, $dispatchedMessage);
        self::assertSame($automation->id, $dispatchedMessage->automationId);

        // Verify pending run was created in DB
        $run = $em->getRepository(AutomationRun::class)->find($dispatchedMessage->runId);
        self::assertNotNull($run);
        self::assertSame('pending', $run->status);

        // Verify handler can process the message
        $handler = self::getContainer()->get(ExecuteAutomationMessageHandler::class);
        $handler($dispatchedMessage);

        $em->refresh($run);
        self::assertSame('success', $run->status);
    }
}
