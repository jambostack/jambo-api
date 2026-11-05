<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\ExportOptions;
use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Media;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\Redirect;
use App\Enum\ProjectMemberStatus;
use App\Service\ExportImport\Export\MediaExportHandler;
use App\Service\ExportImport\Export\SettingsExportHandler;
use App\Service\ExportImport\Import\SettingsImportHandler;
use App\Service\ExportImport\ProjectExporter;
use App\Service\MercurePublisher;
use App\Service\Redirect\LinkChecker;
use App\Service\Redirect\RedirectResolver;
use App\Service\Seo\HreflangGenerator;
use App\Service\Seo\SeoAnalyzer;
use App\Service\Seo\SeoPdfExporter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

class Cycle67HardeningTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/cycle67_test_' . bin2hex(random_bytes(6));
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0700, true);
        }
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    // ─── 1. MediaExportHandler Hardening ─────────────────────────────────────

    public function testMediaExportRejectsPathTraversalAndNullBytes(): void
    {
        $projectUuid = Uuid::v4();
        $project = new Project();
        $project->uuid = $projectUuid;

        // Create mock upload dir
        $uploadDir = $this->tempDir . '/public/uploads/media/' . $projectUuid->toString();
        mkdir($uploadDir, 0700, true);
        file_put_contents($uploadDir . '/valid.jpg', 'fake-image-content');

        // Create a sensitive file outside the upload dir
        $sensitiveDir = $this->tempDir . '/var';
        mkdir($sensitiveDir, 0700, true);
        file_put_contents($sensitiveDir . '/secret.env', 'DB_PASSWORD=secret');

        $m1 = new Media();
        $m1->uuid = Uuid::v4();
        $m1->fileName = 'valid.jpg';
        $m1->originalName = 'valid.jpg';

        $m2 = new Media();
        $m2->uuid = Uuid::v4();
        $m2->fileName = '../../var/secret.env'; // Traversal attack
        $m2->originalName = 'secret.env';

        $m3 = new Media();
        $m3->uuid = Uuid::v4();
        $m3->fileName = "evil.jpg\0.php"; // Null byte injection
        $m3->originalName = 'evil.jpg';

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findBy')->willReturn([$m1, $m2, $m3]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $handler = new MediaExportHandler($em, $this->tempDir);
        $exportDestDir = $this->tempDir . '/export_dest';
        mkdir($exportDestDir, 0700, true);

        $result = $handler->export($project, $exportDestDir);

        // Only valid.jpg should have been copied to media export folder
        $this->assertFileExists($exportDestDir . '/media/valid.jpg');
        $this->assertFileDoesNotExist($exportDestDir . '/media/secret.env');
        $this->assertFileDoesNotExist($exportDestDir . '/media/evil.jpg');
        $this->assertFileDoesNotExist($exportDestDir . '/media/evil.jpg.php');
        $this->assertContains('media/valid.jpg', $result['files']);
        $this->assertNotContains('media/secret.env', $result['files']);
    }

    public function testMediaExportRejectsSymlinkEscapes(): void
    {
        $projectUuid = Uuid::v4();
        $project = new Project();
        $project->uuid = $projectUuid;

        $uploadDir = $this->tempDir . '/public/uploads/media/' . $projectUuid->toString();
        mkdir($uploadDir, 0700, true);

        $targetFile = $this->tempDir . '/external_secret.txt';
        file_put_contents($targetFile, 'SUPER_SECRET_TOKEN');

        $symlinkPath = $uploadDir . '/symlink_secret.txt';
        @symlink($targetFile, $symlinkPath);

        $m = new Media();
        $m->uuid = Uuid::v4();
        $m->fileName = 'symlink_secret.txt';
        $m->originalName = 'symlink_secret.txt';

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findBy')->willReturn([$m]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $handler = new MediaExportHandler($em, $this->tempDir);
        $exportDestDir = $this->tempDir . '/export_dest_symlink';
        mkdir($exportDestDir, 0700, true);

        $handler->export($project, $exportDestDir);

        $this->assertFileDoesNotExist($exportDestDir . '/media/symlink_secret.txt');
    }

    public function testMediaExportEnforcesMaxEntitiesLimit(): void
    {
        $project = new Project();
        $project->uuid = Uuid::v4();

        $entities = [];
        for ($i = 0; $i < 1001; $i++) {
            $m = new Media();
            $m->uuid = Uuid::v4();
            $entities[] = $m;
        }

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findBy')->willReturn($entities);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $handler = new MediaExportHandler($em, $this->tempDir);

        $this->expectException(\RuntimeException::class);
        $handler->export($project, $this->tempDir);
    }

    // ─── 2. SettingsExportHandler & SettingsImportHandler ────────────────────

    public function testSettingsExportOmitsInternalDatabaseIds(): void
    {
        $project = new Project();
        $project->defaultLocale = 'en';
        $project->locales = ['en', 'fr'];
        $project->publicApi = true;

        $member = new ProjectMember();
        $member->email = 'admin@jambo.test';
        $member->status = ProjectMemberStatus::Active;

        $project->projectMembers->add($member);

        $handler = new SettingsExportHandler();
        $dest = $this->tempDir . '/settings_exp';
        mkdir($dest, 0700, true);

        $handler->export($project, $dest);

        $json = json_decode((string) file_get_contents($dest . '/settings.json'), true);
        $this->assertArrayNotHasKey('id', $json['members'][0]);
        $this->assertArrayNotHasKey('role', $json['members'][0]);
        $this->assertArrayNotHasKey('user', $json['members'][0]);
        $this->assertSame('admin@jambo.test', $json['members'][0]['email']);
    }

    public function testSettingsImportValidatesAndSanitizesLocales(): void
    {
        $project = new Project();
        $project->defaultLocale = 'en';
        $project->locales = ['en'];

        $dest = $this->tempDir . '/settings_imp';
        mkdir($dest, 0700, true);

        $payload = [
            'default_locale' => 'FR-FR',
            'locales'        => ['FR-FR', 'en', 'invalid/path', str_repeat('a', 50), 'es'],
            'public_api'     => 1,
        ];
        file_put_contents($dest . '/settings.json', json_encode($payload));

        $handler = new SettingsImportHandler();
        $uuidMap = [];
        $options = new \App\Dto\ImportOptions();
        $handler->import($project, $dest, $options, $uuidMap);

        $this->assertSame('fr-fr', $project->defaultLocale);
        $this->assertContains('fr-fr', $project->locales);
        $this->assertContains('en', $project->locales);
        $this->assertContains('es', $project->locales);
        $this->assertNotContains('invalid/path', $project->locales);
        $this->assertTrue($project->publicApi);
    }

    // ─── 3. MercurePublisher Hardening ───────────────────────────────────────

    public function testMercurePublisherRotatesJsonlOnOverflow(): void
    {
        $publisher = new MercurePublisher($this->tempDir, null);
        $projectUuid = 'bafb2021-079e-44b0-8fe9-35af563dc2b2';

        $realtimeDir = $this->tempDir . '/var/realtime';
        mkdir($realtimeDir, 0700, true);
        $jsonlFile = $realtimeDir . '/' . $projectUuid . '.jsonl';

        // Pre-fill file with > 5MB of dummy lines (> 600 lines)
        $fh = fopen($jsonlFile, 'wb');
        $dummyLine = str_repeat('A', 9000);
        for ($i = 0; $i < 600; $i++) {
            fwrite($fh, json_encode(['line' => $i, 'pad' => $dummyLine]) . "\n");
        }
        fclose($fh);

        $this->assertGreaterThan(5000000, filesize($jsonlFile));

        // Trigger a write
        $publisher->contentChanged($projectUuid, 'created', ['uuid' => 'e-100'], 'New Title');

        // File should have been rotated/truncated to latest 250 lines + new line
        $this->assertLessThan(3000000, filesize($jsonlFile));
        $content = (string) file_get_contents($jsonlFile);
        $this->assertStringContainsString('New Title', $content);
    }

    public function testMercurePublisherLocalizedFallbackTitles(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(function ($id) {
            return match ($id) {
                'realtime.untitled' => 'Titre Inconnu',
                'realtime.file' => 'Fichier Média',
                default => $id,
            };
        });

        $publisher = new MercurePublisher($this->tempDir, null, $translator);
        $projectUuid = 'bafb2021-079e-44b0-8fe9-35af563dc2b2';

        $publisher->contentChanged($projectUuid, 'created', []);
        $publisher->mediaUploaded($projectUuid, []);

        $jsonlFile = $this->tempDir . '/var/realtime/' . $projectUuid . '.jsonl';
        $content = (string) file_get_contents($jsonlFile);

        $this->assertStringContainsString('Titre Inconnu', $content);
        $this->assertStringContainsString('Fichier Média', $content);
    }

    // ─── 4. HreflangGenerator Hardening ──────────────────────────────────────

    public function testHreflangGeneratorNeutralizesCrlfAndXss(): void
    {
        $generator = new HreflangGenerator();

        $project = new Project();
        $project->previewUrl = "https://example.com\r\nX-Injected: true";
        $project->locales = ["en\r\n", "fr", "invalid<script>alert(1)</script>"];
        $project->defaultLocale = 'en';

        $collection = new Collection();
        $collection->project = $project;
        $collection->slug = "blog\r\n";

        $entry = new ContentEntry();
        $entry->collection = $collection;
        $entry->slug = "hello-world\r\n";

        $links = $generator->generateHreflang($entry);

        foreach ($links as $loc => $url) {
            $this->assertStringNotContainsString("\r", $url);
            $this->assertStringNotContainsString("\n", $url);
            $this->assertStringNotContainsString("<script>", $url);
        }
        $this->assertArrayHasKey('fr', $links);
        $this->assertArrayNotHasKey("invalid<script>alert(1)</script>", $links);
    }

    // ─── 5. SeoAnalyzer & SeoPdfExporter Hardening ────────────────────────────

    public function testSeoAnalyzerScansBoundedTextSafely(): void
    {
        $analyzer = new SeoAnalyzer();

        $entry = new ContentEntry();
        $entry->metaTitle = 'Comprehensive SEO Hardening Guide for Enterprise CMS';
        $entry->metaDescription = 'Explore advanced security hardening, SEO analysis, and best practices for building robust headless CMS applications.';
        $entry->slug = 'seo-hardening-guide';
        $entry->ogImage = 'uuid-image';

        // Add 2MB of text across field values
        $fv = new \App\Entity\ContentFieldValue();
        $fv->fieldType = 'richtext';
        $fv->textValue = str_repeat('Fast and secure headless content management systems. ', 10000);
        $entry->fieldValues->add($fv);

        $score = $analyzer->analyze($entry, 'SEO');

        $this->assertGreaterThan(70, $score->score);
        $this->assertArrayHasKey('title_length', $score->criteria);
        $this->assertTrue($score->criteria['content_length']['passed']);
    }

    public function testSeoPdfExporterGeneratesHtmlWithRtlForArabic(): void
    {
        $analyzer = new SeoAnalyzer();
        $exporter = new SeoPdfExporter($analyzer);

        $entry = new ContentEntry();
        $entry->locale = 'ar';
        $entry->metaTitle = 'دليل تحسين محركات البحث الشامل';
        $entry->metaDescription = 'أفضل ممارسات تحسين محركات البحث لعام 2026';
        $entry->slug = 'seo-guide-arabic';

        // Use reflection to test buildHtml
        $reflection = new \ReflectionClass(SeoPdfExporter::class);
        $method = $reflection->getMethod('buildHtml');
        $method->setAccessible(true);

        $score = $analyzer->analyze($entry);
        $report = $analyzer->audit($entry);

        $html = $method->invoke($exporter, $entry, $score, $report, 'ar');

        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('lang="ar"', $html);
        $this->assertStringContainsString('text-align: right', $html);
    }

    // ─── 6. LinkChecker & RedirectResolver Hardening ─────────────────────────

    public function testLinkCheckerFiltersDeletedEntriesAndBoundsText(): void
    {
        $project = new Project();

        $activeEntry = new ContentEntry();
        $activeEntry->project = $project;
        $activeEntry->slug = 'active-page';
        $fvActive = new \App\Entity\ContentFieldValue();
        $fvActive->fieldType = 'richtext';
        $fvActive->textValue = '<a href="/about-us">About Us</a>';
        $activeEntry->fieldValues->add($fvActive);

        $deletedEntry = new ContentEntry();
        $deletedEntry->project = $project;
        $deletedEntry->slug = 'deleted-page';
        $deletedEntry->deletedAt = new \DateTimeImmutable();
        $fvDeleted = new \App\Entity\ContentFieldValue();
        $fvDeleted->fieldType = 'richtext';
        $fvDeleted->textValue = '<a href="/secret-leak">Secret Leak</a>';
        $deletedEntry->fieldValues->add($fvDeleted);

        $repo = $this->createMock(\App\Repository\ContentEntryRepository::class);
        $repo->method('findBy')->willReturn([$activeEntry, $deletedEntry]);

        $checker = new LinkChecker($repo);
        $results = $checker->checkLinks($project);

        $urls = array_column($results, 'url');
        $this->assertContains('/about-us', $urls);
        $this->assertNotContains('/secret-leak', $urls);
    }

    public function testRedirectResolverHandlesLongPatternsSafely(): void
    {
        $project = new Project();

        $r = new Redirect();
        $r->fromPath = str_repeat('a', 600); // Exceeds 500 chars limit
        $r->toPath = '/safe-dest';
        $r->isPattern = true;

        $repo = $this->createMock(\App\Repository\RedirectRepository::class);
        $repo->method('findActiveByProject')->willReturn([$r]);

        $resolver = new RedirectResolver($repo);
        $result = $resolver->resolve('/test-path', $project);

        // Pattern matching skipped safely without regex crashes or infinite recursion
        $this->assertNull($result);
    }
}
