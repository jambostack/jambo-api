<?php

namespace App\Service\Seo;

use App\Entity\Project;
use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Repository\ContentEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;

class SitemapGenerator
{
    public function __construct(
        private EntityManagerInterface $em,
        private ContentEntryRepository $entryRepo,
        private ?CacheInterface $cache = null,
    ) {}

    public function generateSitemap(Project $project): string
    {
        $cacheKey = 'sitemap_' . $project->uuid?->toRfc4122();
        if ($this->cache) {
            return $this->cache->get($cacheKey, fn () => $this->doGenerateSitemap($project));
        }
        return $this->doGenerateSitemap($project);
    }

    private function doGenerateSitemap(Project $project): string
    {
        $seoSettings = $project->settings['seo'] ?? [];
        $excludeCollections = $seoSettings['sitemapExcludeCollections'] ?? [];

        $collections = $this->em->getRepository(Collection::class)
            ->findBy(['project' => $project, 'deletedAt' => null]);

        $urls = [];
        $baseUrl = rtrim($project->previewUrl ?? 'https://example.com', '/');

        foreach ($collections as $collection) {
            if (in_array($collection->slug, $excludeCollections, true)) {
                continue;
            }

            $seoConfig = $collection->settings['seo'] ?? [];
            if (($seoConfig['indexable'] ?? true) === false) {
                continue;
            }

            $entries = $this->entryRepo->findByCollectionPaginated($collection, 1, 1000, null, 'published');
            foreach ($entries as $entry) {
                if (empty($entry->slug)) {
                    continue;
                }
                $urls[] = [
                    'loc' => $baseUrl . '/' . $collection->slug . '/' . $entry->slug,
                    'lastmod' => $entry->updatedAt->format('Y-m-d'),
                    'changefreq' => $seoConfig['sitemapChangefreq'] ?? 'weekly',
                    'priority' => (string) ($seoConfig['sitemapPriority'] ?? 0.5),
                ];
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $url) {
            $xml .= "  <url>\n";
            $xml .= "    <loc>{$url['loc']}</loc>\n";
            $xml .= "    <lastmod>{$url['lastmod']}</lastmod>\n";
            $xml .= "    <changefreq>{$url['changefreq']}</changefreq>\n";
            $xml .= "    <priority>{$url['priority']}</priority>\n";
            $xml .= "  </url>\n";
        }
        $xml .= '</urlset>';

        return $xml;
    }

    public function generateImageSitemap(Project $project): string
    {
        // Similaire avec namespace image
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
            . ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'
            . "\n</urlset>";
    }
}
