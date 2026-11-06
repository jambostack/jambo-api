<?php

namespace App\Tests\Service;

use App\Entity\Project;
use App\Repository\MediaRepository;
use App\Service\UploadSecurityValidator;
use PHPUnit\Framework\TestCase;

class UploadSecurityValidatorTest extends TestCase
{
    private MediaRepository $mediaRepository;
    private UploadSecurityValidator $validator;

    protected function setUp(): void
    {
        $this->mediaRepository = $this->createMock(MediaRepository::class);
        $this->validator = new UploadSecurityValidator($this->mediaRepository);
    }

    public function testSanitizeAndValidateFilenameAllowsSafeFiles(): void
    {
        $this->assertSame('photo.jpg', $this->validator->sanitizeAndValidateFilename('photo.jpg'));
        $this->assertSame('report.pdf', $this->validator->sanitizeAndValidateFilename('../../../report.pdf'));
        $this->assertSame('archive.tar.gz', $this->validator->sanitizeAndValidateFilename('archive.tar.gz'));
    }

    public function testSanitizeAndValidateFilenameRejectsNullByte(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('forbidden null byte');
        $this->validator->sanitizeAndValidateFilename("photo.jpg\0.php");
    }

    public function testSanitizeAndValidateFilenameRejectsForbiddenExtensions(): void
    {
        $forbidden = ['shell.php', 'exploit.phtml', 'malware.exe', 'backdoor.phar', 'script.sh'];
        foreach ($forbidden as $file) {
            try {
                $this->validator->sanitizeAndValidateFilename($file);
                $this->fail("Expected exception for $file");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('forbidden', $e->getMessage());
            }
        }
    }

    public function testSanitizeAndValidateFilenameRejectsCompoundForbiddenExtensions(): void
    {
        $compound = ['photo.php.jpg', 'exploit.phar.png', 'document.phtml.pdf', 'archive.exe.zip', 'avatar.php5.webp'];
        foreach ($compound as $file) {
            try {
                $this->validator->sanitizeAndValidateFilename($file);
                $this->fail("Expected exception for compound extension in $file");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Compound file extension', $e->getMessage());
            }
        }
    }

    public function testSanitizeAndValidateFilenameAllowsSafeMultiDotFiles(): void
    {
        $this->assertSame('report.2026.04.12.pdf', $this->validator->sanitizeAndValidateFilename('report.2026.04.12.pdf'));
        $this->assertSame('archive.tar.gz', $this->validator->sanitizeAndValidateFilename('archive.tar.gz'));
        $this->assertSame('my.backup.sql.gz', $this->validator->sanitizeAndValidateFilename('my.backup.sql.gz'));
    }

