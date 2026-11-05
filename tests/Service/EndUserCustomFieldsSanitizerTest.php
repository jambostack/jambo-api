<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\EndUserCustomFieldsSanitizer;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class EndUserCustomFieldsSanitizerTest extends TestCase
{
    private EndUserCustomFieldsSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new EndUserCustomFieldsSanitizer();
    }

    public function testSanitizeNullReturnsNull(): void
    {
        $res = $this->sanitizer->sanitize(null);
        $this->assertTrue($res['valid']);
        $this->assertNull($res['sanitized']);
        $this->assertNull($res['error']);
    }

    public function testSanitizeRejectsNonArray(): void
    {
        $res = $this->sanitizer->sanitize('not-an-array');
        $this->assertFalse($res['valid']);
        $this->assertSame('Custom fields must be a valid key-value object.', $res['error']);
    }

    public function testSanitizeRejectsTooLargePayload(): void
    {
        $huge = ['field' => str_repeat('a', 66000)];
        $res = $this->sanitizer->sanitize($huge);
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('max 64KB', $res['error']);
    }

    public function testSanitizeRejectsForbiddenKeys(): void
    {
        $badKeys = ['__proto__', 'constructor', 'prototype', 'password', 'roles', 'token_version', 'is_admin', 'two_factor_secret'];
        foreach ($badKeys as $badKey) {
            $res = $this->sanitizer->sanitize([$badKey => 'value']);
            $this->assertFalse($res['valid'], "Key $badKey should have been rejected");
            $this->assertStringContainsString('is reserved', $res['error']);
        }
    }

    public function testSanitizeRejectsDollarPrefixedKeys(): void
    {
        $res = $this->sanitizer->sanitize(['$where' => 'bad']);
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('Invalid custom field key', $res['error']);
    }

    public function testSanitizeRejectsNullByteInKey(): void
    {
        $res = $this->sanitizer->sanitize(["bad\0key" => 'value']);
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('Invalid custom field key', $res['error']);
    }

    public function testSanitizeRejectsExcessiveDepth(): void
    {
        $deep = [
            'l1' => [
                'l2' => [
                    'l3' => [
                        'l4' => [
                            'l5' => [
                                'l6' => 'too-deep',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $res = $this->sanitizer->sanitize($deep);
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('nesting exceeds maximum depth', $res['error']);
    }

    public function testSanitizeRejectsExcessiveKeys(): void
    {
        $many = [];
        for ($i = 0; $i < 105; $i++) {
            $many['k_' . $i] = 'v';
        }

        $res = $this->sanitizer->sanitize($many);
        $this->assertFalse($res['valid']);
        $this->assertStringContainsString('contain too many entries', $res['error']);
    }

    public function testSanitizeCleansNullBytesAndControlCharsFromStringValues(): void
    {
        $input = [
            'company' => "Acme\0 Corp\x07 Ltd",
            'notes'   => "Line 1\nLine 2\tTabbed",
            'bio'     => "Text with \x1b[31mANSI\x1b[0m codes",
        ];

        $res = $this->sanitizer->sanitize($input);
        $this->assertTrue($res['valid']);
        $this->assertSame('Acme Corp Ltd', $res['sanitized']['company']);
        $this->assertSame("Line 1\nLine 2\tTabbed", $res['sanitized']['notes']);
        $this->assertSame('Text with [31mANSI[0m codes', $res['sanitized']['bio']);
    }

    public function testSanitizePreservesValidScalarsAndSubarrays(): void
    {
        $valid = [
            'theme'         => 'dark',
            'notifications' => true,
            'score'         => 42,
            'ratio'         => 3.14,
            'preferences'   => [
                'language' => 'fr',
                'digest'   => false,
            ],
        ];

        $res = $this->sanitizer->sanitize($valid);
        $this->assertTrue($res['valid']);
        $this->assertSame($valid, $res['sanitized']);
    }

    public function testTranslatorReturnsLocalizedError(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(function (string $id) {
            if ($id === 'enduser.custom_fields.must_be_object') {
                return 'Les champs personnalisés doivent être un objet valide.';
            }
            return $id;
        });

        $sanitizer = new EndUserCustomFieldsSanitizer($translator);
        $res = $sanitizer->sanitize(12345);
        $this->assertFalse($res['valid']);
        $this->assertSame('Les champs personnalisés doivent être un objet valide.', $res['error']);
    }
}
