<?php

namespace App\Tests\Security\Oidc;

use App\Dto\OidcConfig;
use App\Security\Oidc\OidcProviderManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OidcProviderManagerTest extends KernelTestCase
{
    private OidcProviderManager $manager;
    private Session $session;

    protected function setUp(): void
    {
        self::bootKernel();
        $requestStack = new RequestStack();
        $this->session = new Session(new MockArraySessionStorage());
        $request = Request::create('/');
        $request->setSession($this->session);
        $requestStack->push($request);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $appSecret = self::getContainer()->getParameter('kernel.secret');

        $this->manager = new OidcProviderManager($httpClient, $requestStack, $appSecret);
    }

    public function testGenerateStateProducesValidJwt(): void
    {
        $result = $this->manager->generateState('admin', 'provider-uuid', null);
        $this->assertArrayHasKey('state', $result);
        $this->assertArrayHasKey('nonce', $result);
        $this->assertArrayHasKey('codeVerifier', $result);
        $this->assertNotEmpty($result['state']);
        $this->assertNotEmpty($result['nonce']);
        $this->assertEquals(64, strlen($result['nonce'])); // 32 bytes hex

        $payload = $this->manager->validateState($result['state']);
        $this->assertEquals('admin', $payload['type']);
        $this->assertEquals('provider-uuid', $payload['providerId']);
        $this->assertEquals($result['nonce'], $payload['nonce']);
    }

    public function testStatePayloadForEndUser(): void
    {
        $result = $this->manager->generateState('end_user', null, 'abc-def-123');
        $payload = $this->manager->validateState($result['state']);
        $this->assertEquals('end_user', $payload['type']);
        $this->assertEquals('abc-def-123', $payload['projectUuid']);
    }

    public function testValidateStateDetectsExpiredToken(): void
    {
        // Créer un state avec TTL 0 pour qu'il expire immédiatement
        $payload = ['type' => 'admin', 'providerId' => 'x', 'nonce' => 'test', 'exp' => time() - 1];
        $jwt = $this->manager->signPayload($payload);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('expired');
        $this->manager->validateState($jwt);
    }

    public function testValidateStateDetectsTamperedToken(): void
    {
        $result = $this->manager->generateState('admin', 'p1', null);
        $tampered = $result['state'].'x';

        $this->expectException(\RuntimeException::class);
        $this->manager->validateState($tampered);
    }

    public function testPkceCodeChallengeIsS256(): void
    {
        $result = $this->manager->generateState('admin', 'p1', null);
        $verifier = $result['codeVerifier'];
        // SHA-256 en base64url = 43 caractères
        $challenge = $this->manager->computeCodeChallenge($verifier);
        $this->assertEquals(43, strlen($challenge));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $challenge);
    }

    public function testBuildAuthorizationUrl(): void
    {
        $config = new OidcConfig(
            issuer: 'https://accounts.example.com',
            authorizationEndpoint: 'https://accounts.example.com/authorize',
            tokenEndpoint: 'https://accounts.example.com/token',
            userinfoEndpoint: 'https://accounts.example.com/userinfo',
        );

        $url = $this->manager->buildAuthorizationUrl(
            $config,
            'https://jambo.test/oidc/check',
            'test-challenge',
            'test-state',
            'test-nonce',
        );

        $this->assertStringStartsWith('https://accounts.example.com/authorize', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('code_challenge=test-challenge', $url);
        $this->assertStringContainsString('code_challenge_method=S256', $url);
        $this->assertStringContainsString('state=test-state', $url);
        $this->assertStringContainsString('nonce=test-nonce', $url);
    }
}
