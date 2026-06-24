<?php

namespace App\Tests\Service\Seo;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Project;
use App\Repository\ContentEntryRepository;
use App\Service\Seo\SitemapGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Cache\CacheInterface;

class SitemapGeneratorTest extends TestCase
{
    public function testGenerateSitemapWithTwoCollections(): void
    {
        $project = $this->createProject('https://mysite.com', 'en', ['en', 'fr']);

        $blogCollection = $this->createCollection($project, 'Blog', 'blog');
        $newsCollection = $this->createCollection($project, 'News', 'news');

        $blogEntry = $this->createEntry('blog-post-1', new \DateTimeImmutable('2026-06-10'));
        $newsEntry = $this->createEntry('news-article-1', new \DateTimeImmutable('2026-06-09'));

        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $entryRepo->expects($this->exactly(2))
            ->method('findByCollectionPaginated')
            ->willReturnMap([
                [$blogCollection, 1, 1000, null, 'published', [$blogEntry]],
                [$newsCollection, 1, 1000, null, 'published', [$newsEntry]],
            ]);

        $collectionRepo = $this->createMock(EntityRepository::class);
        $collectionRepo->expects($this->once())
            ->method('findBy')
            ->with(['project' => $project, 'deletedAt' => null])
            ->willReturn([$blogCollection, $newsCollection]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('getRepository')
            ->with(Collection::class)
            ->willReturn($collectionRepo);

        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('get')
            ->with('sitemap_' . $project->uuid?->toRfc4122())
            ->willReturnCallback(function ($key, $callback) {
                return $callback();
            });

        $generator = new SitemapGenerator($em, $entryRepo, $cache);
        $xml = $generator->generateSitemap($project);

        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $this->assertStringContainsString('<loc>https://mysite.com/blog/blog-post-1</loc>', $xml);
        $this->assertStringContainsString('<loc>https://mysite.com/news/news-article-1</loc>', $xml);
        $this->assertStringContainsString('<lastmod>2026-06-10</lastmod>', $xml);
        $this->assertStringContainsString('<lastmod>2026-06-09</lastmod>', $xml);
        $this->assertStringContainsString('<changefreq>weekly</changefreq>', $xml);
        $this->assertStringContainsString('<priority>0.5</priority>', $xml);
    }

    public function testGenerateSitemapExcludesCollections(): void
    {
        $project = $this->createProject('https://mysite.com', 'en', ['en']);
        $project->settings = [
            'seo' => [
                'sitemapExcludeCollections' => ['hidden'],
            ],
        ];

        $visible = $this->createCollection($project, 'Visible', 'visible');
        $hidden = $this->createCollection($project, 'Hidden', 'hidden');

        $entry = $this->createEntry('visible-post', new \DateTimeImmutable('2026-06-10'));

        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $entryRepo->expects($this->once())
            ->method('findByCollectionPaginated')
            ->with($visible, 1, 1000, null, 'published')
            ->willReturn([$entry]);

        $collectionRepo = $this->createMock(EntityRepository::class);
        $collectionRepo->expects($this->once())
            ->method('findBy')
            ->with(['project' => $project, 'deletedAt' => null])
            ->willReturn([$visible, $hidden]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('getRepository')
            ->with(Collection::class)
            ->willReturn($collectionRepo);

        $generator = new SitemapGenerator($em, $entryRepo);
        $xml = $generator->generateSitemap($project);

        $this->assertStringContainsString('visible-post', $xml);
        $this->assertStringNotContainsString('hidden', $xml);
    }

    public function testGenerateSitemapSkipsNonIndexableCollections(): void
    {
        $project = $this->createProject('https://mysite.com', 'en', ['en']);

        $noindexCollection = $this->createCollection($project, 'NoIndex', 'noindex');
        $noindexCollection->settings = ['seo' => ['indexable' => false]];

        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $entryRepo->expects($this->never())
            ->method('findByCollectionPaginated');

        $collectionRepo = $this->createMock(EntityRepository::class);
        $collectionRepo->expects($this->once())
            ->method('findBy')
            ->willReturn([$noindexCollection]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('getRepository')
            ->with(Collection::class)
            ->willReturn($collectionRepo);

        $generator = new SitemapGenerator($em, $entryRepo);
        $xml = $generator->generateSitemap($project);

        $this->assertStringContainsString('<urlset', $xml);
        $this->assertStringNotContainsString('<url>', $xml);
    }

    public function testGenerateSitemapSkipsEmptySlug(): void
    {
        $project = $this->createProject('https://mysite.com', 'en', ['en']);

        $collection = $this->createCollection($project, 'Test', 'test');

        $validEntry = $this->createEntry('valid-post', new \DateTimeImmutable('2026-06-10'));
        $emptySlugEntry = $this->createEntry('', new \DateTimeImmutable('2026-06-10'));

        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $entryRepo->expects($this->once())
            ->method('findByCollectionPaginated')
            ->with($collection, 1, 1000, null, 'published')
            ->willReturn([$validEntry, $emptySlugEntry]);

        $collectionRepo = $this->createMock(EntityRepository::class);
        $collectionRepo->expects($this->once())
            ->method('findBy')
            ->willReturn([$collection]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('getRepository')
            ->with(Collection::class)
            ->willReturn($collectionRepo);

        $generator = new SitemapGenerator($em, $entryRepo);
        $xml = $generator->generateSitemap($project);

        $this->assertStringContainsString('valid-post', $xml);
        $this->assertStringNotContainsString('?locale=', $xml);
    }

    public function testGenerateSitemapWithoutCache(): void
    {
        $project = $this->createProject('https://example.com', 'en', ['en']);

        $collection = $this->createCollection($project, 'Pages', 'pages');
        $entry = $this->createEntry('page-1', new \DateTimeImmutable('2026-06-10'));

        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $entryRepo->expects($this->once())
            ->method('findByCollectionPaginated')
            ->willReturn([$entry]);

        $collectionRepo = $this->createMock(EntityRepository::class);
        $collectionRepo->expects($this->once())
            ->method('findBy')
            ->willReturn([$collection]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('getRepository')
            ->with(Collection::class)
            ->willReturn($collectionRepo);

        $generator = new SitemapGenerator($em, $entryRepo, null);
        $xml = $generator->generateSitemap($project);

        $this->assertStringContainsString('<loc>https://example.com/pages/page-1</loc>', $xml);
    }

    public function testGenerateImageSitemap(): void
    {
        $project = new Project();
        $project->uuid = Uuid::v4();

        $generator = new SitemapGenerator(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(ContentEntryRepository::class),
        );
        $xml = $generator->generateImageSitemap($project);

        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $this->assertStringContainsString('xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"', $xml);
    }

    public function testGenerateSitemapUsesCustomChangefreqAndPriority(): void
    {
        $project = $this->createProject('https://mysite.com', 'en', ['en']);

        $collection = $this->createCollection($project, 'Pages', 'pages');
        $collection->settings = [
            'seo' => [
                'sitemapChangefreq' => 'daily',
                'sitemapPriority' => 0.9,
                'indexable' => true,
            ],
        ];

        $entry = $this->createEntry('page-1', new \DateTimeImmutable('2026-06-10'));

        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $entryRepo->expects($this->once())
            ->method('findByCollectionPaginated')
            ->willReturn([$entry]);

        $collectionRepo = $this->createMock(EntityRepository::class);
        $collectionRepo->expects($this->once())
            ->method('findBy')
            ->willReturn([$collection]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('getRepository')
            ->with(Collection::class)
            ->willReturn($collectionRepo);

        $generator = new SitemapGenerator($em, $entryRepo);
        $xml = $generator->generateSitemap($project);

        $this->assertStringContainsString('<changefreq>daily</changefreq>', $xml);
        $this->assertStringContainsString('<priority>0.9</priority>', $xml);
    }

    public function testGenerateSitemapFallsBackToExampleDotCom(): void
    {
        $project = new Project();
        $project->uuid = Uuid::v4();
        $project->previewUrl = null;

        $collection = $this->createCollection($project, 'Pages', 'pages');
        $entry = $this->createEntry('page-1', new \DateTimeImmutable('2026-06-10'));

        $entryRepo = $this->createMock(ContentEntryRepository::class);
        $entryRepo->expects($this->once())
            ->method('findByCollectionPaginated')
            ->willReturn([$entry]);

        $collectionRepo = $this->createMock(EntityRepository::class);
        $collectionRepo->expects($this->once())
            ->method('findBy')
            ->willReturn([$collection]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('getRepository')
            ->with(Collection::class)
            ->willReturn($collectionRepo);

        $generator = new SitemapGenerator($em, $entryRepo);
        $xml = $generator->generateSitemap($project);

        $this->assertStringContainsString('https://example.com', $xml);
    }

    private function createProject(string $previewUrl, string $defaultLocale, array $locales): Project
    {
        $project = new Project();
        $project->uuid = Uuid::v4();
        $project->previewUrl = $previewUrl;
        $project->defaultLocale = $defaultLocale;
        $project->locales = $locales;
        $project->settings = [];

        return $project;
    }

    private function createCollection(Project $project, string $name, string $slug): Collection
    {
        $collection = new Collection();
        $collection->uuid = Uuid::v4();
        $collection->name = $name;
        $collection->slug = $slug;
        $collection->project = $project;
        $collection->settings = [];

        return $collection;
    }

    private function createEntry(string $slug, \DateTimeImmutable $updatedAt): ContentEntry
    {
        $entry = new ContentEntry();
        $entry->slug = $slug;
        $entry->updatedAt = $updatedAt;
        $entry->status = 'published';
        $entry->metaTitle = 'Test ' . $slug;

        return $entry;
    }
}
