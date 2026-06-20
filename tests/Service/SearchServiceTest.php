<?php

namespace App\Tests\Service;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Project;
use App\Service\EavDataFormatterService;
use App\Service\SearchService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

class SearchServiceTest extends TestCase
{
    private EavDataFormatterService&\PHPUnit\Framework\MockObject\MockObject $formatter;

    protected function setUp(): void
    {
        $this->formatter = $this->createMock(EavDataFormatterService::class);
    }

    private function makeEntry(): ContentEntry
    {
        $project = new Project();
        $project->uuid = Uuid::v5(Uuid::fromString('00000000-0000-0000-0000-000000000000'), 'search-proj');

        $collection = new Collection();
        $collection->project = $project;
        $collection->slug = 'articles';

        $entry = new ContentEntry();
        $entry->uuid = Uuid::v5(Uuid::fromString('00000000-0000-0000-0000-000000000000'), 'search-entry');
        $entry->project = $project;
        $entry->collection = $collection;

        return $entry;
    }

    // --- Service desactive (pas de Meilisearch) --------------------

    public function testIsDisabledWhenHostIsEmpty(): void
    {
        $service = new SearchService('', '', $this->formatter, new NullLogger());
        $this->assertFalse($service->isEnabled());
    }

    public function testIndexEntryReturnsEarlyWhenDisabled(): void
    {
        $service = new SearchService('', '', $this->formatter, new NullLogger());
        $entry = $this->makeEntry();

        // Ne doit pas appeler le formatter car le service est desactive
        $this->formatter->expects($this->never())->method('formatEntry');

        $service->indexEntry($entry);
    }

    public function testRemoveEntryReturnsEarlyWhenDisabled(): void
    {
        $service = new SearchService('', '', $this->formatter, new NullLogger());
        $entry = $this->makeEntry();

        $service->removeEntry($entry);
        // Aucune exception ne doit etre levee
        $this->assertTrue(true);
    }

    public function testSearchReturnsEmptyWhenDisabled(): void
    {
        $service = new SearchService('', '', $this->formatter, new NullLogger());
        $project = $this->makeEntry()->project;

        $result = $service->search($project, 'test');
        $this->assertSame([], $result['hits']);
        $this->assertSame('test', $result['query']);
    }

    // --- Service active (avec host/key) ----------------------------

    public function testIsEnabledWhenHostAndKeyAreSet(): void
    {
        $service = new SearchService('http://localhost:7700', 'test-key', $this->formatter, new NullLogger());
        $this->assertTrue($service->isEnabled());
    }

    // --- rebuildIndex quand desactive ------------------------------

    public function testRebuildIndexReturnsZeroWhenDisabled(): void
    {
        $service = new SearchService('', '', $this->formatter, new NullLogger());
        $entry = $this->makeEntry();
        $project = $entry->project;
        $project->contentEntries->add($entry);

        $count = $service->rebuildIndex($project);
        $this->assertSame(0, $count);
    }
}
