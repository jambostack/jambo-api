<?php

namespace App\Tests\EventSubscriber;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Media;
use App\Entity\MediaFolder;
use App\Entity\Project;
use App\EventSubscriber\MercureEntitySubscriber;
use App\Service\MercurePublisher;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class MercureEntitySubscriberTest extends TestCase
{
    private MercureEntitySubscriber $subscriber;
    private MercurePublisher&\PHPUnit\Framework\MockObject\MockObject $mercure;

    protected function setUp(): void
    {
        $this->mercure = $this->createMock(MercurePublisher::class);
        $this->subscriber = new MercureEntitySubscriber($this->mercure);
    }

    // ─── Helpers ───────────────────────────────────────────────────

    /** Generate a deterministic UUID from a short label, valid for createFromRfc4122 */
    private function uuid(string $seed): Uuid
    {
        return Uuid::v5(Uuid::fromString('00000000-0000-0000-0000-000000000000'), $seed);
    }

    private function makeEntry(string $projectLabel, string $entryLabel): ContentEntry
    {
        $projectUuid = $this->uuid($projectLabel);
        $entryUuid = $this->uuid($entryLabel);

        $project = new Project();
        $project->uuid = $projectUuid;
        $project->name = 'Test Project';

        $collection = new Collection();
        $collection->project = $project;
        $collection->slug = 'articles';

        $entry = new ContentEntry();
        $entry->uuid = $entryUuid;
        $entry->project = $project;
        $entry->collection = $collection;
        $entry->status = 'draft';

        return $entry;
    }

    private function makeMedia(string $projectLabel, string $filename): Media
    {
        $projectUuid = $this->uuid($projectLabel);

        $project = new Project();
        $project->uuid = $projectUuid;

        $media = new Media();
        $media->uuid = Uuid::v4();
        $media->project = $project;
        $media->originalName = $filename;

        return $media;
    }

    // ─── Content ────────────────────────────────────────────────────

    public function testPostPersistContentEntryPublishesCreated(): void
    {
        $entry = $this->makeEntry('proj-1', 'entry-1');
        $projectUuid = $entry->project->uuid->toRfc4122();
        $entryUuid = $entry->uuid->toRfc4122();
        $args = new PostPersistEventArgs($entry, $this->createMock(EntityManagerInterface::class));

        $this->mercure->expects($this->once())
            ->method('contentChanged')
            ->with($projectUuid, 'created', $this->callback(function (array $e) use ($entryUuid) {
                return $e['uuid'] === $entryUuid && $e['action'] === 'created';
            }));

        $this->subscriber->postPersist($args);
    }

    public function testPostUpdateContentEntryPublishesUpdated(): void
    {
        $entry = $this->makeEntry('proj-2', 'entry-2');
        $projectUuid = $entry->project->uuid->toRfc4122();
        $entryUuid = $entry->uuid->toRfc4122();
        $args = new PostUpdateEventArgs($entry, $this->createMock(EntityManagerInterface::class));

        $this->mercure->expects($this->once())
            ->method('contentChanged')
            ->with($projectUuid, 'updated', $this->callback(function (array $e) use ($entryUuid) {
                return $e['uuid'] === $entryUuid && $e['action'] === 'updated';
            }));

        $this->subscriber->postUpdate($args);
    }

    public function testPreRemoveContentEntryPublishesDeleted(): void
    {
        $entry = $this->makeEntry('proj-3', 'entry-3');
        $projectUuid = $entry->project->uuid->toRfc4122();
        $entryUuid = $entry->uuid->toRfc4122();
        $args = new PreRemoveEventArgs($entry, $this->createMock(EntityManagerInterface::class));

        $this->mercure->expects($this->once())
            ->method('contentChanged')
            ->with($projectUuid, 'deleted', $this->callback(function (array $e) use ($entryUuid) {
                return $e['uuid'] === $entryUuid && $e['action'] === 'deleted';
            }));

        $this->subscriber->preRemove($args);
    }

    // ─── Media ──────────────────────────────────────────────────────

    public function testPreRemoveMediaPublishesDeleted(): void
    {
        $media = $this->makeMedia('proj-media', 'photo.jpg');
        $projectUuid = $media->project->uuid->toRfc4122();
        $args = new PreRemoveEventArgs($media, $this->createMock(EntityManagerInterface::class));

        $this->mercure->expects($this->once())
            ->method('mediaDeleted')
            ->with($projectUuid, 'photo.jpg');

        $this->subscriber->preRemove($args);
    }

    // ─── Status ─────────────────────────────────────────────────────

    public function testOnFlushDetectsStatusChange(): void
    {
        $entry = $this->makeEntry('proj-status', 'entry-status');
        $entry->status = 'published';
        $projectUuid = $entry->project->uuid->toRfc4122();
        $entryUuid = $entry->uuid->toRfc4122();

        $changeSet = ['status' => ['draft', 'published']];

        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getScheduledEntityUpdates')->willReturn([$entry]);
        $uow->method('getEntityChangeSet')->with($entry)->willReturn($changeSet);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        // onFlush stocke les événements, postFlush les publie
        $this->subscriber->onFlush(new OnFlushEventArgs($em));

        $this->mercure->expects($this->once())
            ->method('statusChanged')
            ->with($projectUuid, $entryUuid, 'draft', 'published');

        $this->subscriber->postFlush(new PostFlushEventArgs($em));

        // Vérifier que le tableau est vidé après postFlush
        $this->subscriber->postFlush(new PostFlushEventArgs($em));
        // Aucun appel supplémentaire attendu
    }

    // ─── Ignore non-pertinents ─────────────────────────────────────

    public function testIgnoresNonContentEntities(): void
    {
        $project = new Project();
        $project->uuid = Uuid::v4();
        $project->name = 'Test';

        $args = new PostPersistEventArgs($project, $this->createMock(EntityManagerInterface::class));

        $this->mercure->expects($this->never())
            ->method($this->anything());

        $this->subscriber->postPersist($args);
    }
}
