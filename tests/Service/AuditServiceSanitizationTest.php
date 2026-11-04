<?php

namespace App\Tests\Service;

use App\Entity\AuditLog;
use App\Service\AuditService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class AuditServiceSanitizationTest extends TestCase
{
    private AuditService $service;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->service = new AuditService($this->em);
    }

    public function testSanitizesDirectAndNestedSensitiveFields(): void
    {
        $input = [
            'username' => 'alice',
            'password' => 'superSecretPassword123!',
            'user_password' => 'anotherSecret',
            'api_key' => 'live_sk_1234567890abcdef',
            'client_secret' => 'oauth_secret_xyz',
            'access_token' => 'jwt.token.here',
            'refresh_token' => 'refresh_token_abc',
            'nested' => [
                'auth' => [
                    'bearer' => 'Bearer token_value',
                    'private_key' => '-----BEGIN RSA PRIVATE KEY-----',
                    'pin' => '1234',
                ],
                'safe_field' => 'visible_value',
            ],
            'normal_data' => [
                'title' => 'Article title',
                'views' => 42,
            ],
        ];

        $log = $this->service->log(
            toolName: 'test_tool',
            project: null,
            input: $input,
            output: ['status' => 'ok'],
        );

        $sanitized = $log->input;

        $this->assertSame('alice', $sanitized['username']);
        $this->assertSame('[REDACTED]', $sanitized['password']);
        $this->assertSame('[REDACTED]', $sanitized['user_password']);
        $this->assertSame('[REDACTED]', $sanitized['api_key']);
        $this->assertSame('[REDACTED]', $sanitized['client_secret']);
        $this->assertSame('[REDACTED]', $sanitized['access_token']);
        $this->assertSame('[REDACTED]', $sanitized['refresh_token']);

        // Check nested sanitization
        $this->assertSame('[REDACTED]', $sanitized['nested']['auth']['bearer']);
        $this->assertSame('[REDACTED]', $sanitized['nested']['auth']['private_key']);
        $this->assertSame('[REDACTED]', $sanitized['nested']['auth']['pin']);
        $this->assertSame('visible_value', $sanitized['nested']['safe_field']);

        // Check normal data preserved
        $this->assertSame('Article title', $sanitized['normal_data']['title']);
        $this->assertSame(42, $sanitized['normal_data']['views']);
    }

    public function testHandlesNullInput(): void
    {
        $log = $this->service->log(
            toolName: 'test_tool',
            project: null,
            input: null,
            output: null,
        );

        $this->assertNull($log->input);
        $this->assertNull($log->output);
    }
}
