<?php

namespace App\Tests\Service;

use App\Entity\Project;
use App\Repository\MediaRepository;
use App\Service\Security\SsrfProtectionService;
use App\Service\UploadSecurityValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class Cycle77UploadAndSsrfI18nTest extends TestCase
{
    private function createMockTranslator(array $translations): TranslatorInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            function (string $id, array $parameters = [], ?string $domain = null, ?string $locale = null) use ($translations) {
                if (isset($translations[$id])) {
                    return strtr($translations[$id], $parameters);
                }
                return $id;
            }
        );
        return $translator;
    }

    public function testUploadSecurityValidatorTranslatesNullByteError(): void
    {
        $translator = $this->createMockTranslator([
            'upload.error.null_byte' => 'Le nom de fichier contient un octet nul interdit.',
        ]);
        $mediaRepo = $this->createMock(MediaRepository::class);
        $validator = new UploadSecurityValidator($mediaRepo, $translator);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le nom de fichier contient un octet nul interdit.');
        $validator->sanitizeAndValidateFilename("exploit.jpg\0.php");
    }

    public function testUploadSecurityValidatorTranslatesExtensionForbidden(): void
    {
        $translator = $this->createMockTranslator([
            'upload.error.extension_forbidden' => 'L\'extension de fichier ".%extension%" est strictement interdite.',
        ]);
        $mediaRepo = $this->createMock(MediaRepository::class);
        $validator = new UploadSecurityValidator($mediaRepo, $translator);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('L\'extension de fichier ".php" est strictement interdite.');
        $validator->sanitizeAndValidateFilename("backdoor.php");
    }

    public function testUploadSecurityValidatorTranslatesQuotaExceeded(): void
    {
        $translator = $this->createMockTranslator([
            'upload.error.quota_exceeded' => 'Quota de stockage du projet dépassé (%used% Mo utilisés / %limit% Mo autorisés).',
        ]);
        $mediaRepo = $this->createMock(MediaRepository::class);
        $mediaRepo->method('getTotalSizeByProject')->willReturn(48 * 1024 * 1024);

        $validator = new UploadSecurityValidator($mediaRepo, $translator);

        $project = new Project();
        $project->settings = [
            'upload_max_file_size_mb' => 20,
            'storage_quota_mb' => 50,
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Quota de stockage du projet dépassé (53 Mo utilisés / 50 Mo autorisés).');
        $validator->validateSizeAndQuota($project, 5 * 1024 * 1024);
    }

    public function testSsrfProtectionServiceTranslatesHostControlChars(): void
    {
        $translator = $this->createMockTranslator([
            'ssrf.host_control_chars' => 'L\'hôte contient des caractères de contrôle ou de saut de ligne invalides',
        ]);
        $ssrf = new SsrfProtectionService($translator);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('L\'hôte contient des caractères de contrôle ou de saut de ligne invalides');
        $ssrf->assertSafeHost("api.example.com\r\n");
    }

    public function testSsrfProtectionServiceTranslatesPrivateAddressForbidden(): void
    {
        $translator = $this->createMockTranslator([
            'ssrf.private_address_forbidden' => 'Acceso a direcciones privadas o reservadas prohibido (protección SSRF)',
        ]);
        $ssrf = new SsrfProtectionService($translator);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Acceso a direcciones privadas o reservadas prohibido (protección SSRF)');
        $ssrf->assertSafeHost("127.0.0.1");
    }

    public function testSsrfProtectionServiceTranslatesHttpsRequired(): void
    {
        $translator = $this->createMockTranslator([
            'ssrf.https_required' => 'بروتوكول HTTPS إلزامي',
        ]);
        $ssrf = new SsrfProtectionService($translator);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('بروتوكول HTTPS إلزامي');
        $ssrf->assertSafeUrl("http://example.com/api", true);
    }

    public function testAllCycle77KeysExistInAllLocales(): void
    {
        $en = require __DIR__ . '/../../translations/messages.en.php';
        $fr = require __DIR__ . '/../../translations/messages.fr.php';
        $es = require __DIR__ . '/../../translations/messages.es.php';
        $ar = require __DIR__ . '/../../translations/messages.ar.php';

        $keys = [
            'upload.error.null_byte',
            'upload.error.invalid_filename',
            'upload.error.extension_required',
            'upload.error.extension_forbidden',
            'upload.error.extension_not_allowed',
            'upload.error.mime_control_chars',
            'upload.error.mime_invalid_format',
            'upload.error.mime_forbidden',
            'upload.error.cannot_read',
            'upload.error.mime_not_allowed',
            'upload.error.php_code_detected',
            'upload.error.corrupted_jpeg',
            'upload.error.corrupted_png',
            'upload.error.corrupted_gif',
            'upload.error.corrupted_pdf',
            'upload.error.corrupted_webp',
            'upload.error.xml_doctype_forbidden',
            'upload.error.svg_script_detected',
            'upload.error.svg_event_handlers',
            'upload.error.svg_href_script',
            'upload.error.svg_animation_script',
            'upload.error.svg_forbidden_pattern',
            'upload.error.size_zero',
            'upload.error.size_exceeded',
            'upload.error.quota_exceeded',
            'ssrf.host_control_chars',
            'ssrf.host_empty',
            'ssrf.host_too_long',
            'ssrf.private_address_forbidden',
            'ssrf.url_empty',
            'ssrf.url_invalid_format',
            'ssrf.url_malformed',
            'ssrf.https_required',
            'ssrf.allowed_schemes',
            'ssrf.credentials_forbidden',
            'ssrf.missing_host',
            'ssrf.invalid_host',
            'ssrf.forbidden_port',
            'ssrf.unresolved_host',
            'ssrf.dns_resolution_failed',
        ];

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $en, "Missing $key in en");
            $this->assertArrayHasKey($key, $fr, "Missing $key in fr");
            $this->assertArrayHasKey($key, $es, "Missing $key in es");
            $this->assertArrayHasKey($key, $ar, "Missing $key in ar");

            $this->assertNotEmpty($en[$key], "Empty $key in en");
            $this->assertNotEmpty($fr[$key], "Empty $key in fr");
            $this->assertNotEmpty($es[$key], "Empty $key in es");
            $this->assertNotEmpty($ar[$key], "Empty $key in ar");
        }
    }
}
