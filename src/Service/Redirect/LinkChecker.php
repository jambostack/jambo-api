<?php

declare(strict_types=1);

namespace App\Service\Redirect;

use App\Entity\ContentEntry;
use App\Entity\Project;
use App\Repository\ContentEntryRepository;

class LinkChecker
{
    /**
     * Field types that may contain HTML links.
     */
    private const LINKABLE_FIELD_TYPES = ['richtext', 'markdown', 'html'];

    public function __construct(
        private readonly ContentEntryRepository $entryRepository,
    ) {
    }

    /**
     * Scan a single ContentEntry for links in richtext/markdown/html fields.
     *
     * @return array<int, array{url: string, text: string}>
     */
    public function scanEntry(ContentEntry $entry): array
    {
        $links = [];

        foreach ($entry->fieldValues as $fieldValue) {
            if (!\in_array($fieldValue->fieldType, self::LINKABLE_FIELD_TYPES, true)) {
                continue;
            }

            $text = $fieldValue->textValue;
            if ($text === null || $text === '') {
                continue;
            }

            $links = array_merge($links, $this->extractLinks($text));
        }

        return $links;
    }

    /**
     * Check all links found in entries for the given project.
     *
     * @return array<int, array{entry: ContentEntry, url: string, text: string, status: string}>
     */
    public function checkLinks(Project $project): array
    {
        $entries = $this->entryRepository->findBy(['project' => $project]);
        $allSlugs = $this->buildSlugIndex($entries);
        $results = [];

        foreach ($entries as $entry) {
            $scanned = $this->scanEntry($entry);

            foreach ($scanned as $link) {
                $results[] = [
                    'entry' => $entry,
                    'url' => $link['url'],
                    'text' => $link['text'],
                    'status' => $this->classifyLink($link['url'], $allSlugs),
                ];
            }
        }

        return $results;
    }

    /**
     * @param ContentEntry[] $entries
     *
     * @return string[]
     */
    private function buildSlugIndex(array $entries): array
    {
        $slugs = [];

        foreach ($entries as $entry) {
            if ($entry->slug !== '') {
                $slugs[] = $entry->slug;
            }
        }

        return $slugs;
    }

    /**
     * Extract <a href="..."> links from HTML content.
     *
     * @return array<int, array{url: string, text: string}>
     */
    private function extractLinks(string $html): array
    {
        $links = [];
        $pattern = '/<a\s[^>]*href\s*=\s*"([^"]*)"[^>]*>([^<]*)<\/a>/is';

        if (preg_match_all($pattern, $html, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        foreach ($matches as $m) {
            $links[] = [
                'url' => $m[1],
                'text' => trim(strip_tags($m[2])),
            ];
        }

        return $links;
    }

    /**
     * Classify a URL as internal or external and check if it exists.
     *
     * @param string[] $existingSlugs
     */
    private function classifyLink(string $url, array $existingSlugs): string
    {
        $url = trim($url);

        // Empty or anchor-only
        if ($url === '' || $url === '#') {
            return 'skip';
        }

        // Absolute external URL
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return 'external';
        }

        // Mailto / tel
        if (str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:')) {
            return 'skip';
        }

        // Internal link — check slug existence
        $slug = ltrim($url, '/');
        if (\in_array($slug, $existingSlugs, true)) {
            return 'valid';
        }

        return 'broken';
    }
}
