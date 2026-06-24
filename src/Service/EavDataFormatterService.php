<?php

namespace App\Service;

use App\Entity\ContentEntry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class EavDataFormatterService
{
    /**
     * Clés système réservées dans la réponse plate. Un champ de collection
     * dont le slug correspond à l'une d'elles NE DOIT PAS écraser la valeur
     * système (sinon, ex. un champ « status » masque le statut publish/draft).
     */
    private const RESERVED_KEYS = [
        'id', 'uuid', 'locale', 'status', 'collection',
        'created_at', 'updated_at', 'deleted_at', 'published_at', 'scheduled_at',
        'creator', 'updater', 'assigned_to',
    ];

    public function __construct(
        private LoggerInterface $logger = new NullLogger(),
        private ?\App\Service\Seo\StructuredDataGenerator $structuredDataGenerator = null,
        private ?\App\Service\Seo\HreflangGenerator $hreflangGenerator = null,
    ) {}

    /**
     * Formats a ContentEntry and its EAV field values into a flat JSON-friendly array.
     */
    public function formatEntry(ContentEntry $entry): array
    {
        $data = [
            'id'           => $entry->id,
            'uuid'         => $entry->uuid?->toRfc4122(),
            'locale'       => $entry->locale,
            'status'       => $entry->status,
            'collection'   => $entry->collection?->slug,
            'created_at'   => $entry->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at'   => $entry->updatedAt->format(\DateTimeInterface::ATOM),
            'deleted_at'   => $entry->deletedAt?->format(\DateTimeInterface::ATOM),
            'published_at' => $entry->publishedAt?->format(\DateTimeInterface::ATOM),
            'scheduled_at' => $entry->scheduledAt?->format(\DateTimeInterface::ATOM),
            'creator'      => $entry->createdBy ? ['name' => $entry->createdBy->name ?: $entry->createdBy->email] : null,
            'updater'      => $entry->updatedBy ? ['name' => $entry->updatedBy->name ?: $entry->updatedBy->email] : null,
            'assigned_to'  => $entry->assignedTo !== null ? ['id' => $entry->assignedTo->id, 'name' => $entry->assignedTo->name] : null,
        ];

        foreach ($entry->fieldValues as $fieldValue) {
            $fieldName = $fieldValue->field?->slug;
            if (!$fieldName) {
                $this->logger->warning('orphan field value detected: ContentFieldValue#{id} has no field relation', [
                    'id' => $fieldValue->id,
                ]);
                continue;
            }

            // Un champ ne peut pas écraser une clé système (status, id, etc.).
            // On expose sa valeur sous un alias préfixé pour rester accessible.
            if (in_array($fieldName, self::RESERVED_KEYS, true)) {
                $this->logger->warning('field slug "{slug}" collides with a reserved system key; exposed as "field_{slug}"', [
                    'slug' => $fieldName,
                ]);
                $fieldName = 'field_' . $fieldName;
            }

            $value = match ($fieldValue->fieldType) {
                'text', 'textarea', 'richtext', 'wysiwyg', 'markdown',
                'email', 'url', 'color', 'password', 'slug', 'longtext',
                'code', 'icon', 'uuid', 'time'                           => $fieldValue->textValue,
                'number', 'decimal', 'rating'                            => $fieldValue->numberValue !== null ? (float) $fieldValue->numberValue : null,
                'boolean', 'checkbox'                                    => $fieldValue->booleanValue,
                'date'                                                   => $fieldValue->dateValue?->format('Y-m-d'),
                'datetime'                                               => $fieldValue->datetimeValue?->format(\DateTimeInterface::ATOM),
                'json', 'array', 'repeater',
                'media', 'relation', 'enumeration', 'tags'               => $fieldValue->jsonValue,
                default                                                  => $fieldValue->textValue,
            };

            $data[$fieldName] = $value;
        }

        // ── Bloc SEO natif ──
        $collectionSeo = $entry->collection?->settings['seo'] ?? [];
        $projectSeo = $entry->project?->settings['seo'] ?? [];
        $siteName = $projectSeo['siteName'] ?? 'Jambo';
        $structuredDataType = $collectionSeo['structuredDataType'] ?? 'Article';

        $ogTitle = $entry->metaTitle ?? $data['title'] ?? $siteName;
        $ogDesc = $entry->metaDescription ?? $data['description'] ?? '';
        $ogImage = $entry->ogImage ?? $projectSeo['defaultOgImage'] ?? null;

        $data['_seo'] = [
            'metaTitle'       => $entry->metaTitle,
            'metaDescription' => $entry->metaDescription,
            'slug'            => $entry->slug,
            'canonicalUrl'    => $entry->canonicalUrl,
            'ogImage'         => $ogImage,
            'score'           => $entry->seoScore,
            'openGraph'       => [
                'title'       => $ogTitle,
                'description' => $ogDesc,
                'image'       => $ogImage,
                'type'        => 'article',
                'siteName'    => $siteName,
            ],
            'twitter' => [
                'card'        => !empty($ogImage) ? 'summary_large_image' : 'summary',
                'title'       => $ogTitle,
                'description' => $ogDesc,
                'image'       => $ogImage,
            ],
            'structuredData' => $this->structuredDataGenerator
                ? $this->structuredDataGenerator->generate($entry, $structuredDataType)
                : null,
            'hreflang' => $this->hreflangGenerator
                ? $this->hreflangGenerator->generateHreflang($entry)
                : [],
        ];

        return $data;
    }
}
