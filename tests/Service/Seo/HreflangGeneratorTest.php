<?php

namespace App\Tests\Service\Seo;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Project;
use App\Service\Seo\HreflangGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class HreflangGeneratorTest extends TestCase
{
    private HreflangGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new HreflangGenerator();
    }

    public function testGenerateHreflangWithMultipleLocales(): void
    {
        $entry = $this->createEntry('mon-article', 'fr', ['en', 'fr', 'de']);

        $links = $this->generator->generateHreflang($entry);

        $this->assertCount(4, $links);
        $this->assertArrayHasKey('en', $links);
        $this->assertArrayHasKey('fr', $links);
        $this->assertArrayHasKey('de', $links);
        $this->assertArrayHasKey('x-default', $links);

        $this->assertSame('https://mysite.com/blog/mon-article?locale=en', $links['en']);
        $this->assertSame('https://mysite.com/blog/mon-article?locale=fr', $links['fr']);
        $this->assertSame('https://mysite.com/blog/mon-article?locale=de', $links['de']);
    }

    public function testXDefaultFallsBackToDefaultLocale(): void
    {
        $entry = $this->createEntry('hello-world', 'en', ['en', 'fr']);

        $links = $this->generator->generateHreflang($entry);

        $this->assertArrayHasKey('x-default', $links);
        $this->assertSame($links['en'], $links['x-default']);
    }

    public function testXDefaultFallsBackToFirstLocaleWhenMissing(): void
    {
        $entry = $this->createEntry('test', 'en', ['en', 'fr', 'de']);
        // set defaultLocale to one not in project locales
        $entry->collection->project->defaultLocale = 'it';

        $links = $this->generator->generateHreflang($entry);

        $this->assertArrayHasKey('x-default', $links);
        // falls back to first available locale
        $this->assertSame($links['en'], $links['x-default']);
    }

    public function testGenerateHreflangWithNoCollection(): void
    {
        $entry = new ContentEntry();
        $entry->slug = 'standalone';
        $entry->status = 'published';
        $entry->updatedAt = new \DateTimeImmutable();

        $links = $this->generator->generateHreflang($entry);

        $this->assertArrayHasKey('x-default', $links);
        $this->assertArrayHasKey('en', $links);
        $this->assertSame('https://example.com//standalone?locale=en', $links['en']);
    }

    public function testGenerateHreflangWithSingleLocale(): void
    {
        $entry = $this->createEntry('page-unique', 'en', ['en']);

        $links = $this->generator->generateHreflang($entry);

        $this->assertCount(2, $links);
        $this->assertArrayHasKey('en', $links);
        $this->assertArrayHasKey('x-default', $links);
        $this->assertSame($links['en'], $links['x-default']);
    }

    private function createEntry(string $slug, string $defaultLocale, array $locales): ContentEntry
    {
        $project = new Project();
        $project->uuid = Uuid::v4();
        $project->previewUrl = 'https://mysite.com';
        $project->defaultLocale = $defaultLocale;
        $project->locales = $locales;

        $collection = new Collection();
        $collection->uuid = Uuid::v4();
        $collection->name = 'Blog';
        $collection->slug = 'blog';
        $collection->project = $project;

        $entry = new ContentEntry();
        $entry->metaTitle = 'Test Title';
        $entry->slug = $slug;
        $entry->status = 'published';
        $entry->updatedAt = new \DateTimeImmutable();
        $entry->collection = $collection;

        return $entry;
    }
}
