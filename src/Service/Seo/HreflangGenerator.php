<?php

namespace App\Service\Seo;

use App\Entity\ContentEntry;

class HreflangGenerator
{
    /** @return array<string, string> locale → URL */
    public function generateHreflang(ContentEntry $entry): array
    {
        $locales = $entry->collection?->project?->locales ?? ['en'];
        $baseUrl = rtrim($entry->collection?->project?->previewUrl ?? 'https://example.com', '/');
        $collectionSlug = $entry->collection?->slug ?? '';
        $links = [];

        foreach ($locales as $locale) {
            $links[$locale] = $baseUrl . '/' . $collectionSlug . '/' . $entry->slug . '?locale=' . $locale;
        }
        $links['x-default'] = $links[$entry->collection?->project?->defaultLocale ?? 'en'] ?? reset($links);

        return $links;
    }
}
