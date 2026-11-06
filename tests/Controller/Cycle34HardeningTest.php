<?php

namespace App\Tests\Controller;

use App\Controller\PreviewController;
use App\Controller\Public\FormPublicController;
use App\Controller\Public\RedirectPublicController;
use App\Controller\Public\SeoPublicController;
use App\Entity\Project;
use App\Entity\Redirect;
use App\Repository\ContentEntryRepository;
use App\Repository\FormRepository;
use App\Repository\ProjectRepository;
use App\Service\EavDataFormatterService;
use App\Service\Form\FormBuilder;
use App\Service\Form\SubmitHandler;
use App\Service\PreviewTokenService;
use App\Service\Redirect\RedirectResolver;
use App\Service\Security\SsrfProtectionService;
use App\Service\Seo\SitemapGenerator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Cycle 34 — Security Hardening & Strict I18N Parity:
 * - PreviewController
 * - FormPublicController
 * - RedirectPublicController
 * - SeoPublicController
 *
 * Enforces:
 * 1. 100% I18N parity across 4 locales (en, fr, es, ar) for secondary controllers (35 keys).
 * 2. Target URL Anti-SSRF and input constraints on PreviewController.
 * 3. Strict UUID format and slug validation on FormPublicController.
 * 4. Open-redirect Anti-SSRF and CRLF defense on RedirectPublicController.
 * 5. Private URL topology defense and UUID validation on SeoPublicController.
 */
class Cycle34HardeningTest extends WebTestCase
{
    private const LOCALES = ['en', 'fr', 'es', 'ar'];

    // ─────────────────────────────────────────────────────────────────
    // 1. PreviewController Translation Keys Parity
    // ─────────────────────────────────────────────────────────────────

