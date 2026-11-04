<?php

declare(strict_types=1);

namespace App\Tests\Service\Security;

use App\Service\Security\CsvSanitizer;
use PHPUnit\Framework\TestCase;

class CsvSanitizerTest extends TestCase
{
    private CsvSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new CsvSanitizer();
    }

    public function testSanitizeSafeValues(): void
    {
        $this->assertSame('Hello world', $this->sanitizer->sanitizeValue('Hello world'));
        $this->assertSame('123', $this->sanitizer->sanitizeValue(123));
        $this->assertSame('45.67', $this->sanitizer->sanitizeValue(45.67));
        $this->assertSame('true', $this->sanitizer->sanitizeValue(true));
        $this->assertSame('false', $this->sanitizer->sanitizeValue(false));
        $this->assertSame('', $this->sanitizer->sanitizeValue(null));
    }

    public function testSanitizeFormulaTriggers(): void
    {
        // OWASP CSV Injection triggers: =, +, -, @, \t, \r, %, |
        $this->assertSame("'=cmd|' /C calc'!A0", $this->sanitizer->sanitizeValue("=cmd|' /C calc'!A0"));
        $this->assertSame("'+1234567890", $this->sanitizer->sanitizeValue("+1234567890"));
        $this->assertSame("'-2+3+cmd", $this->sanitizer->sanitizeValue("-2+3+cmd"));
        $this->assertSame("'@SUM(A1:A10)", $this->sanitizer->sanitizeValue("@SUM(A1:A10)"));
        $this->assertSame("'%test", $this->sanitizer->sanitizeValue("%test"));
        $this->assertSame("'|calc.exe", $this->sanitizer->sanitizeValue("|calc.exe"));
    }

    public function testSanitizeTriggersWithLeadingWhitespace(): void
    {
        // Espaces initiaux suivis d'un trigger
        $this->assertSame("'   =1+1", $this->sanitizer->sanitizeValue("   =1+1"));
        $this->assertSame("'\t=HYPERLINK()", $this->sanitizer->sanitizeValue("\t=HYPERLINK()"));
    }

    public function testSanitizeRemovesDangerousControlCharacters(): void
    {
        $input = "clean\x00data\x08with\x1Fcontrols";
        $sanitized = $this->sanitizer->sanitizeValue($input);
        $this->assertSame('cleandatawithcontrols', $sanitized);
    }

    public function testSanitizeRow(): void
    {
        $row = [
            'id' => 1,
            'name' => 'Alice',
            'formula' => '=SUM(1,2)',
            'phone' => '+33600000000',
        ];

        $sanitized = $this->sanitizer->sanitizeRow($row);
        $this->assertSame(['1', 'Alice', "'=SUM(1,2)", "'+33600000000"], $sanitized);
    }

    public function testToCsvLine(): void
    {
        $row = ['John', '=2+2', 'john@example.com'];
        $csvLine = $this->sanitizer->toCsvLine($row);

        $this->assertStringContainsString('John', $csvLine);
        $this->assertStringContainsString("'\=2+2", addcslashes($csvLine, '='));
        $this->assertStringContainsString('john@example.com', $csvLine);
    }

    public function testBomUtf8Constant(): void
    {
        $this->assertSame("\xEF\xBB\xBF", CsvSanitizer::BOM_UTF8);
    }
}
