<?php
namespace App\Tests\Entity;

use App\Entity\Collection;
use App\Entity\Project;
use PHPUnit\Framework\TestCase;

class CollectionTest extends TestCase
{
    private function createCollection(?array $settings = null): Collection
    {
        $c = new Collection();
        $c->name = 'Test';
        $c->slug = 'test';
        $c->settings = $settings;
        return $c;
    }

    public function testGetWorkflowStatusesReturnsDefaultsWhenSettingsIsNull(): void
    {
        $c = $this->createCollection(null);
        $statuses = $c->getWorkflowStatuses();
        $this->assertCount(2, $statuses);
        $this->assertEquals('draft', $statuses[0]['slug']);
        $this->assertEquals('published', $statuses[1]['slug']);
    }

    public function testGetWorkflowStatusesReturnsDefaultsWhenSettingsHasNoWorkflow(): void
    {
        $c = $this->createCollection(['other' => true]);
        $statuses = $c->getWorkflowStatuses();
        $this->assertCount(2, $statuses);
    }

    public function testGetWorkflowStatusesReturnsCustomStatuses(): void
    {
        $c = $this->createCollection(['workflow' => ['statuses' => [
            ['slug' => 'draft', 'label' => 'Brouillon', 'color' => '#000', 'published' => false],
            ['slug' => 'review', 'label' => 'Relecture', 'color' => '#ff0', 'published' => false],
            ['slug' => 'published', 'label' => 'Publié', 'color' => '#0f0', 'published' => true],
        ]]]);
        $statuses = $c->getWorkflowStatuses();
        $this->assertCount(3, $statuses);
        $this->assertEquals('review', $statuses[1]['slug']);
    }

    public function testGetDefaultStatusReturnsDraftWhenNull(): void
    {
        $c = $this->createCollection(null);
        $this->assertEquals('draft', $c->getDefaultStatus());
    }

    public function testGetDefaultStatusReturnsCustomDefault(): void
    {
        $c = $this->createCollection(['workflow' => ['statuses' => [
            ['slug' => 'idea', 'label' => 'Idée', 'color' => '#aaa', 'published' => false],
            ['slug' => 'live', 'label' => 'En ligne', 'color' => '#0f0', 'published' => true],
        ], 'defaultStatus' => 'idea']]);
        $this->assertEquals('idea', $c->getDefaultStatus());
    }

    public function testGetPublishedStatusReturnsNullWhenNone(): void
    {
        $c = $this->createCollection(['workflow' => ['statuses' => [
            ['slug' => 'draft', 'label' => 'Draft', 'color' => '#000', 'published' => false],
        ]]]);
        $this->assertNull($c->getPublishedStatus());
    }

    public function testGetPublishedStatusReturnsFirstPublished(): void
    {
        $c = $this->createCollection(['workflow' => ['statuses' => [
            ['slug' => 'draft', 'label' => 'Draft', 'color' => '#000', 'published' => false],
            ['slug' => 'published', 'label' => 'Pub', 'color' => '#0f0', 'published' => true],
        ]]]);
        $this->assertEquals('published', $c->getPublishedStatus());
    }
}
