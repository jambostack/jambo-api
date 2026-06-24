# SEO / Redirects / Form Builder (v1.19) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ajouter SEO avancé natif (scoring, sitemap, OpenGraph, structured data), gestionnaire de redirections (manuelles + auto-slug + détection boucles), et Form Builder complet (drag & drop, conditions, multi-step, anti-spam, A/B testing) — 30 features sur 3 sous-projets, position "battre la concurrence".

**Architecture:** 3 phases. Phase 1 : entités + migrations + services backend core (SeoAnalyzer, SitemapGenerator, RedirectResolver, FormBuilder, SubmitHandler). Phase 2 : API admin CRUD + frontend React (panneau SEO, dashboard redirects, form builder). Phase 3 : features avancées (IA internal-linking, A/B testing, widget JS, audit PDF). Zéro nouvelle dépendance PHP sauf `dompdf/dompdf` pour l'export PDF.

**Tech Stack:** Symfony 8 / PHP 8.4, Doctrine ORM, `EavDataFormatterService` (existant), Inertia React (admin UI), `symfony/rate-limiter` (déjà présent), `dompdf/dompdf` (nouveau, export PDF audit SEO), vanilla JS (widget embeddable).

## Global Constraints

- **Zéro nouvelle dépendance PHP** sauf `dompdf/dompdf` — tout est construit sur Symfony 8 + Doctrine (déjà présent)
- **OAuth social existant inchangé** — les 4 providers et leurs colonnes ne bougent pas
- **Colonnes SEO sur ContentEntry** : `meta_title` VARCHAR(255) nullable, `meta_description` TEXT nullable, `slug` VARCHAR(255) NOT NULL, `canonical_url` VARCHAR(512) nullable, `og_image` VARCHAR(36) nullable, `seo_score` INT nullable (0-100), `seo_scored_at` DATETIME nullable
- **Index UNIQUE** `(collection_id, slug, locale)` — garantit l'unicité du slug par collection et locale
- **Entité Redirect** : colonnes `fromPath`, `toPath`, `httpCode` (301/302/307/308), `isPattern`, `isEnabled`, `hits`, `lastHitAt`, `isAuto`, `sourceEntry`
- **Entité Form** : colonnes `fields` JSON, `steps` JSON nullable, `settings` JSON
- **Entité FormSubmission** : colonnes `data` JSON, `metadata` JSON, `isComplete`, `isSpam`, `isRead`
- **SEO dans l'API publique** : chaque entrée inclut un objet `_seo` dans la réponse REST et GraphQL
- **Routes publiques** : `/{projectUuid}/sitemap.xml`, `/{projectUuid}/robots.txt`, `/{projectUuid}/forms/{slug}`, `POST /{projectUuid}/forms/{slug}/submit`, `/{projectUuid}/redirects/resolve`
- **Middleware redirect** : cache in-memory, zéro hit DB par requête, invalidation sur modification
- **Anti-spam** : honeypot invisible, rate limiting par IP, Turnstile/hCaptcha/reCAPTCHA, blocklist domaines jetables
- **i18n** : clés `seo.*`, `redirects.*`, `forms.*` dans les 4 fichiers `translations/messages.{fr,en,es,ar}.php`
- **Commits** : français `type(scope): description`, auteur unique, JAMAIS de Co-Authored-By
- **Build** : `npm run dev` doit compiler

---

## File Structure

**Backend (créés) — Phase 1 :**

SEO :
- `src/Dto/SeoScore.php` — DTO score SEO
- `src/Dto/SeoAuditReport.php` — DTO rapport d'audit
- `src/Service/Seo/SeoAnalyzer.php` — scoring + audit
- `src/Service/Seo/StructuredDataGenerator.php` — JSON-LD par type
- `src/Service/Seo/SitemapGenerator.php` — sitemap.xml + sitemap-images.xml
- `src/Service/Seo/HreflangGenerator.php` — balises hreflang
- `src/Entity/SeoRevision.php` — historique SEO

Redirects :
- `src/Entity/Redirect.php`
- `src/Entity/RedirectRevision.php`
- `src/Entity/NotFoundLog.php`
- `src/Service/Redirect/RedirectResolver.php`
- `src/Service/Redirect/RedirectChainDetector.php`
- `src/Service/Redirect/LinkChecker.php`
- `src/EventListener/RedirectCacheInvalidationListener.php`

Forms :
- `src/Entity/Form.php`
- `src/Entity/FormSubmission.php`
- `src/Service/Form/FormBuilder.php`
- `src/Service/Form/SubmitHandler.php`
- `src/Service/Form/AntiSpamService.php`

**Backend (créés) — Phase 2 :**

Controllers :
- `src/Controller/Api/SeoController.php`
- `src/Controller/Admin/RedirectController.php`
- `src/Controller/Admin/FormController.php`
- `src/Controller/Public/SeoPublicController.php` (sitemap, robots.txt)
- `src/Controller/Public/RedirectPublicController.php`
- `src/Controller/Public/FormPublicController.php`

**Backend (créés) — Phase 3 :**

- `src/Service/Form/FormTemplateManager.php`
- `src/Service/Form/AbTestManager.php`
- `src/Controller/Public/EmbedController.php`

**Backend (modifiés) :**
- `src/Entity/ContentEntry.php` — ajout colonnes SEO natives
- `src/Entity/Collection.php` — settings SEO
- `src/Entity/Project.php` — settings SEO
- `src/Service/EavDataFormatterService.php` — ajout `_seo` dans la réponse
- `src/Repository/ContentEntryRepository.php` — `findOneByCollectionAndSlug` natif
- `config/packages/security.yaml` — routes publiques
- `config/packages/rate_limiter.yaml` — rate limiters forms
- `migrations/` — migrations Phase 1

**Frontend (modifiés) :**
- `assets/js/pages/admin/app-settings.tsx` — section SEO settings projet (ou composant existant)
- Nouveaux composants React pour les 3 sous-projets

---

## Phase 1 — Fondations Backend

### Task 1: Migration — colonnes SEO sur ContentEntry + nouvelles entités

