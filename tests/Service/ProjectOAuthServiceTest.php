<?php

namespace App\Tests\Service;

use App\Entity\EndUser;
use App\Entity\Project;
use App\Entity\ProjectAuthAuthorizationCode;
use App\Entity\ProjectAuthClient;
use App\Entity\ProjectAuthRefreshToken;
use App\Repository\ProjectAuthAuthorizationCodeRepository;
use App\Repository\ProjectAuthClientRepository;
use App\Repository\ProjectAuthRefreshTokenRepository;
use App\Service\EndUserJwtService;
use App\Service\ProjectOAuthService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class ProjectOAuthServiceTest extends TestCase
{
    private EntityManagerInterface $em;
    private ProjectAuthClientRepository $clientRepository;
    private ProjectAuthAuthorizationCodeRepository $codeRepository;
    private ProjectAuthRefreshTokenRepository $refreshTokenRepository;
    private EndUserJwtService $jwtService;
    private ProjectOAuthService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->clientRepository = $this->createMock(ProjectAuthClientRepository::class);
        $this->codeRepository = $this->createMock(ProjectAuthAuthorizationCodeRepository::class);
        $this->refreshTokenRepository = $this->createMock(ProjectAuthRefreshTokenRepository::class);
        $this->jwtService = $this->createMock(EndUserJwtService::class);

        $this->service = new ProjectOAuthService(
            $this->em,
            $this->clientRepository,
            $this->codeRepository,
            $this->refreshTokenRepository,
            $this->jwtService
        );
    }

    public function testValidateClientThrowsOnInvalidId(): void
    {
        $this->clientRepository->method('findActiveByClientId')->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid or inactive OAuth2 client_id.');

        $this->service->validateClient('bad_id');
    }

    public function testValidateClientConfidentialChecksSecret(): void
    {
        $client = new ProjectAuthClient();
        $client->isConfidential = true;
        $client->clientSecretHash = password_hash('correct_secret', PASSWORD_DEFAULT);

        $this->clientRepository->method('findActiveByClientId')->willReturn($client);

        // Missing secret
        try {
            $this->service->validateClient('client_id', null);
            $this->fail('Expected exception for missing secret');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Client secret required', $e->getMessage());
        }

        // Wrong secret
        try {
            $this->service->validateClient('client_id', 'wrong_secret');
            $this->fail('Expected exception for wrong secret');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid client_secret', $e->getMessage());
        }

        // Correct secret
        $validated = $this->service->validateClient('client_id', 'correct_secret');
        $this->assertSame($client, $validated);
    }

    public function testCreateAuthorizationCodeThrowsOnDisallowedRedirect(): void
    {
        $client = new ProjectAuthClient();
        $client->redirectUris = ['https://myapp.com/callback'];

        $user = new EndUser(new Project(), 'test@example.com');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Redirect URI is not allowed');

        $this->service->createAuthorizationCode($client, $user, 'https://evil.com/callback', ['openid']);
    }

    public function testExchangeCodeWithPkceS256Success(): void
    {
        $client = new ProjectAuthClient();
        $client->id = 1;

        $user = new EndUser(new Project(), 'test@example.com');

        $verifier = 'high_entropy_random_string_1234567890_abcdef';
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $authCode = new ProjectAuthAuthorizationCode();
        $authCode->client = $client;
        $authCode->endUser = $user;
        $authCode->redirectUri = 'https://myapp.com/callback';
        $authCode->scopes = ['openid', 'profile'];
        $authCode->codeChallenge = $challenge;
        $authCode->codeChallengeMethod = 'S256';

        $this->codeRepository->method('findValidCode')->willReturn($authCode);
        $this->jwtService->method('createAccessToken')->willReturn('fake.jwt.token');

        $tokens = $this->service->exchangeAuthorizationCode(
            $client,
            $authCode->code,
            'https://myapp.com/callback',
            $verifier
        );

        $this->assertEquals('Bearer', $tokens['token_type']);
        $this->assertEquals('fake.jwt.token', $tokens['access_token']);
        $this->assertNotEmpty($tokens['refresh_token']);
        $this->assertTrue($authCode->isUsed);
    }

    public function testExchangeCodeFailsWithInvalidPkceVerifier(): void
    {
        $client = new ProjectAuthClient();
        $client->id = 1;

        $user = new EndUser(new Project(), 'test@example.com');

        $authCode = new ProjectAuthAuthorizationCode();
        $authCode->client = $client;
        $authCode->endUser = $user;
        $authCode->redirectUri = 'https://myapp.com/callback';
        $authCode->codeChallenge = 'valid_challenge_hash';
        $authCode->codeChallengeMethod = 'S256';

        $this->codeRepository->method('findValidCode')->willReturn($authCode);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid code_verifier for PKCE challenge.');

        $this->service->exchangeAuthorizationCode(
            $client,
            $authCode->code,
            'https://myapp.com/callback',
            'wrong_verifier'
        );
    }

    public function testRefreshAccessTokenRotatesToken(): void
    {
        $client = new ProjectAuthClient();
        $client->id = 5;

        $user = new EndUser(new Project(), 'test@example.com');

        $existingToken = new ProjectAuthRefreshToken();
        $existingToken->client = $client;
        $existingToken->endUser = $user;
        $existingToken->scopes = ['openid'];

        $this->refreshTokenRepository->method('findValidToken')->willReturn($existingToken);
        $this->jwtService->method('createAccessToken')->willReturn('new.access.token');

        $tokens = $this->service->refreshAccessToken($client, 'valid_plain_token');

        $this->assertTrue($existingToken->isRevoked);
        $this->assertEquals('new.access.token', $tokens['access_token']);
        $this->assertNotEmpty($tokens['refresh_token']);
    }
}
