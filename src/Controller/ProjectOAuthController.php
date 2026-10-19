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
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
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
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    #[Route('/authorize', name: 'authorize', methods: ['GET', 'POST'])]
    public function authorize(string $projectUuid, Request $request): Response
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return new JsonResponse(['error' => 'invalid_client', 'error_description' => 'Project not found.'], 404);
        }

        $clientId = $this->getRequestParam($request, 'client_id');
        $redirectUri = (string) $this->getRequestParam($request, 'redirect_uri', '');
        $responseType = $this->getRequestParam($request, 'response_type');
        $state = $this->getRequestParam($request, 'state');
        $scope = (string) $this->getRequestParam($request, 'scope', 'openid profile email');
        $codeChallenge = $this->getRequestParam($request, 'code_challenge');
        $codeChallengeMethod = (string) $this->getRequestParam($request, 'code_challenge_method', 'S256');

        if (!$clientId) {
            return new JsonResponse(['error' => 'invalid_request', 'error_description' => 'Missing client_id.'], 400);
        }

        try {
            $client = $this->oauthService->validateClient((string) $clientId);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'invalid_client', 'error_description' => $e->getMessage()], 400);
        }

        if ($client->project->id !== $project->id) {
            return new JsonResponse(['error' => 'invalid_client', 'error_description' => 'Client does not belong to project.'], 400);
        }

        if (!$client->isRedirectUriAllowed($redirectUri)) {
            return new JsonResponse(['error' => 'invalid_request', 'error_description' => 'Redirect URI is not allowed for this client.'], 400);
        }

        if ($responseType !== 'code') {
            $sep = str_contains($redirectUri, '?') ? '&' : '?';
            $errUrl = $redirectUri . $sep . http_build_query(array_filter([
                'error' => 'unsupported_response_type',
                'error_description' => "Response type '{$responseType}' is not supported. Use 'code'.",
                'state' => $state,
            ]));
            return new RedirectResponse($errUrl);
        }

        // Check if an EndUser is already authenticated via Bearer token
        $endUser = null;
        $authHeader = $request->headers->get('Authorization', '');
        if (str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
            $claims = $this->jwtService->validateToken($token);
            if ($claims && isset($claims['sub'])) {
                $candidate = $this->endUserRepository->find($claims['sub']);
                if ($candidate && $candidate->project->id === $project->id && $candidate->isActive()) {
                    $endUser = $candidate;
                }
            }
        }

        // Handle POST credentials or consent actions
        $loginError = null;
        if ($request->isMethod('POST')) {
            $action = $request->request->get('action', 'authorize');
            if ($action === 'deny') {
                $sep = str_contains($redirectUri, '?') ? '&' : '?';
                $denyUrl = $redirectUri . $sep . http_build_query(array_filter([
                    'error' => 'access_denied',
                    'error_description' => 'The user denied the authorization request.',
                    'state' => $state,
                ]));
                return new RedirectResponse($denyUrl);
            }

            if ($endUser === null) {
                $email = trim((string) $request->request->get('email', ''));
                $password = (string) $request->request->get('password', '');

                if ($email !== '' && $password !== '') {
                    $candidate = $this->endUserRepository->findOneByProjectAndEmail($project, $email);
                    if ($candidate && $this->passwordHasher->isPasswordValid($candidate, $password)) {
                        if ($candidate->isActive()) {
                            $endUser = $candidate;
                        } else {
                            $loginError = 'Account is ' . $candidate->status . '.';
                        }
                    } else {
                        $loginError = 'Invalid credentials.';
                    }
                } else {
                    $loginError = 'Email and password are required.';
                }
            }
        }

        // Once EndUser is authenticated and approved, generate authorization code
        if ($endUser !== null) {
            $scopes = array_values(array_filter(preg_split('/\s+/', trim($scope))));
            try {
                $code = $this->oauthService->createAuthorizationCode(
                    client: $client,
                    endUser: $endUser,
                    redirectUri: $redirectUri,
                    scopes: $scopes,
                    codeChallenge: $codeChallenge ? (string) $codeChallenge : null,
                    codeChallengeMethod: $codeChallengeMethod ? (string) $codeChallengeMethod : 'S256',
                );

                $sep = str_contains($redirectUri, '?') ? '&' : '?';
                $params = ['code' => $code];
                if ($state !== null && $state !== '') {
                    $params['state'] = $state;
                }
                $redirectUrl = $redirectUri . $sep . http_build_query($params);

                if ($request->getContentTypeFormat() === 'json' || $request->query->get('format') === 'json') {
                    return new JsonResponse([
                        'redirect_url' => $redirectUrl,
                        'code' => $code,
                        'state' => $state,
                    ]);
                }

                return new RedirectResponse($redirectUrl);
            } catch (\Throwable $e) {
                return new JsonResponse(['error' => 'server_error', 'error_description' => $e->getMessage()], 500);
            }
        }

        // Return JSON 401 for headless API clients requiring login
        if ($request->getContentTypeFormat() === 'json' || $request->query->get('format') === 'json') {
            return new JsonResponse([
                'error' => 'login_required',
                'error_description' => $loginError ?? 'End-user authentication is required.',
                'client_name' => $client->name,
                'scopes' => $client->allowedScopes,
            ], 401);
        }

        // Render HTML authorization & consent form
        return $this->render('oauth/authorize.html.twig', [
            'project'               => $project,
            'client'                => $client,
            'redirect_uri'          => $redirectUri,
            'state'                 => $state,
            'scope'                 => $scope,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'login_error'           => $loginError,
        ]);
    }

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

    private function getRequestParam(Request $request, string $key, ?string $default = null): ?string
    {
        $val = $request->request->get($key) ?? $request->query->get($key);
        return $val !== null ? (string) $val : $default;
    }
}