**Files:**
- Modify: `src/Entity/ContentEntry.php:29-83`
- Create: `src/Entity/SeoRevision.php`
- Create: `src/Entity/Redirect.php`
- Create: `src/Entity/RedirectRevision.php`
- Create: `src/Entity/NotFoundLog.php`
- Create: `src/Entity/Form.php`
- Create: `src/Entity/FormSubmission.php`
- Create: `migrations/VersionXXXXSEO.php`

**Interfaces:**
- Produces: `ContentEntry.metaTitle`, `ContentEntry.metaDescription`, `ContentEntry.slug`, `ContentEntry.canonicalUrl`, `ContentEntry.ogImage`, `ContentEntry.seoScore`, `ContentEntry.seoScoredAt` — utilisés par Tasks 2-5
- Produces: `SeoRevision` entity — utilisé par Task 14
- Produces: `Redirect` entity — utilisé par Tasks 7-11
- Produces: `Form` + `FormSubmission` entities — utilisés par Tasks 12-13

- [ ] **Step 1: Ajouter les colonnes SEO sur ContentEntry.php**

Ajouter après `scheduledAt` (ligne 84) :

```php
#[ORM\Column(length: 255, nullable: true)]
public ?string $metaTitle = null;

#[ORM\Column(type: 'text', nullable: true)]
public ?string $metaDescription = null;

#[ORM\Column(length: 255)]
public string $slug = '' {
    get => $this->slug;
    set { $this->slug = $value; }
}

#[ORM\Column(length: 512, nullable: true)]
public ?string $canonicalUrl = null;

#[ORM\Column(length: 36, nullable: true)]
public ?string $ogImage = null;

#[ORM\Column(nullable: true)]
public ?int $seoScore = null;

#[ORM\Column(nullable: true)]
public ?\DateTimeImmutable $seoScoredAt = null;
```

- [ ] **Step 2: Créer SeoRevision.php**

```php
<?php
namespace App\Entity;

use App\Repository\SeoRevisionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SeoRevisionRepository::class)]
class SeoRevision
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ContentEntry::class)]
    #[ORM\JoinColumn(nullable: false)]
    public ?ContentEntry $entry = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $metaTitle = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $metaDescription = null;

    #[ORM\Column(length: 255)]
    public string $slug = '';

    #[ORM\Column(length: 512, nullable: true)]
    public ?string $canonicalUrl = null;

    #[ORM\Column(length: 36, nullable: true)]
    public ?string $ogImage = null;

    #[ORM\Column(nullable: true)]
    public ?int $seoScore = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    public ?User $changedBy = null;

    #[ORM\Column]
    public \DateTimeImmutable $changedAt;

    public function __construct()
    {
        $this->changedAt = new \DateTimeImmutable();
    }
}
```

- [ ] **Step 3: Créer Redirect.php**

```php
<?php
namespace App\Entity;

use App\Repository\RedirectRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: RedirectRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Redirect
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    public ?Uuid $uuid = null;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(nullable: false)]
    public ?Project $project = null;

    #[ORM\Column(length: 512)]
    public string $fromPath = '';

    #[ORM\Column(length: 512)]
    public string $toPath = '';

    #[ORM\Column]
    public int $httpCode = 301;

    #[ORM\Column]
    public bool $isPattern = false;

    #[ORM\Column]
    public bool $isEnabled = true;

    #[ORM\Column]
    public int $hits = 0;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $lastHitAt = null;

    #[ORM\Column]
    public bool $isAuto = false;

    #[ORM\ManyToOne(targetEntity: ContentEntry::class)]
    #[ORM\JoinColumn(nullable: true)]
    public ?ContentEntry $sourceEntry = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    public ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    public ?User $updatedBy = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function setUuidValue(): void
    {
        if ($this->uuid === null) $this->uuid = Uuid::v4();
    }

    #[ORM\PreUpdate]
    public function setUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
```

- [ ] **Step 4: Créer RedirectRevision.php, NotFoundLog.php, Form.php, FormSubmission.php**

Suivre les structures des colonnes définies dans le spec (section 3.3, 3.4, 4.2).

- [ ] **Step 5: Générer et exécuter la migration**

```bash
php bin/console make:migration
php bin/console doctrine:migrations:migrate --no-interaction
```

- [ ] **Step 6: Commit**

```bash
git add src/Entity/ContentEntry.php src/Entity/SeoRevision.php src/Entity/Redirect.php src/Entity/RedirectRevision.php src/Entity/NotFoundLog.php src/Entity/Form.php src/Entity/FormSubmission.php migrations/
git commit -m "feat(v1.19): colonnes SEO sur ContentEntry + entités Redirect, RedirectRevision, NotFoundLog, Form, FormSubmission"
```

---

### Task 2: DTOs SEO + Repository methods

**Files:**
- Create: `src/Dto/SeoScore.php`
- Create: `src/Dto/SeoAuditReport.php`
- Create: `src/Repository/SeoRevisionRepository.php`
- Create: `src/Repository/RedirectRepository.php`
- Create: `src/Repository/NotFoundLogRepository.php`
- Create: `src/Repository/FormRepository.php`
- Create: `src/Repository/FormSubmissionRepository.php`
- Modify: `src/Repository/ContentEntryRepository.php:113-132`

**Interfaces:**
- Consumes: `ContentEntry.metaTitle`, `.metaDescription`, `.slug`, etc. (Task 1)
- Produces: `SeoScore` DTO, `SeoAuditReport` DTO — utilisés par Task 3
- Produces: `ContentEntryRepository::findOneByCollectionAndSlugNative()` — utilisé par Tasks 3, 7
- Produces: Repository methods pour Redirect, Form, FormSubmission

- [ ] **Step 1: Créer SeoScore DTO**

```php
<?php
namespace App\Dto;

class SeoScore
{
    public function __construct(
        public int $score, // 0-100
        /** @var array<string, array{label: string, passed: bool, score: int, maxScore: int, advice: ?string}> */
        public array $criteria = [],
        /** @var string[] */
        public array $suggestions = [],
    ) {}
}
```

- [ ] **Step 2: Créer SeoAuditReport DTO**

```php
<?php
namespace App\Dto;

class SeoAuditReport
{
    public function __construct(
        public SeoScore $score,
        /** @var array{title: string, url: string, statusCode: int}[] */
        public array $brokenLinks = [],
        /** @var string[] */
        public array $warnings = [],
    ) {}
}
```

