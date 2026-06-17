<?php

namespace App\Tests\Command;

use App\Command\PublishScheduledEntriesCommand;
use App\Entity\ContentEntry;
use App\Repository\ContentEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class PublishScheduledEntriesCommandTest extends TestCase
{
    public function testCommandPublishesScheduledEntries(): void
    {
        $entry = new ContentEntry();
        $entry->status = 'scheduled';

        $repository = $this->createMock(ContentEntryRepository::class);
        $repository->method('findScheduledToPublish')
            ->willReturn([$entry]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $command = new PublishScheduledEntriesCommand($em);
        $command->setRepository($repository);

        $tester = new CommandTester($command);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertSame('published', $entry->status);
        $this->assertNull($entry->scheduledAt);
        $this->assertStringContainsString('1 entrée(s) publiée(s)', $tester->getDisplay());
    }

    public function testCommandDoesNothingWhenNoScheduledEntries(): void
    {
        $repository = $this->createMock(ContentEntryRepository::class);
        $repository->method('findScheduledToPublish')
            ->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $command = new PublishScheduledEntriesCommand($em);
        $command->setRepository($repository);

        $tester = new CommandTester($command);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Aucune entrée à publier', $tester->getDisplay());
    }
}
