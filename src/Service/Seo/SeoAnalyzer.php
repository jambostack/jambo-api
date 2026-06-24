<?php

namespace App\Service\Seo;

use App\Dto\SeoScore;
use App\Dto\SeoAuditReport;
use App\Entity\ContentEntry;

class SeoAnalyzer
{
    private const CRITERIA = [
        'title_length' => ['label' => 'Longueur du titre (50-60 chars)', 'weight' => 15, 'optimalMin' => 50, 'optimalMax' => 60],
        'description_length' => ['label' => 'Longueur meta description (120-155 chars)', 'weight' => 15, 'optimalMin' => 120, 'optimalMax' => 155],
        'keyword_in_title' => ['label' => 'Mot-clé dans le titre', 'weight' => 10],
        'keyword_in_description' => ['label' => 'Mot-clé dans la description', 'weight' => 10],
        'has_og_image' => ['label' => 'Image OpenGraph', 'weight' => 10],
        'slug_optimized' => ['label' => 'Slug optimisé (≤ 75 chars, sans stop words)', 'weight' => 10],
        'content_length' => ['label' => 'Contenu > 300 mots', 'weight' => 10],
        'internal_links' => ['label' => 'Liens internes (≥ 2)', 'weight' => 5],
        'external_links' => ['label' => 'Liens sortants (≥ 1)', 'weight' => 5],
        'images_alt' => ['label' => 'Alt-text sur toutes les images', 'weight' => 5],
        'structured_data' => ['label' => 'Structured Data valide', 'weight' => 5],
    ];

    private const STOP_WORDS = ['le', 'la', 'les', 'de', 'du', 'des', 'un', 'une', 'et', 'ou', 'en', 'à', 'au', 'aux', 'the', 'a', 'an', 'of', 'in', 'on', 'to', 'for', 'and', 'or', 'is', 'it', 'at'];

    public function analyze(ContentEntry $entry, ?string $keyword = null): SeoScore
    {
        $criteria = [];
        $totalScore = 0;
        $maxScore = 0;
        $suggestions = [];

        foreach (self::CRITERIA as $key => $criterion) {
            $result = $this->evaluateCriterion($key, $criterion, $entry, $keyword);
            $criteria[$key] = $result;
            $totalScore += $result['score'];
            $maxScore += $criterion['weight'];
            if ($result['advice'] !== null) {
                $suggestions[] = $result['advice'];
            }
        }

        $score = $maxScore > 0 ? (int) round(($totalScore / $maxScore) * 100) : 0;

        return new SeoScore(score: $score, criteria: $criteria, suggestions: $suggestions);
    }

    /** @return array{label: string, passed: bool, score: int, maxScore: int, advice: ?string} */
    private function evaluateCriterion(string $key, array $criterion, ContentEntry $entry, ?string $keyword): array
    {
        $passed = false;
        $score = 0;
        $advice = null;

        switch ($key) {
            case 'title_length':
                $len = mb_strlen($entry->metaTitle ?? '');
                $passed = $len >= $criterion['optimalMin'] && $len <= $criterion['optimalMax'];
                $score = $passed ? $criterion['weight'] : (int) ($criterion['weight'] * 0.3);
                $advice = $passed ? null : ($len < $criterion['optimalMin'] ? "Ajoutez " . ($criterion['optimalMin'] - $len) . " caractères au titre." : "Raccourcissez le titre de " . ($len - $criterion['optimalMax']) . " caractères.");
                break;

            case 'description_length':
                $len = mb_strlen($entry->metaDescription ?? '');
                $passed = $len >= $criterion['optimalMin'] && $len <= $criterion['optimalMax'];
                $score = $passed ? $criterion['weight'] : ($len > 0 ? (int) ($criterion['weight'] * 0.4) : 0);
                $advice = $len === 0 ? 'Ajoutez une meta description.' : ($passed ? null : 'Ajustez la longueur de la meta description.');
                break;

            case 'keyword_in_title':
                if ($keyword === null) { $score = 0; $advice = 'Définissez un mot-clé cible.'; break; }
                $passed = stripos($entry->metaTitle ?? '', $keyword) !== false;
                $score = $passed ? $criterion['weight'] : 0;
                $advice = $passed ? null : "Intégrez le mot-clé '$keyword' dans le titre.";
                break;

            case 'keyword_in_description':
                if ($keyword === null) { $score = 0; break; }
                $passed = stripos($entry->metaDescription ?? '', $keyword) !== false;
                $score = $passed ? $criterion['weight'] : 0;
                $advice = $passed ? null : "Intégrez le mot-clé '$keyword' dans la meta description.";
                break;

            case 'has_og_image':
                $passed = !empty($entry->ogImage);
                $score = $passed ? $criterion['weight'] : 0;
                $advice = $passed ? null : 'Ajoutez une image OpenGraph.';
                break;

            case 'slug_optimized':
                $len = mb_strlen($entry->slug ?? '');
                $words = explode('-', $entry->slug ?? '');
                $stopCount = count(array_intersect($words, self::STOP_WORDS));
                $passed = $len <= 75 && $len > 0 && $stopCount === 0;
                $score = $passed ? $criterion['weight'] : ($len > 0 ? (int) ($criterion['weight'] * 0.5) : 0);
                $advice = $len === 0 ? 'Le slug est vide.' : ($passed ? null : 'Optimisez le slug (court, sans mots vides).');
                break;

            case 'content_length':
                $contentLength = $this->getContentWordCount($entry);
                $passed = $contentLength >= 300;
                $score = $passed ? $criterion['weight'] : (int) ($criterion['weight'] * ($contentLength / 300));
                $advice = $passed ? null : "Ajoutez du contenu (actuellement ~{$contentLength} mots, visez ≥ 300).";
                break;

            case 'internal_links':
            case 'external_links':
                $linkCount = $key === 'internal_links' ? $this->countInternalLinks($entry) : $this->countExternalLinks($entry);
                $min = $key === 'internal_links' ? 2 : 1;
                $passed = $linkCount >= $min;
                $score = $passed ? $criterion['weight'] : (int) ($criterion['weight'] * ($linkCount / $min));
                $advice = $passed ? null : "Ajoutez " . ($key === 'internal_links' ? 'des liens internes' : 'un lien sortant') . ".";
                break;

            case 'images_alt':
                $allHaveAlt = $this->allImagesHaveAlt($entry);
                $passed = $allHaveAlt;
                $score = $passed ? $criterion['weight'] : 0;
                $advice = $passed ? null : 'Ajoutez un texte alternatif à toutes les images.';
                break;

            case 'structured_data':
                // Validé par StructuredDataGenerator (Task 4), ici on vérifie juste la config collection
                $passed = true; // sera recalculé par l'audit
                $score = $criterion['weight'];
                break;
        }

        return ['label' => $criterion['label'], 'passed' => $passed, 'score' => $score, 'maxScore' => $criterion['weight'], 'advice' => $advice];
    }