    public function testPreviewTranslationKeysExistInAllLocales(): void
    {
        $keys = [
            'preview.rate_limit_exceeded',
            'preview.auth_required',
            'preview.project_not_found',
            'preview.entry_not_found',
            'preview.invalid_ttl_not_int',
            'preview.invalid_ttl_range',
            'preview.url_required',
            'preview.payload_too_large',
            'preview.target_url_empty',
            'preview.target_url_too_long',
            'preview.target_url_control_chars',
            'preview.target_url_malformed',
            'preview.target_url_scheme_invalid',
            'preview.target_url_credentials_forbidden',
            'preview.target_url_missing_host',
            'preview.target_url_ssrf_detected',
            'preview.target_url_forbidden_port',
            'preview.missing_token',
            'preview.token_invalid_or_expired',
            'preview.token_project_mismatch',
            'preview.token_entry_mismatch',
            'preview.token_collection_mismatch',
            'preview.not_enabled',
        ];

        foreach (self::LOCALES as $locale) {
            $file = __DIR__ . '/../../translations/messages.' . $locale . '.php';
            self::assertFileExists($file);
            $catalog = require $file;
            foreach ($keys as $key) {
                self::assertArrayHasKey($key, $catalog, "Missing preview key '$key' in locale '$locale'");
                self::assertNotEmpty($catalog[$key], "Empty preview key '$key' in locale '$locale'");
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // 2. FormPublicController Translation Keys Parity
    // ─────────────────────────────────────────────────────────────────

    public function testFormPublicTranslationKeysExistInAllLocales(): void
    {
        $keys = [
            'form.project_not_found',
            'form.not_found',
            'form.rate_limit_exceeded',
            'form.payload_too_large',
            'form.validation_failed',
            'form.invalid_slug',
        ];

        foreach (self::LOCALES as $locale) {
            $file = __DIR__ . '/../../translations/messages.' . $locale . '.php';
            self::assertFileExists($file);
            $catalog = require $file;
            foreach ($keys as $key) {
                self::assertArrayHasKey($key, $catalog, "Missing form public key '$key' in locale '$locale'");
                self::assertNotEmpty($catalog[$key], "Empty form public key '$key' in locale '$locale'");
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // 3. RedirectPublicController & SeoPublicController Translation Keys Parity
    // ─────────────────────────────────────────────────────────────────

    public function testRedirectAndSeoTranslationKeysExistInAllLocales(): void
    {
        $keys = [
            'redirect.project_not_found',
            'redirect.path_too_long',
            'redirect.path_control_chars',
            'redirect.unsafe_destination',
            'redirect.rate_limit_exceeded',
            'seo.project_not_found',
        ];

        foreach (self::LOCALES as $locale) {
            $file = __DIR__ . '/../../translations/messages.' . $locale . '.php';
            self::assertFileExists($file);
            $catalog = require $file;
            foreach ($keys as $key) {
                self::assertArrayHasKey($key, $catalog, "Missing redirect/seo key '$key' in locale '$locale'");
                self::assertNotEmpty($catalog[$key], "Empty redirect/seo key '$key' in locale '$locale'");
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // 4. PreviewController Hardening & Translator Integration
    // ─────────────────────────────────────────────────────────────────

    public function testPreviewControllerTranslatesErrorWhenTranslatorInjected(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(function (string $id, array $params = []) {
            if ($id === 'preview.target_url_scheme_invalid') {
                return 'TRADUCTION_FR_SCHEME_INVALIDE';
            }
            if ($id === 'preview.target_url_forbidden_port') {
                return 'TRADUCTION_FR_PORT_' . ($params['%port%'] ?? '');
            }
            return $id;
        });

        $controller = new PreviewController(
            $this->createStub(ProjectRepository::class),
            $this->createStub(ContentEntryRepository::class),
            $this->createStub(PreviewTokenService::class),
            $this->createStub(EavDataFormatterService::class),
            new SsrfProtectionService(),
            null,
            $translator
        );

        $errScheme = $controller->validatePreviewTargetUrl('ftp://invalide.com');
        self::assertSame('TRADUCTION_FR_SCHEME_INVALIDE', $errScheme);

        $errPort = $controller->validatePreviewTargetUrl('http://example.com:3306');
        self::assertSame('TRADUCTION_FR_PORT_3306', $errPort);
    }

    public function testPreviewControllerValidateUrlRejectsOversizedPayload(): void
    {
        $controller = new PreviewController(
            $this->createStub(ProjectRepository::class),
            $this->createStub(ContentEntryRepository::class),
            $this->createStub(PreviewTokenService::class),
            $this->createStub(EavDataFormatterService::class),
            new SsrfProtectionService()
        );

        // Huge payload > 1MB
        $huge = str_repeat('A', 1048577);
        $req = Request::create('/api/projects/test/preview/validate-url', 'POST', [], [], [], [], $huge);
        $res = $controller->validateUrl('00000000-0000-0000-0000-000000000000', $req);

        self::assertSame(413, $res->getStatusCode());
        $data = json_decode((string) $res->getContent(), true);
        self::assertSame('Payload too large.', $data['error']);
    }

    // ─────────────────────────────────────────────────────────────────
    // 5. FormPublicController Input Constraints & I18N
    // ─────────────────────────────────────────────────────────────────

    public function testFormPublicControllerRejectsInvalidUuidAndSlug(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(fn($k) => 'T_' . $k);

        $controller = new FormPublicController(
            $this->createStub(ProjectRepository::class),
            $this->createStub(FormRepository::class),
            $this->createStub(FormBuilder::class),
            $this->createStub(SubmitHandler::class),
            null,
            null,
            $translator
        );

        // Invalid project UUID
        $resUuid = $controller->show('not-a-valid-uuid', 'contact-form');
        self::assertSame(404, $resUuid->getStatusCode());
        $dataUuid = json_decode((string) $resUuid->getContent(), true);
        self::assertSame('T_form.project_not_found', $dataUuid['error']);

        // Invalid slug (contains spaces or dangerous characters)
        $validUuid = '00000000-0000-4000-8000-000000000001';
        $resSlug = $controller->show($validUuid, 'invalid slug with spaces!');
        self::assertSame(400, $resSlug->getStatusCode());
        $dataSlug = json_decode((string) $resSlug->getContent(), true);
        self::assertSame('T_form.invalid_slug', $dataSlug['error']);

        // Submit rejects oversized payload > 1MB
        $reqBig = Request::create("/api/projects/$validUuid/forms/contact/submit", 'POST', [], [], [], [], str_repeat('B', 1048577));
        $resBig = $controller->submit($validUuid, 'contact', $reqBig);
        self::assertSame(413, $resBig->getStatusCode());
        $dataBig = json_decode((string) $resBig->getContent(), true);
        self::assertSame('T_form.payload_too_large', $dataBig['error']);
    }

    // ─────────────────────────────────────────────────────────────────
    // 6. RedirectPublicController Anti-SSRF, Control Chars & I18N
    // ─────────────────────────────────────────────────────────────────

    public function testRedirectPublicControllerBlocksSsrfOnExternalDestinations(): void
    {
        $project = new Project();
        $projectRepo = $this->createStub(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $redirect = new Redirect();
        $redirect->fromPath = '/admin-redirect';
        $redirect->toPath = 'http://127.0.0.1:8080/internal-admin';
        $redirect->httpCode = 302;

        $resolver = $this->createStub(RedirectResolver::class);
        $resolver->method('resolve')->willReturn($redirect);

        $ssrf = new SsrfProtectionService();
        $controller = new RedirectPublicController(
            $projectRepo,
            $resolver,
            $ssrf
        );

        // Request with text/html Accept header triggers external redirect check
        $validUuid = '00000000-0000-4000-8000-000000000001';
        $req = Request::create("/$validUuid/redirects/resolve?path=/admin-redirect", 'GET', [], [], [], [
            'HTTP_ACCEPT' => 'text/html,application/xhtml+xml',
        ]);

        $res = $controller->resolve($validUuid, $req);
        self::assertSame(400, $res->getStatusCode());
        $data = json_decode((string) $res->getContent(), true);
        self::assertSame('Unsafe redirect destination.', $data['error']);
    }

    public function testRedirectPublicControllerBlocksPathWithControlChars(): void
    {
        $project = new Project();
        $projectRepo = $this->createStub(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $controller = new RedirectPublicController(
            $projectRepo,
            $this->createStub(RedirectResolver::class),
            new SsrfProtectionService()
        );

        $validUuid = '00000000-0000-4000-8000-000000000001';
        $req = Request::create("/$validUuid/redirects/resolve", 'GET', [
            'path' => "/legit\r\nInjected-Header: 1",
        ]);

        $res = $controller->resolve($validUuid, $req);
        self::assertSame(400, $res->getStatusCode());
        $data = json_decode((string) $res->getContent(), true);
        self::assertSame('Path contains invalid control characters.', $data['error']);
    }

    // ─────────────────────────────────────────────────────────────────
    // 7. SeoPublicController SSRF Protection & UUID Constraints
    // ─────────────────────────────────────────────────────────────────

    public function testSeoPublicControllerBlocksPrivateSsrfInRobotsTxt(): void
    {
        $project = new Project();
        $project->name = 'Test Project';
        // Malicious or private previewUrl pointing to cloud metadata
        $project->previewUrl = 'http://169.254.169.254/latest';
        $project->settings = ['seo' => ['enableSitemap' => true]];

        $projectRepo = $this->createStub(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $controller = new SeoPublicController(
            $projectRepo,
            $this->createStub(SitemapGenerator::class),
            new SsrfProtectionService()
        );

        $validUuid = '00000000-0000-4000-8000-000000000001';
        $res = $controller->robots($validUuid);

        self::assertSame(200, $res->getStatusCode());
        $content = (string) $res->getContent();
        // Must fallback to https://example.com and NOT expose the private 169.254.169.254 IP
        self::assertStringNotContainsString('169.254.169.254', $content);
        self::assertStringContainsString('https://example.com/' . $validUuid . '/sitemap.xml', $content);
    }

    public function testSeoPublicControllerRejectsInvalidUuidWith404(): void
    {
        $controller = new SeoPublicController(
            $this->createStub(ProjectRepository::class),
            $this->createStub(SitemapGenerator::class),
            new SsrfProtectionService()
        );

        $this->expectException(NotFoundHttpException::class);
        $controller->sitemap('invalid-uuid-format');
    }
}
