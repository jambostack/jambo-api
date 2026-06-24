<?php
namespace App\Tests\Service\Seo;

use App\Service\Seo\SeoAnalyzer;
use App\Entity\ContentEntry;
use App\Entity\ContentFieldValue;
use PHPUnit\Framework\TestCase;

class SeoAnalyzerTest extends TestCase
{
    private SeoAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new SeoAnalyzer();
    }

    public function testAnalyzePerfectEntry(): void
    {
        $entry = $this->createMockEntry(
            metaTitle: 'Guide SEO 2026 complet — Comment bien référencer son site',
            metaDescription: 'Découvrez les meilleures pratiques SEO 2026 : optimisation on-page, netlinking, Core Web Vitals et stratégies de contenu pour booster votre trafic.',
            slug: 'guide-seo-2026',
            ogImage: 'uuid-image',
            content: str_repeat('SEO 2026 est une année clé pour le référencement. ', 50), // ~450 mots
        );

        $score = $this->analyzer->analyze($entry, 'SEO 2026');
        $this->assertGreaterThanOrEqual(80, $score->score);
        $this->assertNotEmpty($score->criteria);
    }

    public function testAnalyzePoorEntry(): void
    {
        $entry = $this->createMockEntry(
            metaTitle: 'Article',
            metaDescription: null,
            slug: 'a',
            ogImage: null,
            content: 'Court.',
        );

        $score = $this->analyzer->analyze($entry);
        $this->assertLessThan(50, $score->score);
    }

    public function testTitleLengthScoring(): void
    {
        $defaults = $this->defaults();
        $defaults['metaTitle'] = 'Trop court';
        $entry = $this->createMockEntry(...$defaults);
        $score = $this->analyzer->analyze($entry);
        $titleCriterion = $score->criteria['title_length'] ?? null;
        $this->assertNotNull($titleCriterion);
        $this->assertFalse($titleCriterion['passed']);

        $entry->metaTitle = 'Un titre parfaitement calibré entre 50 et 60 caractères';
        $score = $this->analyzer->analyze($entry);
        $this->assertTrue($score->criteria['title_length']['passed']);
    }

    public function testAuditIncludesBrokenLinks(): void
    {
        $entry = $this->createMockEntry(...$this->defaults());
        $report = $this->analyzer->audit($entry);
        $this->assertNotNull($report->score);
        $this->assertIsArray($report->brokenLinks);
    }

    private function createMockEntry(string $metaTitle, ?string $metaDescription, string $slug, ?string $ogImage, string $content): ContentEntry
    {
        $entry = new ContentEntry();
        $entry->metaTitle = $metaTitle;
        $entry->metaDescription = $metaDescription;
        $entry->slug = $slug;
        $entry->ogImage = $ogImage;
        $entry->status = 'published';

        // content → simulated via fieldValues (EAV)
        $fieldValue = new ContentFieldValue();
        $fieldValue->fieldType = 'richtext';
        $fieldValue->textValue = $content;
        $fieldValue->contentEntry = $entry;
        $entry->fieldValues->add($fieldValue);

        return $entry;
    }

    private function defaults(): array
    {
        return [
            'metaTitle' => 'Titre SEO correct avec plus de 50 caractères pour être bien',
            'metaDescription' => 'Une meta description suffisamment longue entre 120 et 155 caractères pour le référencement naturel.',
            'slug' => 'article-test',
            'ogImage' => 'uuid-img',
            'content' => str_repeat('lorem ipsum ', 50),
        ];
    }
}
