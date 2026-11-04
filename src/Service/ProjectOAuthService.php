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
use App\Repository\ProjectAuthSessionRepository;
use Doctrine\ORM\EntityManagerInterface;

class ProjectOAuthService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectAuthClientRepository $clientRepository,
        private readonly ProjectAuthAuthorizationCodeRepository $codeRepository,
        private readonly ProjectAuthRefreshTokenRepository $refreshTokenRepository,
        private readonly EndUserJwtService $jwtService,
        private readonly ?ProjectAuthSessionRepository $sessionRepository = null,
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
        $refreshToken->familyId = bin2hex(random_bytes(16));

        $this->em->persist($refreshToken);
        $this->em->flush();

        $expiresIn = EndUserJwtService::resolveTtl($code->endUser->project?->jwtAccessTtl, EndUserJwtService::DEFAULT_ACCESS_TTL);

        return [
            'token_type'    => 'Bearer',
            'expires_in'    => $expiresIn,
            'access_token'  => $accessToken,
            'refresh_token' => $plainRefreshToken,
            'scope'         => implode(' ', $code->scopes),
        ];
    }

    public function refreshAccessToken(ProjectAuthClient $client, string $plainRefreshToken): array
    {
        $token = $this->refreshTokenRepository->findValidToken($plainRefreshToken);
        if (!$token) {
            // Détection de réutilisation de Refresh Token (OAuth 2.0 Security BCP - RFC 6819)
            $compromised = $this->refreshTokenRepository->findByTokenHash($plainRefreshToken);
            if ($compromised !== null && $compromised->isRevoked) {
                // Révocation de la famille de tokens et de toutes les sessions
                if ($compromised->familyId !== null) {
                    $this->refreshTokenRepository->revokeFamily($compromised->familyId);
                }
                $this->revokeAllTokensForEndUser($compromised->endUser);
                throw new \InvalidArgumentException('Refresh token reuse detected. All tokens and sessions have been revoked.');
            }

            throw new \InvalidArgumentException('Invalid or expired refresh token.');
        }

        if ($token->client->id !== $client->id) {
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
        $newToken->familyId = $token->familyId ?? bin2hex(random_bytes(16));
        $newToken->parentTokenHash = $token->tokenHash;

        $this->em->persist($newToken);
        $this->em->flush();

        $expiresIn = EndUserJwtService::resolveTtl($token->endUser->project?->jwtAccessTtl, EndUserJwtService::DEFAULT_ACCESS_TTL);

        return [
            'token_type'    => 'Bearer',
            'expires_in'    => $expiresIn,
            'access_token'  => $accessToken,
            'refresh_token' => $newPlainRefreshToken,
            'scope'         => implode(' ', $token->scopes),
        ];
    }

    /**
     * Révoque de manière proactive l'ensemble des refresh tokens et sessions d'un EndUser.
     */
    public function revokeAllTokensForEndUser(EndUser $endUser): void
    {
        $this->refreshTokenRepository->revokeAllForEndUser($endUser);
        $this->sessionRepository?->revokeAllForEndUser($endUser);
        $endUser->tokenVersion++;
        $this->em->flush();
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
