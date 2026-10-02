<?php

namespace App\Tests\Entity;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\ContentFieldGroup;
use App\Entity\ContentFieldValue;
use App\Entity\Field;
use App\Entity\Project;
use PHPUnit\Framework\TestCase;

class ContentFieldGroupTest extends TestCase
{
    public function testInstantiationAndDefaults(): void
    {
        $group = new ContentFieldGroup();
        $this->assertNotNull($group->uuid);
        $this->assertEquals(0, $group->sortOrder);
        $this->assertCount(0, $group->values);
    }

    public function testAddAndRemoveValues(): void
    {
        $group = new ContentFieldGroup();
        $value = new ContentFieldValue();

        $group->addValue($value);
        $this->assertCount(1, $group->values);
        $this->assertSame($group, $value->groupInstance);

        $group->removeValue($value);
        $this->assertCount(0, $group->values);
        $this->assertNull($value->groupInstance);
    }

    public function testRelationships(): void
    {
        $project = new Project();
        $collection = new Collection();
        $entry = new ContentEntry();
        $field = new Field();

        $group = new ContentFieldGroup();
        $group->project = $project;
        $group->collection = $collection;
        $group->contentEntry = $entry;
        $group->field = $field;
        $group->sortOrder = 3;

        $this->assertSame($project, $group->project);
        $this->assertSame($collection, $group->collection);
        $this->assertSame($entry, $group->contentEntry);
        $this->assertSame($field, $group->field);
        $this->assertEquals(3, $group->sortOrder);
    }
}
