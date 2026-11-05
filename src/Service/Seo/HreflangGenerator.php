<?php

namespace App\Service\Seo;

use App\Entity\ContentEntry;

class HreflangGenerator
{
    /** @return array<string, string> locale → URL */
    public function generateHreflang(ContentEntry $entry): array
    {
        $project = $entry->collection?->project;
        $locales = is_array($project?->locales) ? $project->locales : ['en'];

        $rawBaseUrl = rtrim($project?->previewUrl ?? 'https://example.com', '/');
        $baseUrl = str_replace(["\r", "\n", "\0"], '', $rawBaseUrl);
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || (!str_starts_with($baseUrl, 'http://') && !str_starts_with($baseUrl, 'https://'))) {
            $baseUrl = 'https://example.com';
        }

        $collectionSlug = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($entry->collection?->slug ?? ''));
        $entrySlug = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) ($entry->slug ?? ''));
        $links = [];

        foreach ($locales as $locale) {
            if (!is_string($locale)) {
                continue;
            }
            $cleanLocale = trim(str_replace(["\r", "\n", "\0"], '', $locale));
            if (!preg_match('/^[a-zA-Z0-9_\-]{1,10}$/', $cleanLocale)) {
                continue;
            }
            $cleanLocaleLower = strtolower($cleanLocale);
            $links[$cleanLocaleLower] = $baseUrl . '/' . $collectionSlug . '/' . $entrySlug . '?locale=' . urlencode($cleanLocaleLower);
        }

        if (empty($links)) {
            $links['en'] = $baseUrl . '/' . $collectionSlug . '/' . $entrySlug . '?locale=en';
        }

        $defaultLocale = strtolower(trim((string) ($project?->defaultLocale ?? 'en')));
        $links['x-default'] = $links[$defaultLocale] ?? reset($links);

        return $links;
    }
}