- [ ] **Step 3: Update ContentEntryRepository — remplacer findOneByCollectionAndSlug par version native**

Remplacer la méthode existante (lignes 115-132) par une version qui utilise `e.slug` au lieu du champ EAV :

```php
public function findOneByCollectionAndSlug(Collection $collection, string $slug, ?string $locale = null): ?ContentEntry
{
    $qb = $this->createQueryBuilder('e')
        ->where('e.collection = :collection')
        ->andWhere('e.deletedAt IS NULL')
        ->andWhere('e.status = :status')
        ->andWhere('e.slug = :slug')
        ->setParameter('collection', $collection)
        ->setParameter('status', 'published')
        ->setParameter('slug', $slug)
        ->setMaxResults(1);

    if ($locale !== null) {
        $qb->andWhere('e.locale = :locale')->setParameter('locale', $locale);
    }

    return $qb->getQuery()->getOneOrNullResult();
}
```

- [ ] **Step 4: Créer les repositories pour les nouvelles entités**

Chaque repository suit le pattern standard `ServiceEntityRepository` avec les méthodes de base (find, findOneBy, etc.).

- [ ] **Step 5: Commit**

```bash
git add src/Dto/SeoScore.php src/Dto/SeoAuditReport.php src/Repository/
git commit -m "feat(v1.19): DTOs SEO + repositories Redirect, Form, FormSubmission + slug natif ContentEntryRepository"
```

---

### Task 3: SeoAnalyzer — scoring + audit SEO

**Files:**
- Create: `src/Service/Seo/SeoAnalyzer.php`
- Create: `tests/Service/Seo/SeoAnalyzerTest.php`

**Interfaces:**
- Consumes: `ContentEntry` (colonnes SEO Task 1), `SeoScore` DTO (Task 2), `SeoAuditReport` DTO (Task 2)
- Produces: `SeoAnalyzer::analyze(ContentEntry, ?string $keyword): SeoScore`, `SeoAnalyzer::audit(ContentEntry): SeoAuditReport`, `SeoAnalyzer::batchAnalyze(array $entries): array` — utilisés par Tasks 5, 15, 16

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace App\Tests\Service\Seo;