    public function testValidateFileContentAllowsValidJpeg(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_jpg');
        // JPEG magic bytes: FF D8 FF E0
        file_put_contents($tempFile, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x01\x00`\x00`\x00\x00");

        try {
            $mime = $this->validator->validateFileContent($tempFile, 'jpg');
            $this->assertNotEmpty($mime);
        } finally {
            @unlink($tempFile);
        }
    }

    public function testValidateFileContentRejectsInvalidJpegMagicBytes(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_fake_jpg');
        file_put_contents($tempFile, "NOT_A_JPEG_FILE_CONTENT");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Corrupted or invalid JPEG image header.');

        try {
            $this->validator->validateFileContent($tempFile, 'jpg');
        } finally {
            @unlink($tempFile);
        }
    }

    public function testValidateFileContentRejectsEmbeddedPhpInMedia(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_polyglot');
        // Valid JPEG header followed by PHP webshell
        file_put_contents($tempFile, "\xFF\xD8\xFF\xE0<?php phpinfo(); ?>");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('dangerous PHP executable code');

        try {
            $this->validator->validateFileContent($tempFile, 'jpg');
        } finally {
            @unlink($tempFile);
        }
    }

    public function testValidateFileContentRejectsSvgWithScript(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_xss_svg');
        file_put_contents($tempFile, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SVG file contains dangerous active script pattern');

        try {
            $this->validator->validateFileContent($tempFile, 'svg');
        } finally {
            @unlink($tempFile);
        }
    }

    public function testValidateFileContentRejectsSvgWithDoctypeXxe(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_xxe_svg');
        file_put_contents($tempFile, '<!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><svg>&xxe;</svg>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('XML/SVG file containing DOCTYPE or ENTITY');

        try {
            $this->validator->validateFileContent($tempFile, 'svg');
        } finally {
            @unlink($tempFile);
        }
    }

    public function testValidateFileContentRejectsSvgWithInlineEventHandler(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_attr_svg');
        file_put_contents($tempFile, '<svg onload="alert(1)"></svg>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SVG file contains dangerous inline event handler attributes.');

        try {
            $this->validator->validateFileContent($tempFile, 'svg');
        } finally {
            @unlink($tempFile);
        }
    }

    public function testValidateFileContentRejectsSvgWithJavascriptHref(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_href_svg');
        file_put_contents($tempFile, '<svg><a href="javascript:alert(1)"><text>Click</text></a></svg>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SVG file contains dangerous script or data URI in href attribute.');

        try {
            $this->validator->validateFileContent($tempFile, 'svg');
        } finally {
            @unlink($tempFile);
        }
    }

    public function testValidateFileContentRejectsSvgWithForeignObject(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_fo_svg');
        file_put_contents($tempFile, '<svg><foreignObject width="100" height="50"><div>test</div></foreignObject></svg>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SVG file contains dangerous active script pattern');

        try {
            $this->validator->validateFileContent($tempFile, 'svg');
        } finally {
            @unlink($tempFile);
        }
    }

    public function testValidateFileContentRejectsSvgWithCdataScript(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_cdata_svg');
        file_put_contents($tempFile, '<svg><script><![CDATA[alert(document.cookie)]]></script></svg>');

        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->validator->validateFileContent($tempFile, 'svg');
        } finally {
            @unlink($tempFile);
        }
    }

    public function testValidateFileContentRejectsSvgWithXhtmlNamespace(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_xhtml_svg');
        file_put_contents($tempFile, '<svg xmlns="http://www.w3.org/2000/svg" xmlns:html="http://www.w3.org/1999/xhtml"><text>Test</text></svg>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SVG file contains forbidden script pattern or namespace.');

        try {
            $this->validator->validateFileContent($tempFile, 'svg');
        } finally {
            @unlink($tempFile);
        }
    }

    public function testValidateFileContentRejectsObfuscatedPhpInMedia(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_obf_php');
        file_put_contents($tempFile, "\xFF\xD8\xFF\xE0<\x00?\x00p\x00h\x00p\x00 \x00s\x00y\x00s\x00t\x00e\x00m\x00(\x00");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('dangerous PHP executable code');

        try {
            $this->validator->validateFileContent($tempFile, 'jpg');
        } finally {
            @unlink($tempFile);
        }
    }

    public function testValidateSizeAndQuotaEnforcesMaxFileSize(): void
    {
        $project = new Project();
        $project->settings = ['upload_max_file_size_mb' => 2]; // 2MB max

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds maximum allowed limit');

        $this->validator->validateSizeAndQuota($project, 3 * 1024 * 1024);
    }

    public function testValidateSizeAndQuotaEnforcesProjectQuota(): void
    {
        $project = new Project();
        $project->settings = [
            'upload_max_file_size_mb' => 10,
            'storage_quota_mb' => 50, // 50MB quota
        ];

        // 45MB already used
        $this->mediaRepository->method('getTotalSizeByProject')->willReturn(45 * 1024 * 1024);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Project storage quota exceeded');

        // Trying to upload 10MB (total 55MB > 50MB quota)
        $this->validator->validateSizeAndQuota($project, 10 * 1024 * 1024);
    }
}
