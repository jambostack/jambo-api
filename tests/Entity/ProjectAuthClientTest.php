<?php

namespace App\Tests\Entity;

use App\Entity\EndUser;
use App\Entity\Project;
use App\Entity\ProjectAuthAuthorizationCode;
use App\Entity\ProjectAuthClient;
use App\Entity\ProjectAuthRefreshToken;
use App\Entity\ProjectAuthSession;
use PHPUnit\Framework\TestCase;

class ProjectAuthClientTest extends TestCase
{
    public function testClientInstantiationAndDefaults(): void
    {
        $client = new ProjectAuthClient();
        $this->assertNotNull($client->uuid);
        $this->assertStringStartsWith('client_', $client->clientId);
        $this->assertTrue($client->isActive);
        $this->assertFalse($client->isConfidential);
    }

    public function testRedirectUriValidation(): void
    {
        $client = new ProjectAuthClient();
        $client->redirectUris = ['https://myapp.com/callback', 'http://localhost:3000/api/auth'];

        $this->assertTrue($client->isRedirectUriAllowed('https://myapp.com/callback'));
        $this->assertTrue($client->isRedirectUriAllowed('http://localhost:3000/api/auth'));
        $this->assertFalse($client->isRedirectUriAllowed('https://evil.com/callback'));
    }

    public function testScopeValidation(): void
    {
        $client = new ProjectAuthClient();
        $client->allowedScopes = ['openid', 'profile', 'email'];

        $this->assertTrue($client->areScopesAllowed(['openid', 'email']));
        $this->assertFalse($client->areScopesAllowed(['openid', 'admin:write']));
    }

    public function testAuthorizationCodeExpiration(): void
    {
        $code = new ProjectAuthAuthorizationCode();
        $this->assertFalse($code->isExpired());
        $this->assertFalse($code->isUsed);

        $code->expiresAt = (new \DateTimeImmutable())->modify('-1 minute');
        $this->assertTrue($code->isExpired());
    }

    public function testRefreshTokenHash(): void
    {
        $plain = 'secret_refresh_token_123';
        $hash1 = ProjectAuthRefreshToken::hash($plain);
        $hash2 = hash('sha256', $plain);

        $this->assertEquals($hash1, $hash2);
    }

    public function testSessionTouch(): void
    {
        $session = new ProjectAuthSession();
        $initial = $session->lastActiveAt;
        usleep(1000);
        $session->touch();
        $this->assertGreaterThanOrEqual($initial, $session->lastActiveAt);
    }
}