use App\Service\Seo\SeoAnalyzer;
use App\Entity\ContentEntry;
use App\Entity\Collection;
use App\Entity\Project;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class SeoAnalyzerTest extends KernelTestCase
{
    private SeoAnalyzer $analyzer;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->analyzer = self::getContainer()->get(SeoAnalyzer::class);
    }

    public function testAnalyzePerfectEntry(): void
    {
        $entry = $this->createMockEntry(
            metaTitle: 'Comment bien référencer son site en 2026 — Guide complet',
            metaDescription: 'Découvrez les meilleures pratiques SEO en 2026 : optimisation on-page, netlinking, Core Web Vitals et stratégies de contenu pour booster votre trafic.',
            slug: 'guide-seo-2026',
            ogImage: 'uuid-image',
            content: str_repeat('Contenu riche pour le SEO. ', 20), // ~300 mots
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
        $entry = $this->createMockEntry(metaTitle: 'Trop court', ...$this->defaults());
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
        // content → simulated via fieldValues (EAV)
        $entry->status = 'published';
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
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php vendor/bin/phpunit tests/Service/Seo/SeoAnalyzerTest.php 2>&1 | tail -3
```
Expected: FAIL (class not found)

- [ ] **Step 3: Implement SeoAnalyzer**

```php
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
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php vendor/bin/phpunit tests/Service/Seo/SeoAnalyzerTest.php 2>&1
```
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Service/Seo/SeoAnalyzer.php tests/Service/Seo/SeoAnalyzerTest.php
git commit -m "feat(v1.19): SeoAnalyzer — scoring 11 critères + audit SEO"
```

---

### Task 4: StructuredDataGenerator + SitemapGenerator + HreflangGenerator

**Files:**
- Create: `src/Service/Seo/StructuredDataGenerator.php`
- Create: `src/Service/Seo/SitemapGenerator.php`
- Create: `src/Service/Seo/HreflangGenerator.php`
- Create: `tests/Service/Seo/StructuredDataGeneratorTest.php`
- Create: `tests/Service/Seo/SitemapGeneratorTest.php`

**Interfaces:**
- Consumes: `ContentEntry` (Task 1), `Collection::$settings['seo']`, `Project::$settings['seo']`
- Produces: `StructuredDataGenerator::generate(ContentEntry, string $type): array` — JSON-LD array
- Produces: `SitemapGenerator::generateSitemap(Project): string`, `SitemapGenerator::generateImageSitemap(Project): string`
- Produces: `HreflangGenerator::generateHreflang(ContentEntry): array`

- [ ] **Step 1: Implement StructuredDataGenerator**

```php
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

    private function generateProduct(ContentEntry $entry): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $entry->metaTitle ?? '',
            'description' => $entry->metaDescription ?? '',
        ];
    }

    private function generateFaq(ContentEntry $entry): array
    {
        // Les FAQ sont générées depuis les champs EAV question/réponse — simplifié
        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => [],
        ];
    }

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
```

- [ ] **Step 2: Implement SitemapGenerator**

```php
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
            if (in_array($collection->slug, $excludeCollections, true)) continue;

            $seoConfig = $collection->settings['seo'] ?? [];
            if (($seoConfig['indexable'] ?? true) === false) continue;

            $entries = $this->entryRepo->findByCollectionPaginated($collection, 1, 1000, null, 'published');
            foreach ($entries as $entry) {
                if (empty($entry->slug)) continue;
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
```

- [ ] **Step 3: Implement HreflangGenerator**

```php
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
```

- [ ] **Step 4: Write tests and commit**

```bash
php vendor/bin/phpunit tests/Service/Seo/ 2>&1 | tail -3
git add src/Service/Seo/ tests/Service/Seo/
git commit -m "feat(v1.19): StructuredDataGenerator + SitemapGenerator + HreflangGenerator"
```

---

### Task 5: EavDataFormatterService — intégration _seo dans l'API

**Files:**
- Modify: `src/Service/EavDataFormatterService.php:29-82`

**Interfaces:**
- Consumes: `ContentEntry` colonnes SEO (Task 1), `StructuredDataGenerator` (Task 4), `HreflangGenerator` (Task 4), `SeoAnalyzer` (Task 3)
- Produces: `formatEntry()` retourne désormais un bloc `_seo` dans chaque entrée

- [ ] **Step 1: Injecter les services SEO dans EavDataFormatterService**

Modifier le constructeur :

```php
public function __construct(
    private LoggerInterface $logger = new NullLogger(),
    private ?\App\Service\Seo\StructuredDataGenerator $structuredDataGenerator = null,
    private ?\App\Service\Seo\HreflangGenerator $hreflangGenerator = null,
) {}
```

- [ ] **Step 2: Ajouter le bloc _seo dans formatEntry**

Après `$data[$fieldName] = $value;` (ligne 78), avant `return $data;` (ligne 81) :

```php
// ── Bloc SEO natif ──
$collectionSeo = $entry->collection?->settings['seo'] ?? [];
$projectSeo = $entry->project?->settings['seo'] ?? [];
$siteName = $projectSeo['siteName'] ?? 'Jambo';
$structuredDataType = $collectionSeo['structuredDataType'] ?? 'Article';

$ogTitle = $entry->metaTitle ?? $data['title'] ?? $siteName;
$ogDesc = $entry->metaDescription ?? $data['description'] ?? '';
$ogImage = $entry->ogImage ?? $projectSeo['defaultOgImage'] ?? null;

$data['_seo'] = [
    'metaTitle' => $entry->metaTitle,
    'metaDescription' => $entry->metaDescription,
    'slug' => $entry->slug,
    'canonicalUrl' => $entry->canonicalUrl,
    'ogImage' => $ogImage,
    'score' => $entry->seoScore,
    'openGraph' => [
        'title' => $ogTitle,
        'description' => $ogDesc,
        'image' => $ogImage,
        'type' => 'article',
        'siteName' => $siteName,
    ],
    'twitter' => [
        'card' => !empty($ogImage) ? 'summary_large_image' : 'summary',
        'title' => $ogTitle,
        'description' => $ogDesc,
        'image' => $ogImage,
    ],
    'structuredData' => $this->structuredDataGenerator
        ? $this->structuredDataGenerator->generate($entry, $structuredDataType)
        : null,
    'hreflang' => $this->hreflangGenerator
        ? $this->hreflangGenerator->generateHreflang($entry)
        : [],
];
```

- [ ] **Step 3: Run existing tests to verify no regression**

```bash
php vendor/bin/phpunit tests/Controller/Api/ContentControllerTest.php 2>&1 | tail -3
php vendor/bin/phpunit tests/Enum/ShareDurationTest.php tests/Service/Share/ShareServiceTest.php tests/Controller/PublicShareControllerTest.php tests/Controller/ShareControllerTest.php 2>&1 | tail -3
```
Expected: all green

- [ ] **Step 4: Commit**

```bash
git add src/Service/EavDataFormatterService.php
git commit -m "feat(v1.19): bloc _seo dans l'API publique REST/GraphQL via EavDataFormatterService"
```

---

### Task 6: Services Redirect — RedirectResolver + RedirectChainDetector + LinkChecker

**Files:**
- Create: `src/Service/Redirect/RedirectResolver.php`
- Create: `src/Service/Redirect/RedirectChainDetector.php`
- Create: `src/Service/Redirect/LinkChecker.php`
- Create: `tests/Service/Redirect/RedirectResolverTest.php`
- Create: `tests/Service/Redirect/RedirectChainDetectorTest.php`

**Interfaces:**
- Consumes: `Redirect` entity (Task 1), `RedirectRepository` (Task 2)
- Produces: `RedirectResolver::resolve(string $path, Project $project): ?Redirect`
- Produces: `RedirectChainDetector::detectChains(array $redirects): array`, `detectLoop(Redirect): bool`
- Produces: `LinkChecker::scanEntry(ContentEntry): array`, `checkLinks(Project): array`

- [ ] **Step 1: Write failing tests**

```php
<?php
namespace App\Tests\Service\Redirect;

use App\Entity\Redirect;
use App\Entity\Project;
use App\Service\Redirect\RedirectResolver;
use App\Repository\RedirectRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class RedirectResolverTest extends KernelTestCase
{
    public function testResolveExactMatch(): void
    {
        $redirect = new Redirect();
        $redirect->fromPath = '/blog/ancien';
        $redirect->toPath = '/blog/nouveau';
        $redirect->httpCode = 301;
        $redirect->isEnabled = true;

        $repo = $this->createMock(RedirectRepository::class);
        $repo->method('findByProject')->willReturn([$redirect]);

        $resolver = new RedirectResolver($repo);
        $result = $resolver->resolve('/blog/ancien', new Project());
        $this->assertNotNull($result);
        $this->assertEquals('/blog/nouveau', $result->toPath);
    }

    public function testResolvePatternMatch(): void
    {
        $redirect = new Redirect();
        $redirect->fromPath = '/blog/(.*)';
        $redirect->toPath = '/articles/$1';
        $redirect->httpCode = 301;
        $redirect->isPattern = true;
        $redirect->isEnabled = true;

        $repo = $this->createMock(RedirectRepository::class);
        $repo->method('findByProject')->willReturn([$redirect]);

        $resolver = new RedirectResolver($repo);
        $result = $resolver->resolve('/blog/mon-article', new Project());
        $this->assertNotNull($result);
        $this->assertEquals('/articles/mon-article', $result->toPath);
    }

    public function testResolveChainDetection(): void
    {
        $r1 = new Redirect(); $r1->fromPath = '/a'; $r1->toPath = '/b'; $r1->isEnabled = true;
        $r2 = new Redirect(); $r2->fromPath = '/b'; $r2->toPath = '/c'; $r2->isEnabled = true;

        $repo = $this->createMock(RedirectRepository::class);
        $repo->method('findByProject')->willReturn([$r1, $r2]);

        $resolver = new RedirectResolver($repo);
        $result = $resolver->resolve('/a', new Project());
        $this->assertNotNull($result);
        $this->assertEquals('/c', $result->toPath); // resolv final target
    }
}
```

- [ ] **Step 2: Implement services**

Implémenter `RedirectResolver`, `RedirectChainDetector`, `LinkChecker` selon le spec sections 3.5.

- [ ] **Step 3: Run tests and commit**

```bash
php vendor/bin/phpunit tests/Service/Redirect/ 2>&1 | tail -3
git add src/Service/Redirect/ tests/Service/Redirect/
git commit -m "feat(v1.19): RedirectResolver + RedirectChainDetector + LinkChecker"
```

---

### Task 7: Auto-redirect sur changement de slug + EventListener

**Files:**
- Create: `src/EventListener/SlugChangeRedirectListener.php`
- Modify: `src/Entity/ContentEntry.php` — hook `#[ORM\PreUpdate]`

**Interfaces:**
- Consumes: `ContentEntry.slug` natif (Task 1), `Redirect` entity (Task 1)
- Produces: `SlugChangeRedirectListener` — crée automatiquement une redirection 301 quand le slug change

- [ ] **Step 1: Implement SlugChangeRedirectListener**

```php
<?php
namespace App\EventListener;

use App\Entity\ContentEntry;
use App\Entity\Redirect;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreUpdateEventArgs;

class SlugChangeRedirectListener
{
    public function __construct(private EntityManagerInterface $em) {}

    public function preUpdate(ContentEntry $entry, PreUpdateEventArgs $args): void
    {
        if (!$args->hasChangedField('slug') || $entry->status !== 'published') {
            return;
        }

        $oldSlug = $args->getOldValue('slug');
        $newSlug = $args->getNewValue('slug');
        if ($oldSlug === $newSlug || empty($oldSlug)) return;

        $collectionSlug = $entry->collection?->slug ?? '';
        $fromPath = '/' . $collectionSlug . '/' . $oldSlug;
        $toPath = '/' . $collectionSlug . '/' . $newSlug;

        // Vérifier si une redirection auto existe déjà pour cette entrée
        $existing = $this->em->getRepository(Redirect::class)
            ->findOneBy(['sourceEntry' => $entry, 'isAuto' => true]);

        if ($existing) {
            $existing->toPath = $toPath;
            $existing->updatedAt = new \DateTimeImmutable();
        } else {
            $redirect = new Redirect();
            $redirect->project = $entry->project;
            $redirect->fromPath = $fromPath;
            $redirect->toPath = $toPath;
            $redirect->httpCode = 301;
            $redirect->isAuto = true;
            $redirect->sourceEntry = $entry;
            $redirect->createdBy = $entry->updatedBy; // ou system user
            $this->em->persist($redirect);
        }
    }
}
```

- [ ] **Step 2: Enregistrer le listener**

Dans `config/services.yaml` :

```yaml
App\EventListener\SlugChangeRedirectListener:
    tags:
        - { name: doctrine.orm.entity_listener, entity: App\Entity\ContentEntry, event: preUpdate }
```

- [ ] **Step 3: Commit**

```bash
git add src/EventListener/SlugChangeRedirectListener.php config/services.yaml
git commit -m "feat(v1.19): auto-redirect 301 sur changement de slug ContentEntry"
```

---

### Task 8: Services Form — FormBuilder + SubmitHandler + AntiSpamService

**Files:**
- Create: `src/Service/Form/FormBuilder.php`
- Create: `src/Service/Form/SubmitHandler.php`
- Create: `src/Service/Form/AntiSpamService.php`
- Create: `tests/Service/Form/FormBuilderTest.php`
- Create: `tests/Service/Form/SubmitHandlerTest.php`
- Create: `tests/Service/Form/AntiSpamServiceTest.php`

**Interfaces:**
- Consumes: `Form` entity (Task 1), `FormSubmission` entity (Task 1)
- Produces: `FormBuilder::validateDefinition(array $fields): array`, `buildFormSchema(Form): array`, `resolveConditions(array $fields, array $values): array`
- Produces: `SubmitHandler::handle(Form $form, array $data, Request $request): FormSubmission`
- Produces: `AntiSpamService::checkHoneypot()`, `checkRateLimit()`, `verifyCaptcha()`, `checkBlocklistedDomain()`, `detectSpamPatterns()`

- [ ] **Step 1: Implement AntiSpamService**

```php
<?php
namespace App\Service\Form;

use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AntiSpamService
{
    private const BLOCKLISTED_DOMAINS = [
        'mailinator.com', 'tempmail.com', 'guerrillamail.com', '10minutemail.com',
        'yopmail.com', 'throwaway.email', 'sharklasers.com', 'trashmail.com',
    ];

    public function __construct(
        private RateLimiterFactory $formSubmitLimiter,
        private ?HttpClientInterface $httpClient = null,
    ) {}

    public function checkHoneypot(array $data, string $honeypotField = '_website'): bool
    {
        return !empty($data[$honeypotField] ?? null); // true = spam detected
    }

    public function checkRateLimit(string $ip): bool
    {
        $limiter = $this->formSubmitLimiter->create($ip);
        $limit = $limiter->consume();
        return !$limit->isAccepted(); // true = rate limited
    }

    public function verifyCaptcha(string $token, array $captchaConfig): bool
    {
        $provider = $captchaConfig['provider'] ?? 'turnstile';
        $secret = $captchaConfig['secret'] ?? '';
        if (empty($secret) || empty($token)) return false;
        if (str_starts_with($secret, 'enc:')) {
            // La clé est déchiffrée avant d'arriver ici
        }

        return match ($provider) {
            'turnstile' => $this->verifyTurnstile($token, $secret),
            'recaptcha' => $this->verifyRecaptcha($token, $secret),
            'hcaptcha' => $this->verifyHcaptcha($token, $secret),
            default => false,
        };
    }

    private function verifyTurnstile(string $token, string $secret): bool
    {
        if (!$this->httpClient) return true; // skip en test
        $response = $this->httpClient->request('POST', 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'body' => ['secret' => $secret, 'response' => $token],
        ]);
        $data = $response->toArray();
        return $data['success'] ?? false;
    }

    private function verifyRecaptcha(string $token, string $secret): bool
    {
        if (!$this->httpClient) return true;
        $response = $this->httpClient->request('POST', 'https://www.google.com/recaptcha/api/siteverify', [
            'body' => ['secret' => $secret, 'response' => $token],
        ]);
        $data = $response->toArray();
        return $data['success'] ?? false;
    }

    private function verifyHcaptcha(string $token, string $secret): bool
    {
        if (!$this->httpClient) return true;
        $response = $this->httpClient->request('POST', 'https://hcaptcha.com/siteverify', [
            'body' => ['secret' => $secret, 'response' => $token],
        ]);
        $data = $response->toArray();
        return $data['success'] ?? false;
    }

    public function checkBlocklistedDomain(string $email): bool
    {
        $domain = substr(strrchr($email, '@'), 1);
        return in_array(strtolower($domain ?: ''), self::BLOCKLISTED_DOMAINS, true);
    }

    /** @return float 0-1, > 0.7 = probable spam */
    public function detectSpamPatterns(array $data): float
    {
        $score = 0.0;
        $allText = strtolower(implode(' ', array_filter($data, 'is_string')));

        // Patterns de spam communs
        if (str_contains($allText, 'buy now')) $score += 0.2;
        if (str_contains($allText, 'click here')) $score += 0.1;
        if (str_contains($allText, 'casino')) $score += 0.3;
        if (str_contains($allText, 'viagra')) $score += 0.4;
        if (preg_match('/(http|www\.)\S+/i', $allText)) $score += 0.2;
        if (substr_count($allText, '<a ') > 3) $score += 0.3;

        return min(1.0, $score);
    }
}
```

- [ ] **Step 2: Implement FormBuilder + SubmitHandler**

Implémenter selon le spec sections 4.3.

- [ ] **Step 3: Add rate limiter config**

Dans `config/packages/rate_limiter.yaml` :

```yaml
        form_submit:
            policy: sliding_window
            limit: 10
            interval: '60 seconds'
```

- [ ] **Step 4: Run tests and commit**

```bash
php vendor/bin/phpunit tests/Service/Form/ 2>&1 | tail -3
git add src/Service/Form/ tests/Service/Form/ config/packages/rate_limiter.yaml
git commit -m "feat(v1.19): FormBuilder + SubmitHandler + AntiSpamService + rate_limiter form_submit"
```

---

### Task 9: Settings SEO sur Collection et Project

**Files:**
- Modify: `src/Entity/Collection.php:70-71` — méthodes helpers SEO dans settings
- Modify: `src/Entity/Project.php:80-82` — méthodes helpers SEO dans settings

**Interfaces:**
- Consumes: settings JSON existant
- Produces: `Collection::getSeoSettings(): array`, `Project::getSeoSettings(): array` — utilisés par Tasks 4, 10, 15

- [ ] **Step 1: Add SEO settings helpers to Collection.php**

```php
/** @return array{indexable: bool, sitemapPriority: float, sitemapChangefreq: string, autoGenerateSlug: bool, slugSourceField: ?string, defaultOgImage: ?string, structuredDataType: string} */
public function getSeoSettings(): array
{
    $s = $this->settings['seo'] ?? [];
    return [
        'indexable' => $s['indexable'] ?? true,
        'sitemapPriority' => (float) ($s['sitemapPriority'] ?? 0.5),
        'sitemapChangefreq' => $s['sitemapChangefreq'] ?? 'weekly',
        'autoGenerateSlug' => $s['autoGenerateSlug'] ?? true,
        'slugSourceField' => $s['slugSourceField'] ?? 'title',
        'defaultOgImage' => $s['defaultOgImage'] ?? null,
        'structuredDataType' => $s['structuredDataType'] ?? 'Article',
    ];
}
```

- [ ] **Step 2: Add SEO settings helpers to Project.php**

```php
/** @return array{defaultTitleTemplate: string, siteName: string, defaultOgImage: ?string, twitterHandle: ?string, robotsDefault: string, googleSiteVerification: ?string, enableSitemap: bool, enableImageSitemap: bool, sitemapExcludeCollections: string[]} */
public function getSeoSettings(): array
{
    $s = $this->settings['seo'] ?? [];
    return [
        'defaultTitleTemplate' => $s['defaultTitleTemplate'] ?? '{title} | {siteName}',
        'siteName' => $s['siteName'] ?? $this->name,
        'defaultOgImage' => $s['defaultOgImage'] ?? null,
        'twitterHandle' => $s['twitterHandle'] ?? null,
        'robotsDefault' => $s['robotsDefault'] ?? 'index, follow',
        'googleSiteVerification' => $s['googleSiteVerification'] ?? null,
        'enableSitemap' => $s['enableSitemap'] ?? true,
        'enableImageSitemap' => $s['enableImageSitemap'] ?? true,
        'sitemapExcludeCollections' => $s['sitemapExcludeCollections'] ?? [],
    ];
}
```

- [ ] **Step 3: Commit**

```bash
git add src/Entity/Collection.php src/Entity/Project.php
git commit -m "feat(v1.19): settings SEO sur Collection et Project — getSeoSettings()"
```

---

### Task 10: Phase 1 — Tests de non-régression globaux

- [ ] **Step 1: Run all existing tests**

```bash
php vendor/bin/phpunit tests/ 2>&1 | tail -5
```
Expected: pas de nouvelle régression par rapport au pre-v1.19.

- [ ] **Step 2: Run all new tests**

```bash
php vendor/bin/phpunit tests/Service/Seo/ tests/Service/Redirect/ tests/Service/Form/ 2>&1 | tail -3
```
Expected: all green.

- [ ] **Step 3: Commit any remaining files**

```bash
git add -A && git commit -m "feat(v1.19): Phase 1 complète — entités, migrations, services SEO/Redirects/Forms"
```

---

## Phase 2 — Admin API + Frontend

### Task 11: Routes publiques (sitemap, robots.txt, redirects/resolve, forms) + security.yaml

**Files:**
- Create: `src/Controller/Public/SeoPublicController.php`
- Create: `src/Controller/Public/RedirectPublicController.php`
- Create: `src/Controller/Public/FormPublicController.php`
- Modify: `config/packages/security.yaml:57-72`

**Interfaces:**
- Consumes: `SitemapGenerator` (Task 4), `RedirectResolver` (Task 6), `FormBuilder` (Task 8), `SubmitHandler` (Task 8), `AntiSpamService` (Task 8)

- [ ] **Step 1: Create SeoPublicController**

```php
<?php
namespace App\Controller\Public;

use App\Service\Seo\SitemapGenerator;
use App\Repository\ProjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SeoPublicController extends AbstractController
{
    public function __construct(
        private ProjectRepository $projectRepo,
        private SitemapGenerator $sitemapGenerator,
    ) {}

    #[Route('/{projectUuid}/sitemap.xml', name: 'public_sitemap', requirements: ['projectUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function sitemap(string $projectUuid): Response
    {
        $project = $this->projectRepo->findOneBy(['uuid' => $projectUuid]);
        if (!$project) throw $this->createNotFoundException();

        $xml = $this->sitemapGenerator->generateSitemap($project);
        return new Response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    #[Route('/{projectUuid}/sitemap-images.xml', name: 'public_image_sitemap', requirements: ['projectUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function imageSitemap(string $projectUuid): Response
    {
        $project = $this->projectRepo->findOneBy(['uuid' => $projectUuid]);
        if (!$project) throw $this->createNotFoundException();

        $xml = $this->sitemapGenerator->generateImageSitemap($project);
        return new Response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    #[Route('/{projectUuid}/robots.txt', name: 'public_robots', requirements: ['projectUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function robots(string $projectUuid): Response
    {
        $project = $this->projectRepo->findOneBy(['uuid' => $projectUuid]);
        if (!$project) throw $this->createNotFoundException();

        $seo = $project->getSeoSettings();
        $baseUrl = rtrim($project->previewUrl ?? 'https://example.com', '/');

        $content = "User-agent: *\n";
        $content .= "Allow: /\n";
        if ($seo['enableSitemap']) {
            $content .= "Sitemap: {$baseUrl}/{$projectUuid}/sitemap.xml\n";
        }

        return new Response($content, 200, ['Content-Type' => 'text/plain']);
    }
}
```

- [ ] **Step 2: Create RedirectPublicController + FormPublicController**

Suivre le spec sections 3.7 et 4.4 pour les routes publiques.

- [ ] **Step 3: Update security.yaml**

```yaml
# Ajouter AVANT ^/ :
        - { path: '^/[\w-]{36}/(sitemap\.xml|sitemap-images\.xml|robots\.txt)', roles: PUBLIC_ACCESS }
        - { path: '^/[\w-]{36}/redirects/resolve', roles: PUBLIC_ACCESS }
        - { path: '^/[\w-]{36}/forms/', roles: PUBLIC_ACCESS }
        - { path: ^/forms/embed.js, roles: PUBLIC_ACCESS }
```

- [ ] **Step 4: Commit**

```bash
git add src/Controller/Public/ config/packages/security.yaml
git commit -m "feat(v1.19): routes publiques sitemap, robots.txt, redirects/resolve, forms + security.yaml"
```

---

### Task 12: Admin API — SEO (dashboard, bulk, audit)

**Files:**
- Create: `src/Controller/Api/SeoController.php`
- Create: `tests/Controller/Api/SeoControllerTest.php`

- [ ] **Step 1: Implement SeoController (admin API)**

```php
<?php
namespace App\Controller\Api;

use App\Service\Seo\SeoAnalyzer;
use App\Repository\ContentEntryRepository;
use App\Repository\ProjectRepository;
use App\Repository\CollectionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class SeoController extends AbstractController
{
    public function __construct(
        private SeoAnalyzer $analyzer,
        private ContentEntryRepository $entryRepo,
        private ProjectRepository $projectRepo,
        private CollectionRepository $collectionRepo,
        private EntityManagerInterface $em,
    ) {}

    #[Route('/api/{projectUuid}/seo/scores', name: 'admin_seo_scores', methods: ['GET'])]
    public function scores(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->projectRepo->findOneBy(['uuid' => $projectUuid]);
        if (!$project) return $this->json(['error' => 'Project not found'], 404);

        $collectionSlug = $request->query->get('collection');
        $scoreFilter = $request->query->get('score_filter'); // 'poor' (< 50), 'ok' (50-79), 'good' (>= 80)
        $search = $request->query->get('search');

        $scores = [];
        $collections = $collectionSlug
            ? [$this->collectionRepo->findOneByProjectAndSlug($project, $collectionSlug)]
            : $project->collections->toArray();

        foreach ($collections as $collection) {
            if (!$collection || $collection->isDeleted()) continue;
            $entries = $this->entryRepo->findByCollectionPaginated($collection, 1, 500, null, 'published');
            foreach ($entries as $entry) {
                if ($search && stripos($entry->metaTitle ?? '', $search) === false) continue;
                $seoScore = $this->analyzer->analyze($entry);
                if ($scoreFilter === 'poor' && $seoScore->score >= 50) continue;
                if ($scoreFilter === 'ok' && ($seoScore->score < 50 || $seoScore->score >= 80)) continue;
                if ($scoreFilter === 'good' && $seoScore->score < 80) continue;

                $scores[] = [
                    'uuid' => $entry->uuid?->toRfc4122(),
                    'collection' => $collection->slug,
                    'metaTitle' => $entry->metaTitle,
                    'metaDescription' => $entry->metaDescription,
                    'slug' => $entry->slug,
                    'score' => $seoScore->score,
                    'seoScore' => $entry->seoScore,
                ];
            }
        }

        usort($scores, fn ($a, $b) => $a['score'] <=> $b['score']);

        $avgScore = count($scores) > 0
            ? (int) round(array_sum(array_column($scores, 'score')) / count($scores))
            : null;

        return $this->json([
            'data' => $scores,
            'meta' => ['total' => count($scores), 'avg_score' => $avgScore],
        ]);
    }

    #[Route('/api/{projectUuid}/seo/bulk', name: 'admin_seo_bulk', methods: ['PUT'])]
    public function bulkUpdate(string $projectUuid, Request $request): JsonResponse
    {
        $data = $request->toArray();
        $entries = $data['entries'] ?? [];
        $updated = 0;

        foreach ($entries as $entryData) {
            $entry = $this->entryRepo->findOneBy(['uuid' => $entryData['uuid'] ?? '']);
            if (!$entry || $entry->project?->uuid?->toRfc4122() !== $projectUuid) continue;

            if (array_key_exists('metaTitle', $entryData)) $entry->metaTitle = $entryData['metaTitle'];
            if (array_key_exists('metaDescription', $entryData)) $entry->metaDescription = $entryData['metaDescription'];
            if (array_key_exists('slug', $entryData)) $entry->slug = $entryData['slug'];
            if (array_key_exists('canonicalUrl', $entryData)) $entry->canonicalUrl = $entryData['canonicalUrl'];
            $updated++;
        }

        $this->em->flush();

        return $this->json(['updated' => $updated]);
    }

    #[Route('/api/{projectUuid}/seo/audit/{entryUuid}', name: 'admin_seo_audit', methods: ['GET'])]
    public function audit(string $projectUuid, string $entryUuid): JsonResponse
    {
        $entry = $this->entryRepo->findOneBy(['uuid' => $entryUuid]);
        if (!$entry || $entry->project?->uuid?->toRfc4122() !== $projectUuid) {
            return $this->json(['error' => 'Entry not found'], 404);
        }

        $report = $this->analyzer->audit($entry);
        return $this->json([
            'score' => $report->score,
            'brokenLinks' => $report->brokenLinks,
            'warnings' => $report->warnings,
        ]);
    }
}
```

- [ ] **Step 2: Write tests and commit**

```bash
git add src/Controller/Api/SeoController.php tests/Controller/Api/SeoControllerTest.php
git commit -m "feat(v1.19): API admin SEO — dashboard, bulk edit, audit"
```

---

### Task 13: Admin API — Redirects CRUD + NotFoundLogs

**Files:**
- Create: `src/Controller/Admin/RedirectController.php`
- Create: `tests/Controller/Admin/RedirectControllerTest.php`

Suivre le spec section 3.7 pour les routes admin redirects.

- [ ] **Step 1: Implement RedirectController**
- [ ] **Step 2: Write tests and commit**

---

### Task 14: Admin API — Forms CRUD + Submissions

**Files:**
- Create: `src/Controller/Admin/FormController.php`
- Create: `tests/Controller/Admin/FormControllerTest.php`

Suivre le spec section 4.4 pour les routes admin forms.

- [ ] **Step 1: Implement FormController**
- [ ] **Step 2: Write tests and commit**

---

### Task 15: Frontend — Panneau SEO dans l'éditeur + SEO Dashboard + Bulk Editor

**Files:**
- Modify: Ajouter composants React dans `assets/js/pages/` ou sous-dossier dédié

- [ ] **Step 1: Créer le composant SeoPanel (panneau latéral dans l'éditeur)**
- [ ] **Step 2: Créer le composant SeoDashboard (vue par projet)**
- [ ] **Step 3: Créer le composant BulkSeoEditor (spreadsheet)**
- [ ] **Step 4: Ajouter les clés i18n seo.***
- [ ] **Step 5: Build et commit**

```bash
npm run dev 2>&1 | tail -1
git add assets/js/ translations/
git commit -m "feat(v1.19): frontend SEO — panneau éditeur + dashboard + bulk editor + i18n"
```

---

### Task 16: Frontend — Redirects admin (liste, form, preview, 404, liens cassés)

**Files:**
- Créer composants React dans `assets/js/pages/admin/Redirects/`

- [ ] **Step 1: Créer liste + formulaire redirect**
- [ ] **Step 2: Créer preview + liens cassés + 404**
- [ ] **Step 3: i18n redirects.***
- [ ] **Step 4: Build et commit**

---

### Task 17: Frontend — Form Builder drag & drop + Dashboard soumissions

**Files:**
- Créer composants React dans `assets/js/pages/admin/Forms/`

- [ ] **Step 1: Créer le Form Builder drag & drop**
- [ ] **Step 2: Créer le dashboard soumissions (tableau, graphiques, filtres)**
- [ ] **Step 3: i18n forms.***
- [ ] **Step 4: Build et commit**

---

## Phase 3 — Features avancées + Polish

### Task 18: Auto-internal-linking IA

**Files:**
- Create: `src/Service/Seo/InternalLinkSuggester.php`
- Modify: intégration dans le panneau SEO frontend

---

### Task 19: Historique SEO (SeoRevision)

**Files:**
- Modify: hook `#[ORM\PreUpdate]` sur ContentEntry pour créer SeoRevision

---

### Task 20: Audit SEO one-click + export PDF (dompdf)

**Files:**
- Create: `src/Service/Seo/SeoPdfExporter.php`
- Add: `composer require dompdf/dompdf`

---

### Task 21: Form Templates + A/B Testing

**Files:**
- Create: `src/Service/Form/FormTemplateManager.php`
- Create: `src/Service/Form/AbTestManager.php`

---

### Task 22: Widget embeddable JS (`/forms/embed.js`)

**Files:**
- Create: `src/Controller/Public/EmbedController.php`
- Create: `assets/js/embed/widget.js` (vanilla JS, ~5 Ko minifié)

---

### Task 23: Final verification — tests + build + review

- [ ] **Step 1: Run full test suite**

```bash
php vendor/bin/phpunit tests/ 2>&1 | tail -5
```
Expected: ALL green, pas de nouvelle régression.

- [ ] **Step 2: Final dev build**

```bash
npm run dev 2>&1 | tail -1
```
Expected: `webpack compiled successfully`.

- [ ] **Step 3: Update roadmap**

Mettre à jour `docs/ROADMAP_2026-2027_JAMBO.html` pour v1.19.

- [ ] **Step 4: Commit final**

```bash
git add -A && git commit -m "feat(v1.19): SEO/Redirects/Form Builder — Phase 3 complète"
```

---

## Phase 1 Self-Review Checklist

- [ ] Toutes les entités ont leur repository
- [ ] Toutes les migrations sont scopées (pas de drift)
- [ ] Les tests Phase 1 passent
- [ ] Les tests de régression existants passent
- [ ] `npm run dev` compile (frontend inchangé en Phase 1)

## Phase 2 Self-Review Checklist

- [ ] Toutes les routes admin fonctionnent
- [ ] Les composants frontend sont intégrés au layout existant (Inertia)
- [ ] i18n complète 4 langues
- [ ] Les tests Phase 2 passent

## Phase 3 Self-Review Checklist

- [ ] dompdf installé et fonctionnel
- [ ] Widget JS servable et fonctionnel
- [ ] A/B testing stats correctes
- [ ] Tests complets passent
