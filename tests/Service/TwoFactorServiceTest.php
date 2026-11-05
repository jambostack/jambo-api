<?php

namespace App\Tests\Service;

use App\Service\TwoFactorService;
use PHPUnit\Framework\TestCase;

class TwoFactorServiceTest extends TestCase
{
    private TwoFactorService $service;

    protected function setUp(): void
    {
        $this->service = new TwoFactorService();
    }

    public function testGenerateSecretReturnsValidBase32String(): void
    {
        $secret = $this->service->generateSecret();
        $this->assertNotEmpty($secret);
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
    }

    public function testGetProvisioningUri(): void
    {
        $secret = $this->service->generateSecret();
        $uri = $this->service->getProvisioningUri($secret, 'user@example.com', 'JamboTest');
        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=' . $secret, $uri);
        $this->assertStringContainsString('issuer=JamboTest', $uri);
    }

    public function testVerifyTotpRejectsInvalidInputs(): void
    {
        $secret = $this->service->generateSecret();

        // Too short / not 6 digits
        $this->assertFalse($this->service->verifyTotp($secret, '123'));
        $this->assertFalse($this->service->verifyTotp($secret, '1234567'));
        $this->assertFalse($this->service->verifyTotp($secret, 'abcdef'));
        $this->assertFalse($this->service->verifyTotp('', '123456'));
    }

    public function testVerifyTotpCatchesExceptionOnCorruptedSecret(): void
    {
        $this->assertFalse($this->service->verifyTotp('!!!invalid-secret-890!!!', '123456'));
    }

    public function testGenerateAndConsumeBackupCodes(): void
    {
        $result = $this->service->generateBackupCodes();
        $this->assertArrayHasKey('hashes', $result);
        $this->assertArrayHasKey('plaintext', $result);
        $this->assertCount(8, $result['hashes']);
        $this->assertCount(8, $result['plaintext']);

        $codes = $result['hashes'];
        $plain = $result['plaintext'][0];

        // First verification should succeed
        $this->assertTrue($this->service->verifyAndConsumeBackupCode($codes, $plain));
        // Same code used a second time must fail
        $this->assertFalse($this->service->verifyAndConsumeBackupCode($codes, $plain));
        // Code with lowercase and no hyphens should also match the second plaintext code
        $secondPlain = strtolower(str_replace('-', '', $result['plaintext'][1]));
        $this->assertTrue($this->service->verifyAndConsumeBackupCode($codes, $secondPlain));
        $this->assertFalse($this->service->verifyAndConsumeBackupCode($codes, $secondPlain));
    }

    public function testVerifyBackupCodeRejectsOversizedAndMalformedInputs(): void
    {
        $result = $this->service->generateBackupCodes();
        $codes = $result['hashes'];

        $this->assertFalse($this->service->verifyAndConsumeBackupCode($codes, str_repeat('A', 100)));
        $this->assertFalse($this->service->verifyAndConsumeBackupCode($codes, '***---@@@'));
        $this->assertFalse($this->service->verifyAndConsumeBackupCode($codes, ''));
    }

    public function testVerifyEmailCodeConstantTime(): void
    {
        $expires = time() + 300;
        $this->assertTrue($this->service->verifyEmailCode('123456', $expires, '123456'));
        $this->assertFalse($this->service->verifyEmailCode('123456', $expires, '654321'));
        $this->assertFalse($this->service->verifyEmailCode('123456', time() - 10, '123456')); // expired
        $this->assertFalse($this->service->verifyEmailCode('123456', $expires, '12345')); // too short
        $this->assertFalse($this->service->verifyEmailCode(null, $expires, '123456'));
    }

    public function testVerifyEmailHash(): void
    {
        $code = '482910';
        $hash = hash('sha256', $code);

        $this->assertTrue($this->service->verifyEmailHash($hash, $code));
        $this->assertFalse($this->service->verifyEmailHash($hash, '000000'));
        $this->assertFalse($this->service->verifyEmailHash($hash, str_repeat('X', 100)));
        $this->assertFalse($this->service->verifyEmailHash(null, $code));
    }

    public function testFormatBackupCodesForDisplay(): void
    {
        $codes = [
            ['hash' => 'dummy1', 'used' => false],
            ['hash' => 'dummy2', 'used' => true],
        ];
        $display = $this->service->formatBackupCodesForDisplay($codes);
        $this->assertSame(['****-****', 'USED'], $display);
    }
}