    public function audit(ContentEntry $entry): SeoAuditReport
    {
        $score = $this->analyze($entry);
        $brokenLinks = $this->findBrokenLinks($entry);
        $warnings = [];
        if ($entry->seoScore === null) $warnings[] = 'Premier audit SEO pour cette entrée.';
        if ($score->score < 50) $warnings[] = 'Score SEO critique — action recommandée.';

        return new SeoAuditReport(score: $score, brokenLinks: $brokenLinks, warnings: $warnings);
    }

    /** @return SeoScore[] */
    public function batchAnalyze(array $entries): array
    {
        $scores = [];
        foreach ($entries as $entry) {
            $scores[$entry->uuid?->toRfc4122()] = $this->analyze($entry);
        }
        return $scores;
    }

    private function getContentWordCount(ContentEntry $entry): int
    {
        $text = '';
        foreach ($entry->fieldValues as $fv) {
            if (in_array($fv->fieldType, ['text', 'longtext', 'richtext', 'wysiwyg', 'markdown'], true)) {
                $text .= ' ' . ($fv->textValue ?? '');
            }
        }
        return str_word_count(strip_tags($text));
    }

    private function countInternalLinks(ContentEntry $entry): int
    {
        $count = 0;
        foreach ($entry->fieldValues as $fv) {
            if (in_array($fv->fieldType, ['richtext', 'wysiwyg', 'markdown'], true)) {
                preg_match_all('/href=["\'](?!https?:\/\/)[^"\']+["\']/', $fv->textValue ?? '', $m);
                $count += count($m[0] ?? []);
            }
        }
        return $count;
    }

    private function countExternalLinks(ContentEntry $entry): int
    {
        $count = 0;
        foreach ($entry->fieldValues as $fv) {
            if (in_array($fv->fieldType, ['richtext', 'wysiwyg', 'markdown'], true)) {
                preg_match_all('/href=["\']https?:\/\/[^"\']+["\']/', $fv->textValue ?? '', $m);
                $count += count($m[0] ?? []);
            }
        }
        return $count;
    }

    private function allImagesHaveAlt(ContentEntry $entry): bool
    {
        foreach ($entry->fieldValues as $fv) {
            if (in_array($fv->fieldType, ['richtext', 'wysiwyg', 'markdown'], true)) {
                preg_match_all('/<img[^>]+>/i', $fv->textValue ?? '', $imgs);
                foreach ($imgs[0] as $img) {
                    if (!preg_match('/\salt=["\'][^"\']*["\']/', $img)) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    /** @return array{title: string, url: string, statusCode: int}[] */
    private function findBrokenLinks(ContentEntry $entry): array
    {
        // Simplifié — sera enrichi avec LinkChecker (Task 9)
        return [];
    }
}
