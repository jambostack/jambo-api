<?php

namespace App\Controller;

use App\Entity\EndUser;
use App\Repository\EndUserRepository;
use App\Repository\ProjectRepository;
use App\Service\EndUserJwtService;
use App\Service\ProjectAuthLockoutService;
use App\Service\ProjectOAuthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/projects/{projectUuid}/oauth', name: 'api_project_oauth_')]
class ProjectOAuthController extends AbstractController
{
    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly EndUserRepository $endUserRepository,
        private readonly ProjectOAuthService $oauthService,
        private readonly EndUserJwtService $jwtService,
        private readonly ProjectAuthLockoutService $lockoutService,
    ) {}

    #[Route('/token', name: 'token', methods: ['POST'])]
    public function token(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return new JsonResponse(['error' => 'invalid_client', 'error_description' => 'Project not found.'], 404);
        }

        $clientId = $request->request->get('client_id') ?? $request->query->get('client_id');
        $clientSecret = $request->request->get('client_secret') ?? $request->query->get('client_secret');
        $grantType = $request->request->get('grant_type') ?? $request->query->get('grant_type');

        // Vérifier protection lockout sur l'IP cliente
        $clientIp = $request->getClientIp() ?? 'unknown';
        if ($this->lockoutService->isLockedOut($clientIp)) {
            $remaining = $this->lockoutService->getRemainingLockoutSeconds($clientIp);
            return new JsonResponse([
                'error' => 'slow_down',
                'error_description' => "Too many failed attempts. Locked out for {$remaining} seconds.",
            ], 429);
        }

        try {
            $client = $this->oauthService->validateClient((string) $clientId, $clientSecret);

            if ($grantType === 'authorization_code') {
                $code = (string) $request->request->get('code');
                $redirectUri = (string) $request->request->get('redirect_uri');
                $codeVerifier = $request->request->get('code_verifier');

                $tokens = $this->oauthService->exchangeAuthorizationCode($client, $code, $redirectUri, $codeVerifier);
                $this->lockoutService->clearAttempts($clientIp);
                return new JsonResponse($tokens);
            }

            if ($grantType === 'refresh_token') {
                $refreshToken = (string) $request->request->get('refresh_token');
                $tokens = $this->oauthService->refreshAccessToken($client, $refreshToken);
                $this->lockoutService->clearAttempts($clientIp);
                return new JsonResponse($tokens);
            }

            return new JsonResponse([
                'error' => 'unsupported_grant_type',
                'error_description' => "Grant type '{$grantType}' is not supported.",
            ], 400);

        } catch (\Throwable $e) {
            $this->lockoutService->recordFailedAttempt($clientIp);
            return new JsonResponse([
                'error' => 'invalid_grant',
                'error_description' => $e->getMessage(),
            ], 400);
        }
    }

    #[Route('/userinfo', name: 'userinfo', methods: ['GET', 'POST'])]
    public function userinfo(string $projectUuid, Request $request): JsonResponse
    {
        $authHeader = $request->headers->get('Authorization', '');
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return new JsonResponse(['error' => 'invalid_token', 'error_description' => 'Bearer token required.'], 401);
        }

        $token = substr($authHeader, 7);
        $claims = $this->jwtService->validateToken($token);
        if ($claims === null) {
            return new JsonResponse(['error' => 'invalid_token', 'error_description' => 'Invalid or expired token.'], 401);
        }

        $userId = $claims['sub'] ?? null;
        $endUser = $userId ? $this->endUserRepository->find($userId) : null;
        if (!$endUser) {
            return new JsonResponse(['error' => 'user_not_found', 'error_description' => 'User no longer exists.'], 404);
        }

        return new JsonResponse([
            'sub'            => (string) $endUser->uuid?->toRfc4122(),
            'email'          => $endUser->email,
            'email_verified' => $endUser->emailVerifiedAt !== null,
            'name'           => $endUser->username ?? $endUser->email,
            'picture'        => $endUser->avatarUrl,
            'created_at'     => $endUser->createdAt->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/revoke', name: 'revoke', methods: ['POST'])]
    public function revoke(string $projectUuid, Request $request): JsonResponse
    {
        $clientId = (string) $request->request->get('client_id');
        $clientSecret = $request->request->get('client_secret');
        $token = (string) $request->request->get('token');

        try {
            $client = $this->oauthService->validateClient($clientId, $clientSecret);
            $this->oauthService->revokeToken($client, $token);
            return new JsonResponse(['status' => 'revoked']);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'invalid_request', 'error_description' => $e->getMessage()], 400);
        }
    }
}
