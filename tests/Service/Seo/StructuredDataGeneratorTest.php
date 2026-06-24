<?php

namespace App\Tests\Service\Seo;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Service\Seo\StructuredDataGenerator;
use PHPUnit\Framework\TestCase;

class StructuredDataGeneratorTest extends TestCase
{
    private StructuredDataGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new StructuredDataGenerator();
    }

    public function testGenerateArticle(): void
    {
        $entry = $this->createEntry([
            'metaTitle' => 'Test Article Title',
            'metaDescription' => 'Test description for article.',
            'slug' => 'test-article',
            'ogImage' => 'uuid-og-image',
        ]);

        $data = $this->generator->generate($entry, 'Article');

        $this->assertSame('https://schema.org', $data['@context']);
        $this->assertSame('Article', $data['@type']);
        $this->assertSame('Test Article Title', $data['headline']);
        $this->assertSame('Test description for article.', $data['description']);
        $this->assertSame('https://example.com/api/media/uuid-og-image', $data['image']);
        $this->assertNotNull($data['datePublished']);
        $this->assertNotNull($data['dateModified']);
    }

    public function testGenerateArticleWithoutImage(): void
    {
        $entry = $this->createEntry([
            'metaTitle' => 'Article No Image',
            'ogImage' => null,
        ]);

        $data = $this->generator->generate($entry, 'Article');

        $this->assertNull($data['image']);
    }

    public function testGenerateWebPage(): void
    {
        $entry = $this->createEntry([
            'metaTitle' => 'Web Page Title',
            'metaDescription' => 'Web page description.',
            'slug' => 'webpage',
        ]);

        $data = $this->generator->generate($entry, 'WebPage');

        $this->assertSame('WebPage', $data['@type']);
        $this->assertSame('Web Page Title', $data['name']);
        $this->assertSame('https://example.com/webpage', $data['url']);
    }

    public function testGenerateProduct(): void
    {
        $entry = $this->createEntry([
            'metaTitle' => 'Product Name',
            'metaDescription' => 'Product description.',
        ]);

        $data = $this->generator->generate($entry, 'Product');

        $this->assertSame('Product', $data['@type']);
        $this->assertSame('Product Name', $data['name']);
        $this->assertSame('Product description.', $data['description']);
    }

    public function testGenerateFaq(): void
    {
        $entry = $this->createEntry([
            'metaTitle' => 'FAQ Page',
        ]);

        $data = $this->generator->generate($entry, 'FAQ');

        $this->assertSame('FAQPage', $data['@type']);
        $this->assertIsArray($data['mainEntity']);
        $this->assertEmpty($data['mainEntity']);
    }

    public function testGenerateBreadcrumb(): void
    {
        $collection = new Collection();
        $collection->name = 'Blog';
        $collection->slug = 'blog';

        $entry = $this->createEntry([
            'metaTitle' => 'Breadcrumb Article',
            'slug' => 'breadcrumb-article',
            'collection' => $collection,
        ]);

        $data = $this->generator->generate($entry, 'BreadcrumbList');

        $this->assertSame('BreadcrumbList', $data['@type']);
        $this->assertCount(3, $data['itemListElement']);
        $this->assertSame('Home', $data['itemListElement'][0]['name']);
        $this->assertSame('Blog', $data['itemListElement'][1]['name']);
        $this->assertSame('Breadcrumb Article', $data['itemListElement'][2]['name']);
    }

    public function testGenerateWithInvalidTypeFallsBackToArticle(): void
    {
        $entry = $this->createEntry([
            'metaTitle' => 'Fallback',
            'metaDescription' => 'Fallback description.',
        ]);

        $data = $this->generator->generate($entry, 'InvalidType');

        $this->assertSame('Article', $data['@type']);
    }

    public function testIsValidArticleReturnsTrue(): void
    {
        $entry = $this->createEntry([
            'metaTitle' => 'Valid Article',
        ]);

        $this->assertTrue($this->generator->isValid($entry, 'Article'));
    }

    public function testIsValidArticleReturnsFalseWhenEmptyTitle(): void
    {
        $publishedAt = new \DateTimeImmutable('2026-01-01');
        $entry = $this->createEntry([
            'metaTitle' => '',
            'publishedAt' => $publishedAt,
        ]);

        $this->assertFalse($this->generator->isValid($entry, 'Article'));
    }

    public function testIsValidProductReturnsFalseWhenEmptyName(): void
    {
        $entry = $this->createEntry([
            'metaTitle' => '',
        ]);

        $this->assertFalse($this->generator->isValid($entry, 'Product'));
    }

    public function testIsValidDefaultReturnsTrue(): void
    {
        $entry = $this->createEntry([
            'metaTitle' => 'Anything',
        ]);

        $this->assertTrue($this->generator->isValid($entry, 'FAQ'));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createEntry(array $overrides = []): ContentEntry
    {
        $entry = new ContentEntry();
        $entry->metaTitle = $overrides['metaTitle'] ?? 'Default Title';
        $entry->metaDescription = $overrides['metaDescription'] ?? 'Default meta description for testing purposes.';
        $entry->slug = $overrides['slug'] ?? 'default-slug';
        $entry->ogImage = $overrides['ogImage'] ?? null;
        $entry->status = 'published';
        $entry->publishedAt = $overrides['publishedAt'] ?? new \DateTimeImmutable('2026-06-01T12:00:00+00:00');
        $entry->updatedAt = new \DateTimeImmutable('2026-06-10T12:00:00+00:00');

        if (isset($overrides['collection'])) {
            $entry->collection = $overrides['collection'];
        }

        return $entry;
    }
}
