<?php

namespace App\Service\Seo;

use App\Entity\ContentEntry;

class StructuredDataGenerator
{
    private const SUPPORTED_TYPES = ['Article', 'Product', 'FAQ', 'Event', 'Recipe', 'Organization', 'WebPage', 'BreadcrumbList'];

    /** @return array{@context: string, @type: string, ...} */
    public function generate(ContentEntry $entry, string $type): array
    {
        $type = in_array($type, self::SUPPORTED_TYPES, true) ? $type : 'Article';
        $baseUrl = 'https://example.com'; // sera injecté depuis Project settings

        return match ($type) {
            'Article' => $this->generateArticle($entry, $baseUrl),
            'WebPage' => $this->generateWebPage($entry, $baseUrl),
            'Product' => $this->generateProduct($entry),
            'FAQ' => $this->generateFaq($entry),
            'BreadcrumbList' => $this->generateBreadcrumb($entry, $baseUrl),
            default => $this->generateWebPage($entry, $baseUrl),
        };
    }

    public function isValid(ContentEntry $entry, string $type): bool
    {
        $data = $this->generate($entry, $type);
        // Validation basique : vérifier les champs obligatoires par type
        return match ($type) {
            'Article' => !empty($data['headline'] ?? '') && !empty($data['datePublished'] ?? ''),
            'Product' => !empty($data['name'] ?? ''),
            default => true,
        };
    }

    /** @return array{@context: string, @type: string, headline: string, description: string, image: ?string, datePublished: ?string, dateModified: string} */
    private function generateArticle(ContentEntry $entry, string $baseUrl): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $entry->metaTitle ?? '',
            'description' => $entry->metaDescription ?? '',
            'image' => $entry->ogImage ? $baseUrl . '/api/media/' . $entry->ogImage : null,
            'datePublished' => $entry->publishedAt?->format('c'),
            'dateModified' => $entry->updatedAt->format('c'),
        ];
    }

    /** @return array{@context: string, @type: string, name: string, description: string, url: string} */
    private function generateWebPage(ContentEntry $entry, string $baseUrl): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => $entry->metaTitle ?? '',
            'description' => $entry->metaDescription ?? '',
            'url' => $baseUrl . '/' . $entry->slug,
        ];
    }

    /** @return array{@context: string, @type: string, name: string, description: string} */
    private function generateProduct(ContentEntry $entry): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $entry->metaTitle ?? '',
            'description' => $entry->metaDescription ?? '',
        ];
    }

    /** @return array{@context: string, @type: string, mainEntity: array} */
    private function generateFaq(ContentEntry $entry): array
    {
        // Les FAQ sont générées depuis les champs EAV question/réponse — simplifié
        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => [],
        ];
    }

    /** @return array{@context: string, @type: string, itemListElement: array} */
    private function generateBreadcrumb(ContentEntry $entry, string $baseUrl): array
    {
        $collectionName = $entry->collection?->name ?? 'Home';
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $baseUrl],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $collectionName, 'item' => $baseUrl . '/' . ($entry->collection?->slug ?? '')],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $entry->metaTitle ?? $entry->slug],
            ],
        ];
    }
}
