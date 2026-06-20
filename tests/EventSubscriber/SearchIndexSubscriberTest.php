<?php
namespace App\Tests\EventSubscriber;

use App\Entity\ContentEntry;
use App\Entity\Project;
use App\Entity\Collection;
use App\EventSubscriber\SearchIndexSubscriber;
use App\Service\SearchService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class SearchIndexSubscriberTest extends TestCase
{
    private SearchIndexSubscriber $subscriber;
    private SearchService&\PHPUnit\Framework\MockObject\MockObject $search;

    protected function setUp(): void
    {
        $this->search = $this->createMock(SearchService::class);
        $this->subscriber = new SearchIndexSubscriber($this->search);
    }

    private function makeEntry(): ContentEntry
    {
        $project = new Project();
        $project->uuid = Uuid::v5(Uuid::fromString('00000000-0000-0000-0000-000000000000'), 'search-sub-proj');
        $collection = new Collection();
        $collection->project = $project;
        $collection->slug = 'articles';
        $entry = new ContentEntry();
        $entry->uuid = Uuid::v5(Uuid::fromString('00000000-0000-0000-0000-000000000000'), 'search-sub-entry');
        $entry->project = $project;
        $entry->collection = $collection;
        return $entry;
    }

    public function testIndexesOnInsert(): void
    {
        $entry = $this->makeEntry();

        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn([$entry]);
        $uow->method('getScheduledEntityUpdates')->willReturn([]);
        $uow->method('getScheduledEntityDeletions')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        $this->search->expects($this->once())->method('indexEntry')->with($entry);
        $this->search->expects($this->never())->method('removeEntry');

        $this->subscriber->onFlush(new OnFlushEventArgs($em));
        $this->subscriber->postFlush(new PostFlushEventArgs($em));
    }

    public function testRemovesOnDelete(): void
    {
        $entry = $this->makeEntry();

        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn([]);
        $uow->method('getScheduledEntityUpdates')->willReturn([]);
        $uow->method('getScheduledEntityDeletions')->willReturn([$entry]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        $this->search->expects($this->once())->method('removeEntry')->with($entry);
        $this->search->expects($this->never())->method('indexEntry');

        $this->subscriber->onFlush(new OnFlushEventArgs($em));
        $this->subscriber->postFlush(new PostFlushEventArgs($em));
    }

    public function testRemovesWhenSoftDeleted(): void
    {
        $entry = $this->makeEntry();
        $entry->deletedAt = new \DateTimeImmutable(); // soft delete

        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn([]);
        $uow->method('getScheduledEntityUpdates')->willReturn([$entry]);
        $uow->method('getScheduledEntityDeletions')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        $this->search->expects($this->once())->method('removeEntry')->with($entry);
        $this->search->expects($this->never())->method('indexEntry');

        $this->subscriber->onFlush(new OnFlushEventArgs($em));
        $this->subscriber->postFlush(new PostFlushEventArgs($em));
    }

    public function testIgnoresNonContentEntry(): void
    {
        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn([new \stdClass()]);
        $uow->method('getScheduledEntityUpdates')->willReturn([]);
        $uow->method('getScheduledEntityDeletions')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        $this->search->expects($this->never())->method('indexEntry');
        $this->search->expects($this->never())->method('removeEntry');

        $this->subscriber->onFlush(new OnFlushEventArgs($em));
        $this->subscriber->postFlush(new PostFlushEventArgs($em));
    }
}
