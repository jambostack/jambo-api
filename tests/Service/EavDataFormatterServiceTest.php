<?php

namespace App\Tests\Service;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\ContentFieldValue;
use App\Entity\Field;
use App\Entity\Project;
use App\Service\EavDataFormatterService;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class EavDataFormatterServiceTest extends TestCase
{
    private EavDataFormatterService $formatter;

    protected function setUp(): void
    {
        $this->formatter = new EavDataFormatterService();
    }

    public function testFormatEntryReturnsBaseFields(): void
    {
        $entry = $this->makeEntry();

        $result = $this->formatter->formatEntry($entry);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('uuid', $result);
        $this->assertArrayHasKey('locale', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('collection', $result);
        $this->assertSame('en', $result['locale']);
        $this->assertSame('draft', $result['status']);
        $this->assertSame('articles', $result['collection']);
    }

    public function testFormatEntryIncludesTextField(): void
    {
        $entry = $this->makeEntry();
        $entry->fieldValues->add($this->makeFieldValue($entry, 'title', 'text', textValue: 'Hello World'));

        $result = $this->formatter->formatEntry($entry);

        $this->assertSame('Hello World', $result['title']);
    }

    public function testFormatEntryIncludesNumberField(): void
    {
        $entry = $this->makeEntry();
        $entry->fieldValues->add($this->makeFieldValue($entry, 'price', 'number', numberValue: '19.99'));

        $result = $this->formatter->formatEntry($entry);

        $this->assertSame(19.99, $result['price']);
    }

    public function testFormatEntryIncludesBooleanField(): void
    {
        $entry = $this->makeEntry();
        $entry->fieldValues->add($this->makeFieldValue($entry, 'published', 'boolean', booleanValue: true));

        $result = $this->formatter->formatEntry($entry);

        $this->assertTrue($result['published']);
    }

    public function testFormatEntryIncludesJsonField(): void
    {
        $entry = $this->makeEntry();
        $entry->fieldValues->add($this->makeFieldValue($entry, 'tags', 'json', jsonValue: ['php', 'symfony']));

        $result = $this->formatter->formatEntry($entry);

        $this->assertSame(['php', 'symfony'], $result['tags']);
    }

    public function testFormatEntrySkipsFieldValueWithNullSlug(): void
    {
        $entry = $this->makeEntry();

        $fv = new ContentFieldValue();
        $fv->fieldType = 'text';
        $fv->textValue = 'orphan';
        // no field set — slug will be null
        $entry->fieldValues->add($fv);

        $result = $this->formatter->formatEntry($entry);

        $this->assertArrayNotHasKey('orphan', $result);
    }

    public function testFormatEntryLogsWarningForOrphanFieldValue(): void
    {
        $entry = $this->makeEntry();

        $fv = new ContentFieldValue();
        $fv->fieldType = 'text';
        $fv->textValue = 'orphan';
        // no field set — slug will be null
        $entry->fieldValues->add($fv);

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('orphan field value'));

        $formatter = new EavDataFormatterService($logger);
        $result = $formatter->formatEntry($entry);

        $this->assertArrayNotHasKey('orphan', $result);
    }

    public function testFormatEntryDateField(): void
    {
        $entry = $this->makeEntry();
        $date = new \DateTime('2026-01-15');
        $entry->fieldValues->add($this->makeFieldValue($entry, 'published_at', 'date', dateValue: $date));

        $result = $this->formatter->formatEntry($entry);

        $this->assertSame('2026-01-15', $result['published_at']);
    }

    public function testFormatEntryRendersTagsAsArray(): void
    {
        $entry = $this->makeEntry();
        $entry->fieldValues->add($this->makeFieldValue($entry, 'keywords', 'tags', jsonValue: ['php', 'symfony']));

        $result = $this->formatter->formatEntry($entry);

        $this->assertSame(['php', 'symfony'], $result['keywords']);
    }

    public function testFormatEntryRendersRatingAsNumber(): void
    {
        $entry = $this->makeEntry();
        $entry->fieldValues->add($this->makeFieldValue($entry, 'score', 'rating', numberValue: '4'));

        $result = $this->formatter->formatEntry($entry);

        $this->assertSame(4.0, $result['score']);
    }

    public function testFormatEntryIncludesScheduledAtWhenSet(): void
    {
        $entry = $this->makeEntry();
        $entry->scheduledAt = new \DateTimeImmutable('2026-07-01T12:00:00+00:00');

        $result = $this->formatter->formatEntry($entry);

        $this->assertSame('2026-07-01T12:00:00+00:00', $result['scheduled_at']);
    }

    public function testFormatEntryScheduledAtNullWhenNotSet(): void
    {
        $entry = $this->makeEntry();

        $result = $this->formatter->formatEntry($entry);

        $this->assertNull($result['scheduled_at']);
    }

    // ----------------------------------------------------------------
    // assigned_to
    // ----------------------------------------------------------------

    public function testAssignedToIsFormattedInOutput(): void
    {
        $entry = $this->makeEntry();

        $user = new \App\Entity\User();
        $user->name = 'Alice Johnson';
        $entry->assignedTo = $user;

        $result = $this->formatter->formatEntry($entry);

        $this->assertArrayHasKey('assigned_to', $result);
        $this->assertNotNull($result['assigned_to']);
        $this->assertSame($user->name, $result['assigned_to']['name']);
        $this->assertSame($user->id, $result['assigned_to']['id']);
    }

    public function testAssignedToIsNullWhenNotAssigned(): void
    {
        $entry = $this->makeEntry();

        $result = $this->formatter->formatEntry($entry);

        $this->assertArrayHasKey('assigned_to', $result);
        $this->assertNull($result['assigned_to']);
    }

    private function makeEntry(): ContentEntry
    {
        $project = new Project();
        $project->name = 'Test Project';

        $collection = new Collection();
        $collection->name = 'Articles';
        $collection->slug = 'articles';
        $collection->project = $project;

        $entry = new ContentEntry();
        $entry->locale = 'en';
        $entry->status = 'draft';
        $entry->collection = $collection;
        $entry->project = $project;
        $entry->uuid = Uuid::v4();

        return $entry;
    }

    private function makeFieldValue(
        ContentEntry $entry,
        string $slug,
        string $type,
        ?string $textValue = null,
        ?string $numberValue = null,
        ?bool $booleanValue = null,
        ?\DateTimeInterface $dateValue = null,
        ?\DateTimeInterface $datetimeValue = null,
        ?array $jsonValue = null,
    ): ContentFieldValue {
        $field = new Field();
        $field->name = $slug;
        $field->slug = $slug;
        $field->type = $type;

        $fv = new ContentFieldValue();
        $fv->contentEntry = $entry;
        $fv->field = $field;
        $fv->fieldType = $type;
        $fv->textValue = $textValue;
        $fv->numberValue = $numberValue;
        $fv->booleanValue = $booleanValue;
        $fv->dateValue = $dateValue;
        $fv->datetimeValue = $datetimeValue;
        $fv->jsonValue = $jsonValue;

        return $fv;
    }
}
