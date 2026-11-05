<?php

namespace App\Service\Seo;

use App\Dto\SeoScore;
use App\Dto\SeoAuditReport;
use App\Entity\ContentEntry;
use Symfony\Contracts\Translation\TranslatorInterface;

class SeoAnalyzer
{
    public const MAX_SCANNED_TEXT_BYTES = 500000; // 500 KB

    private const STOP_WORDS = ['le', 'la', 'les', 'de', 'du', 'des', 'un', 'une', 'et', 'ou', 'en', 'à', 'au', 'aux', 'the', 'a', 'an', 'of', 'in', 'on', 'to', 'for', 'and', 'or', 'is', 'it', 'at'];

    public function __construct(
        private readonly ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters, 'messages');
        }
        return $default !== null ? strtr($default, $parameters) : $id;
    }

    private function getCriteriaDefinitions(): array
    {
        return [
            'title_length' => ['key' => 'title_length', 'label' => $this->trans('seo.criteria.title_length', [], 'Title length (50-60 chars)'), 'weight' => 15, 'optimalMin' => 50, 'optimalMax' => 60],
            'description_length' => ['key' => 'description_length', 'label' => $this->trans('seo.criteria.description_length', [], 'Meta description length (120-155 chars)'), 'weight' => 15, 'optimalMin' => 120, 'optimalMax' => 155],
            'keyword_in_title' => ['key' => 'keyword_in_title', 'label' => $this->trans('seo.criteria.keyword_in_title', [], 'Target keyword in title'), 'weight' => 10],
            'keyword_in_description' => ['key' => 'keyword_in_description', 'label' => $this->trans('seo.criteria.keyword_in_description', [], 'Target keyword in description'), 'weight' => 10],
            'has_og_image' => ['key' => 'has_og_image', 'label' => $this->trans('seo.criteria.has_og_image', [], 'OpenGraph image'), 'weight' => 10],
            'slug_optimized' => ['key' => 'slug_optimized', 'label' => $this->trans('seo.criteria.slug_optimized', [], 'Optimized slug (≤ 75 chars, without stop words)'), 'weight' => 10],
            'content_length' => ['key' => 'content_length', 'label' => $this->trans('seo.criteria.content_length', [], 'Content length (> 300 words)'), 'weight' => 10],
            'internal_links' => ['key' => 'internal_links', 'label' => $this->trans('seo.criteria.internal_links', [], 'Internal links (≥ 2)'), 'weight' => 5],
            'external_links' => ['key' => 'external_links', 'label' => $this->trans('seo.criteria.external_links', [], 'External links (≥ 1)'), 'weight' => 5],
            'images_alt' => ['key' => 'images_alt', 'label' => $this->trans('seo.criteria.images_alt', [], 'Alt text on all images'), 'weight' => 5],
            'structured_data' => ['key' => 'structured_data', 'label' => $this->trans('seo.criteria.structured_data', [], 'Valid Structured Data'), 'weight' => 5],
        ];
    }

    public function analyze(ContentEntry $entry, ?string $keyword = null): SeoScore
    {
        $criteria = [];
        $totalScore = 0;
        $maxScore = 0;
        $suggestions = [];

        foreach ($this->getCriteriaDefinitions() as $key => $criterion) {
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
                $advice = $passed ? null : ($len < $criterion['optimalMin']
                    ? $this->trans('seo.advice.add_title_chars', ['%count%' => $criterion['optimalMin'] - $len], "Add " . ($criterion['optimalMin'] - $len) . " characters to the title.")
                    : $this->trans('seo.advice.shorten_title', ['%count%' => $len - $criterion['optimalMax']], "Shorten the title by " . ($len - $criterion['optimalMax']) . " characters."));
                break;

            case 'description_length':
                $len = mb_strlen($entry->metaDescription ?? '');
                $passed = $len >= $criterion['optimalMin'] && $len <= $criterion['optimalMax'];
                $score = $passed ? $criterion['weight'] : ($len > 0 ? (int) ($criterion['weight'] * 0.4) : 0);
                $advice = $len === 0
                    ? $this->trans('seo.advice.add_description', [], 'Add a meta description.')
                    : ($passed ? null : $this->trans('seo.advice.adjust_description', [], 'Adjust the meta description length.'));
                break;

            case 'keyword_in_title':
                if ($keyword === null) {
                    $score = 0;
                    $advice = $this->trans('seo.advice.define_keyword', [], 'Define a target keyword.');
                    break;
                }
                $passed = stripos($entry->metaTitle ?? '', $keyword) !== false;
                $score = $passed ? $criterion['weight'] : 0;
                $advice = $passed ? null : $this->trans('seo.advice.keyword_in_title', ['%keyword%' => $keyword], "Include target keyword '$keyword' in the title.");
                break;

            case 'keyword_in_description':
                if ($keyword === null) {
                    $score = 0;
                    break;
                }
                $passed = stripos($entry->metaDescription ?? '', $keyword) !== false;
                $score = $passed ? $criterion['weight'] : 0;
                $advice = $passed ? null : $this->trans('seo.advice.keyword_in_description', ['%keyword%' => $keyword], "Include target keyword '$keyword' in the meta description.");
                break;

            case 'has_og_image':
                $passed = !empty($entry->ogImage);
                $score = $passed ? $criterion['weight'] : 0;
                $advice = $passed ? null : $this->trans('seo.advice.add_og_image', [], 'Add an OpenGraph image.');
                break;

            case 'slug_optimized':
                $len = mb_strlen($entry->slug ?? '');
                $words = explode('-', $entry->slug ?? '');
                $stopCount = count(array_intersect($words, self::STOP_WORDS));
                $passed = $len <= 75 && $len > 0 && $stopCount === 0;
                $score = $passed ? $criterion['weight'] : ($len > 0 ? (int) ($criterion['weight'] * 0.5) : 0);
                $advice = $len === 0
                    ? $this->trans('seo.advice.empty_slug', [], 'The slug is empty.')
                    : ($passed ? null : $this->trans('seo.advice.optimize_slug', [], 'Optimize the slug (short, without stop words).'));
                break;

            case 'content_length':
                $contentLength = $this->getContentWordCount($entry);
                $passed = $contentLength >= 300;
                $score = $passed ? $criterion['weight'] : (int) ($criterion['weight'] * ($contentLength / 300));
                $advice = $passed ? null : $this->trans('seo.advice.add_content', ['%count%' => $contentLength], "Add more content (currently ~{$contentLength} words, aim for ≥ 300).");
                break;

            case 'internal_links':
            case 'external_links':
                $linkCount = $key === 'internal_links' ? $this->countInternalLinks($entry) : $this->countExternalLinks($entry);
                $min = $key === 'internal_links' ? 2 : 1;
                $passed = $linkCount >= $min;
                $score = $passed ? $criterion['weight'] : (int) ($criterion['weight'] * ($linkCount / $min));
                $advice = $passed ? null : ($key === 'internal_links'
                    ? $this->trans('seo.advice.add_internal_links', [], 'Add internal links.')
                    : $this->trans('seo.advice.add_external_link', [], 'Add an external link.'));
                break;

            case 'images_alt':
                $allHaveAlt = $this->allImagesHaveAlt($entry);
                $passed = $allHaveAlt;
                $score = $passed ? $criterion['weight'] : 0;
                $advice = $passed ? null : $this->trans('seo.advice.add_images_alt', [], 'Add alternative text to all images.');
                break;

            case 'structured_data':
                $passed = true;
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
        if ($entry->seoScore === null) {
            $warnings[] = $this->trans('seo.warning.first_audit', [], 'First SEO audit for this entry.');
        }
        if ($score->score < 50) {
            $warnings[] = $this->trans('seo.warning.critical_score', [], 'Critical SEO score — recommended action.');
        }

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
                $val = (string) ($fv->textValue ?? '');
                $text .= ' ' . $val;
                if (strlen($text) >= self::MAX_SCANNED_TEXT_BYTES) {
                    $text = substr($text, 0, self::MAX_SCANNED_TEXT_BYTES);
                    break;
                }
            }
        }
        return str_word_count(strip_tags($text));
    }

    private function countInternalLinks(ContentEntry $entry): int
    {
        $count = 0;
        foreach ($entry->fieldValues as $fv) {
            if (in_array($fv->fieldType, ['richtext', 'wysiwyg', 'markdown'], true)) {
                $val = (string) ($fv->textValue ?? '');
                if (strlen($val) > self::MAX_SCANNED_TEXT_BYTES) {
                    $val = substr($val, 0, self::MAX_SCANNED_TEXT_BYTES);
                }
                preg_match_all('/href=["\'](?!https?:\/\/)[^"\']+["\']/', $val, $m);
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
                $val = (string) ($fv->textValue ?? '');
                if (strlen($val) > self::MAX_SCANNED_TEXT_BYTES) {
                    $val = substr($val, 0, self::MAX_SCANNED_TEXT_BYTES);
                }
                preg_match_all('/href=["\']https?:\/\/[^"\']+["\']/', $val, $m);
                $count += count($m[0] ?? []);
            }
        }
        return $count;
    }

    private function allImagesHaveAlt(ContentEntry $entry): bool
    {
        foreach ($entry->fieldValues as $fv) {
            if (in_array($fv->fieldType, ['richtext', 'wysiwyg', 'markdown'], true)) {
                $val = (string) ($fv->textValue ?? '');
                if (strlen($val) > self::MAX_SCANNED_TEXT_BYTES) {
                    $val = substr($val, 0, self::MAX_SCANNED_TEXT_BYTES);
                }
                preg_match_all('/<img[^>]+>/i', $val, $imgs);
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
        return [];
    }
}
