<?php

declare(strict_types=1);

namespace App\Service\Seo;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Repository\ContentEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Suggère des liens internes entre les entrées d'un même projet.
 *
 * Utilise une approche par mots-clés extraits du contenu de l'entrée source
 * et recherche les entrées cibles pertinentes par similarité sémantique simple
 * (TF-IDF-like scoring sur les titres, slugs et meta descriptions).
 */
class InternalLinkSuggester
{
    private const MIN_KEYWORD_LENGTH = 4;
    private const MAX_SUGGESTIONS = 5;
    private const MIN_SCORE_THRESHOLD = 2.0;

    /** @var array<string, string[]> stop words français + anglais */
    private const STOP_WORDS = [
        'le', 'la', 'les', 'des', 'une', 'dans', 'pour', 'sur', 'avec',
        'the', 'and', 'for', 'with', 'from', 'this', 'that', 'have',
    ];

    public function __construct(
        private readonly ContentEntryRepository $entryRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Suggère des liens internes pour une entrée source.
     *
     * @param ContentEntry $source L'entrée pour laquelle chercher des liens
     * @param int|null $limit Nombre maximum de suggestions (défaut: self::MAX_SUGGESTIONS)
     * @return array<int, array{entry: ContentEntry, matchedKeyword: string, score: float, suggestedAnchor: string}>
     */
    public function suggest(ContentEntry $source, ?int $limit = null): array
    {
        $limit ??= self::MAX_SUGGESTIONS;

        // 1. Extraire les mots-clés significatifs du contenu de l'entrée source
        $keywords = $this->extractKeywords($source);
        if ($keywords === []) {
            return [];
        }

        // 2. Récupérer les entrées candidates (même projet, publiées, pas la source)
        $collection = $source->collection;
        if (!$collection) {
            return [];
        }

        $candidates = $this->entryRepo->findByCollectionPaginated(
            $collection,
            1,
            200,
            null,
            'published',
        );

        // Filtrer la source elle-même
        $candidates = array_filter(
            $candidates,
            fn (ContentEntry $e) => $e->id !== $source->id,
        );

        if ($candidates === []) {
            return [];
        }

        // 3. Scorer chaque candidat contre les mots-clés
        $scored = [];
        foreach ($candidates as $candidate) {
            $bestMatch = $this->scoreCandidate($candidate, $keywords);
            if ($bestMatch['score'] >= self::MIN_SCORE_THRESHOLD) {
                $scored[] = $bestMatch;
            }
        }

        // 4. Trier par score décroissant et limiter
        usort($scored, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * Suggère des liens à travers toutes les collections d'un projet.
     *
     * @param ContentEntry $source
     * @param int|null $limit
     * @return array<int, array{entry: ContentEntry, matchedKeyword: string, score: float, suggestedAnchor: string}>
     */
    public function suggestCrossCollection(ContentEntry $source, ?int $limit = null): array
    {
        $limit ??= self::MAX_SUGGESTIONS;
        $project = $source->project;
        if (!$project) {
            return [];
        }

        $keywords = $this->extractKeywords($source);
        if ($keywords === []) {
            return [];
        }

        $scored = [];
        $collections = $project->collections;

        foreach ($collections as $collection) {
            if ($collection->isDeleted()) {
                continue;
            }

            $entries = $this->entryRepo->findByCollectionPaginated(
                $collection,
                1,
                200,
                null,
                'published',
            );

            foreach ($entries as $entry) {
                if ($entry->id === $source->id) {
                    continue;
                }

                $best = $this->scoreCandidate($entry, $keywords);
                if ($best['score'] >= self::MIN_SCORE_THRESHOLD) {
                    $scored[] = $best;
                }
            }
        }

        usort($scored, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /**
     * Extrait les mots-clés significatifs du contenu d'une entrée.
     *
     * @return string[] mots-clés uniques, en minuscules
     */
    public function extractKeywords(ContentEntry $entry): array
    {
        $textParts = [];

        // Meta title
        if (!empty($entry->metaTitle)) {
            $textParts[] = $entry->metaTitle;
        }

        // Meta description
        if (!empty($entry->metaDescription)) {
            $textParts[] = $entry->metaDescription;
        }

        // Contenu EAV
        foreach ($entry->fieldValues as $fv) {
            if (in_array($fv->fieldType, ['text', 'longtext', 'richtext', 'wysiwyg', 'markdown'], true)) {
                $textParts[] = strip_tags($fv->textValue ?? '');
            }
        }

        $fullText = implode(' ', $textParts);
        $words = preg_split('/\s+/', strtolower($fullText));
        if ($words === false) {
            return [];
        }

        // Filtrer : longueur minimale, pas de stop words, pas de nombres purs
        $filtered = [];
        foreach ($words as $word) {
            $clean = trim($word, ".,;:!?()[]{}\"'«»“”'`…–—-/\\|@#$%^&*+=<>");
            if (
                mb_strlen($clean) < self::MIN_KEYWORD_LENGTH
                || in_array($clean, self::STOP_WORDS, true)
                || is_numeric($clean)
            ) {
                continue;
            }
            $filtered[] = $clean;
        }

        // Compter les occurrences et garder les plus fréquentes (top 15)
        $freq = array_count_values($filtered);
        arsort($freq);

        return array_slice(array_keys($freq), 0, 15);
    }

    /**
     * Score un candidat contre une liste de mots-clés.
     *
     * @return array{entry: ContentEntry, matchedKeyword: string, score: float, suggestedAnchor: string}
     */
    private function scoreCandidate(ContentEntry $candidate, array $keywords): array
    {
        $bestScore = 0.0;
        $bestKeyword = '';

        $searchText = strtolower(
            ($candidate->metaTitle ?? '')
            . ' ' . ($candidate->metaDescription ?? '')
            . ' ' . $candidate->slug,
        );

        foreach ($keywords as $keyword) {
            $count = substr_count($searchText, $keyword);
            if ($count > 0) {
                // Score : nombre d'occurrences × longueur du mot-clé (favorise les correspondances précises)
                $score = $count * mb_strlen($keyword) * 1.0 / 10;
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestKeyword = $keyword;
                }
            }

            // Bonus si le mot-clé apparaît dans le titre (×2)
            if (stripos($candidate->metaTitle ?? '', $keyword) !== false) {
                $bestScore += 1.0;
                $bestKeyword = $bestKeyword ?: $keyword;
            }
        }

        $anchor = $candidate->metaTitle ?? $candidate->slug;
        $collectionSlug = $candidate->collection?->slug ?? '';

        return [
            'entry' => $candidate,
            'matchedKeyword' => $bestKeyword,
            'score' => round($bestScore, 2),
            'suggestedAnchor' => $anchor . ' → /' . $collectionSlug . '/' . $candidate->slug,
        ];
    }
}
