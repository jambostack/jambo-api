<?php

namespace App\Service;

use App\Entity\EndUser;
use App\Entity\Project;
use App\Entity\ProjectAuthAuthorizationCode;
use App\Entity\ProjectAuthClient;
use App\Entity\ProjectAuthRefreshToken;
use App\Entity\ProjectAuthSession;
use App\Repository\ProjectAuthAuthorizationCodeRepository;
use App\Repository\ProjectAuthClientRepository;
use App\Repository\ProjectAuthRefreshTokenRepository;
use Doctrine\ORM\EntityManagerInterface;

class ProjectOAuthService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectAuthClientRepository $clientRepository,
        private readonly ProjectAuthAuthorizationCodeRepository $codeRepository,
        private readonly ProjectAuthRefreshTokenRepository $refreshTokenRepository,
        private readonly EndUserJwtService $jwtService,
    ) {}

    public function validateClient(string $clientId, ?string $clientSecret = null): ProjectAuthClient
    {
        $client = $this->clientRepository->findActiveByClientId($clientId);
        if (!$client) {
            throw new \InvalidArgumentException('Invalid or inactive OAuth2 client_id.');
        }

        if ($client->isConfidential) {
            if ($clientSecret === null || $client->clientSecretHash === null) {
                throw new \InvalidArgumentException('Client secret required for confidential client.');
            }
            if (!password_verify($clientSecret, $client->clientSecretHash)) {
                throw new \InvalidArgumentException('Invalid client_secret.');
            }
        }

        return $client;
    }

    public function createAuthorizationCode(
        ProjectAuthClient $client,
        EndUser $endUser,
        string $redirectUri,
        array $scopes,
        ?string $codeChallenge = null,
        ?string $codeChallengeMethod = 'S256',
    ): string {
        if (!$client->isRedirectUriAllowed($redirectUri)) {
            throw new \InvalidArgumentException('Redirect URI is not allowed for this client.');
        }

        $code = new ProjectAuthAuthorizationCode();
        $code->client = $client;
        $code->endUser = $endUser;
        $code->redirectUri = $redirectUri;
        $code->scopes = $scopes;
        $code->codeChallenge = $codeChallenge;
        $code->codeChallengeMethod = $codeChallengeMethod ?? 'S256';

        $this->em->persist($code);
        $this->em->flush();

        return $code->code;
    }

    public function exchangeAuthorizationCode(
        ProjectAuthClient $client,
        string $codeString,
        string $redirectUri,
        ?string $codeVerifier = null,
    ): array {
        $code = $this->codeRepository->findValidCode($codeString);
        if (!$code) {
            throw new \InvalidArgumentException('Invalid or expired authorization code.');
        }

        if ($code->client->id !== $client->id) {
            throw new \InvalidArgumentException('Authorization code does not belong to this client.');
        }

        if ($code->redirectUri !== $redirectUri) {
            throw new \InvalidArgumentException('Redirect URI mismatch.');
        }

        // Vérification PKCE si codeChallenge présent
        if ($code->codeChallenge !== null) {
            if ($codeVerifier === null) {
                throw new \InvalidArgumentException('Code verifier required for PKCE.');
            }
            if (!$this->verifyCodeChallenge($codeVerifier, $code->codeChallenge, $code->codeChallengeMethod ?? 'S256')) {
                throw new \InvalidArgumentException('Invalid code_verifier for PKCE challenge.');
            }
        }

        $code->isUsed = true;

        $accessToken = $this->jwtService->createAccessToken($code->endUser);
        $plainRefreshToken = bin2hex(random_bytes(32));

        $refreshToken = new ProjectAuthRefreshToken();
        $refreshToken->tokenHash = ProjectAuthRefreshToken::hash($plainRefreshToken);
        $refreshToken->client = $client;
        $refreshToken->endUser = $code->endUser;
        $refreshToken->scopes = $code->scopes;

        $this->em->persist($refreshToken);
        $this->em->flush();

        return [
            'token_type'    => 'Bearer',
            'expires_in'    => EndUserJwtService::DEFAULT_ACCESS_TTL,
            'access_token'  => $accessToken,
            'refresh_token' => $plainRefreshToken,
            'scope'         => implode(' ', $code->scopes),
        ];
    }

    public function refreshAccessToken(ProjectAuthClient $client, string $plainRefreshToken): array
    {
        $token = $this->refreshTokenRepository->findValidToken($plainRefreshToken);
        if (!$token || $token->client->id !== $client->id) {
            throw new \InvalidArgumentException('Invalid or expired refresh token.');
        }

        // Rotation du refresh token : invalider l'ancien et émettre un nouveau
        $token->isRevoked = true;

        $accessToken = $this->jwtService->createAccessToken($token->endUser);
        $newPlainRefreshToken = bin2hex(random_bytes(32));

        $newToken = new ProjectAuthRefreshToken();
        $newToken->tokenHash = ProjectAuthRefreshToken::hash($newPlainRefreshToken);
        $newToken->client = $client;
        $newToken->endUser = $token->endUser;
        $newToken->scopes = $token->scopes;

        $this->em->persist($newToken);
        $this->em->flush();

        return [
            'token_type'    => 'Bearer',
            'expires_in'    => EndUserJwtService::DEFAULT_ACCESS_TTL,
            'access_token'  => $accessToken,
            'refresh_token' => $newPlainRefreshToken,
            'scope'         => implode(' ', $token->scopes),
        ];
    }

    public function revokeToken(ProjectAuthClient $client, string $token): void
    {
        $refreshToken = $this->refreshTokenRepository->findValidToken($token);
        if ($refreshToken && $refreshToken->client->id === $client->id) {
            $refreshToken->isRevoked = true;
            $this->em->flush();
        }
    }

    private function verifyCodeChallenge(string $verifier, string $challenge, string $method): bool
    {
        if ($method === 'plain') {
            return hash_equals($challenge, $verifier);
        }

        if ($method === 'S256') {
            $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            return hash_equals($challenge, $computed);
        }

        return false;
    }
}
