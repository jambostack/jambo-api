<?php

namespace App\Tests\EventSubscriber;

use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\EventSubscriber\GraphQLCacheInvalidationSubscriber;
use App\GraphQL\SchemaGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class GraphQLCacheInvalidationSubscriberTest extends TestCase
{
    private GraphQLCacheInvalidationSubscriber $subscriber;
    private SchemaGenerator&\PHPUnit\Framework\MockObject\MockObject $schemaGenerator;

    protected function setUp(): void
    {
        $this->schemaGenerator = $this->createMock(SchemaGenerator::class);
        $this->subscriber = new GraphQLCacheInvalidationSubscriber($this->schemaGenerator);
    }

    private function makeProject(): Project
    {
        $project = new Project();
        $project->uuid = Uuid::v5(Uuid::fromString('00000000-0000-0000-0000-000000000000'), 'gql-cache-proj');
        $project->name = 'Test';
        return $project;
    }

    public function testInvalidatesOnProjectInsert(): void
    {
        $project = $this->makeProject();

        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn([$project]);
        $uow->method('getScheduledEntityUpdates')->willReturn([]);
        $uow->method('getScheduledEntityDeletions')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        $this->schemaGenerator->expects($this->once())
            ->method('invalidateCache')
            ->with($project);

        $this->subscriber->onFlush(new OnFlushEventArgs($em));
        $this->subscriber->postFlush(new PostFlushEventArgs($em));
    }

    public function testInvalidatesOnCollectionUpdate(): void
    {
        $project = $this->makeProject();
        $collection = new Collection();
        $collection->project = $project;
        $collection->slug = 'articles';

        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn([]);
        $uow->method('getScheduledEntityUpdates')->willReturn([$collection]);
        $uow->method('getScheduledEntityDeletions')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        $this->schemaGenerator->expects($this->once())
            ->method('invalidateCache')
            ->with($project);

        $this->subscriber->onFlush(new OnFlushEventArgs($em));
        $this->subscriber->postFlush(new PostFlushEventArgs($em));
    }

    public function testInvalidatesOnFieldDelete(): void
    {
        $project = $this->makeProject();
        $collection = new Collection();
        $collection->project = $project;
        $collection->slug = 'articles';

        $field = new Field();
        $field->collection = $collection;
        $field->slug = 'title';
        $field->name = 'Title';
        $field->type = 'text';

        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn([]);
        $uow->method('getScheduledEntityUpdates')->willReturn([]);
        $uow->method('getScheduledEntityDeletions')->willReturn([$field]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        $this->schemaGenerator->expects($this->once())
            ->method('invalidateCache')
            ->with($project);

        $this->subscriber->onFlush(new OnFlushEventArgs($em));
        $this->subscriber->postFlush(new PostFlushEventArgs($em));
    }

    public function testIgnoresUnknownEntity(): void
    {
        $unknown = new \stdClass();

        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn([$unknown]);
        $uow->method('getScheduledEntityUpdates')->willReturn([]);
        $uow->method('getScheduledEntityDeletions')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        $this->schemaGenerator->expects($this->never())->method('invalidateCache');

        $this->subscriber->onFlush(new OnFlushEventArgs($em));
        $this->subscriber->postFlush(new PostFlushEventArgs($em));
    }
}
