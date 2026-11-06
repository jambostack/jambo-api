<?php

namespace App\Controller;

use App\Entity\EndUser;
use App\Entity\Project;
use App\Repository\EndUserRepository;
use App\Repository\ProjectRepository;
use App\Service\AuditService;
use App\Service\EndUserJwtService;
use App\Service\ProjectAuthLockoutService;
use App\Service\ProjectOAuthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/v1/projects/{projectUuid}/oauth', name: 'api_project_oauth_')]
class ProjectOAuthController extends AbstractController
{
    private const SECURITY_HEADERS = [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        'Pragma' => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'no-referrer',
    ];

    public function __construct(
        private readonly ProjectRepository $projectRepository,
        private readonly EndUserRepository $endUserRepository,
        private readonly ProjectOAuthService $oauthService,
        private readonly EndUserJwtService $jwtService,
        private readonly ProjectAuthLockoutService $lockoutService,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ?TranslatorInterface $translator = null,
        #[Autowire(service: 'limiter.login_limiter')]
        private readonly ?RateLimiterFactory $loginLimiter = null,
        private readonly ?AuditService $auditService = null,
    ) {}

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    private function resolveProject(string $projectUuid): ?Project
    {
        if (strlen($projectUuid) > 64 || !preg_match('/^[a-zA-Z0-9_\-]{3,64}$/', $projectUuid)) {
            return null;
        }
        try {
            return $this->projectRepository->findOneBy(['uuid' => $projectUuid]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function validateContentType(Request $request): ?JsonResponse
    {
        $contentType = (string) $request->headers->get('Content-Type', '');
        if ($contentType !== '' && !str_starts_with(strtolower($contentType), 'application/json') && !str_starts_with(strtolower($contentType), 'application/x-www-form-urlencoded')) {
            return $this->secureJson([
                'error' => 'invalid_request',
                'error_description' => $this->trans('project_oauth.error.invalid_content_type', [], 'Invalid Content-Type header, application/json or application/x-www-form-urlencoded expected.'),
            ], 415);
        }
        return null;
    }

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters);
        }
        return $default !== null ? strtr($default, $parameters) : $id;
    }

    #[Route('/authorize', name: 'authorize', methods: ['GET', 'POST'])]
    public function authorize(string $projectUuid, Request $request): Response
    {
        if (strlen($request->getContent()) > 65536) {
            return $this->secureJson([
                'error' => 'invalid_request',
                'error_description' => $this->trans('project_oauth.error.payload_too_large', [], 'Request payload too large (max 64KB).'),
            ], 413);
        }

        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return $this->secureJson(['error' => 'invalid_client', 'error_description' => $this->trans('project_oauth.error.project_not_found', [], 'Project not found.')], 404);
        }

        $clientId = $this->getRequestParam($request, 'client_id');
        $redirectUri = (string) $this->getRequestParam($request, 'redirect_uri', '');
        $responseType = $this->getRequestParam($request, 'response_type');
        $state = $this->getRequestParam($request, 'state');
        $scope = (string) $this->getRequestParam($request, 'scope', 'openid profile email');
        $codeChallenge = $this->getRequestParam($request, 'code_challenge');
        $codeChallengeMethod = (string) $this->getRequestParam($request, 'code_challenge_method', 'S256');

        if (!$clientId) {
            return $this->secureJson(['error' => 'invalid_request', 'error_description' => $this->trans('project_oauth.error.missing_client_id', [], 'Missing client_id.')], 400);
        }

        try {
            $client = $this->oauthService->validateClient((string) $clientId);
        } catch (\Throwable $e) {
            $this->auditService?->log('oauth_client_invalid', $project, ['client_id' => $clientId], null, 'failed', $e->getMessage(), $request->getClientIp(), 'project_oauth');
            $this->auditService?->flush();
            return $this->secureJson(['error' => 'invalid_client', 'error_description' => $e->getMessage() ?: $this->trans('project_oauth.error.invalid_client', [], 'Client authentication failed or invalid client credentials.')], 400);
        }

        if ($client->project->id !== $project->id) {
            $this->auditService?->log('oauth_client_mismatch', $project, ['client_id' => $clientId], null, 'blocked', 'Client does not belong to project.', $request->getClientIp(), 'project_oauth');
            $this->auditService?->flush();
            return $this->secureJson(['error' => 'invalid_client', 'error_description' => $this->trans('project_oauth.error.client_not_in_project', [], 'Client does not belong to project.')], 400);
        }

        if (!$client->isRedirectUriAllowed($redirectUri)) {
            $this->auditService?->log('oauth_redirect_uri_rejected', $project, ['client_id' => $clientId, 'redirect_uri' => $redirectUri], null, 'blocked', 'Redirect URI is not allowed for this client.', $request->getClientIp(), 'project_oauth');
            $this->auditService?->flush();
            return $this->secureJson(['error' => 'invalid_request', 'error_description' => $this->trans('project_oauth.error.redirect_uri_not_allowed', [], 'Redirect URI is not allowed for this client.')], 400);
        }

        if ($codeChallenge !== null && $codeChallenge !== '') {
            if (!in_array($codeChallengeMethod, ['S256', 'plain'], true)) {
                return $this->secureJson([
                    'error' => 'invalid_request',
                    'error_description' => $this->trans('project_oauth.error.unsupported_transform_algorithm', ['%algorithm%' => $codeChallengeMethod], "Transform algorithm '{$codeChallengeMethod}' not supported. Use 'S256'."),
                ], 400);
            }
        }

        if ($responseType !== 'code') {
            $sep = str_contains($redirectUri, '?') ? '&' : '?';
            $errUrl = $redirectUri . $sep . http_build_query(array_filter([
                'error' => 'unsupported_response_type',
                'error_description' => $this->trans('project_oauth.error.unsupported_response_type', ['%type%' => (string) $responseType], "Response type '{$responseType}' is not supported. Use 'code'."),
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
            $userId = $claims['sub'] ?? $claims['euid'] ?? null;
            if ($claims && empty($claims['ref']) && empty($claims['tfa']) && $userId !== null) {
                $candidate = is_numeric($userId)
                    ? $this->endUserRepository->find((int) $userId)
                    : $this->endUserRepository->findOneBy(['uuid' => $userId, 'project' => $project]);
                if ($candidate && $candidate->project->id === $project->id && $candidate->isActive()) {
                    $endUser = $candidate;
                }
            }
        }

        // Handle POST credentials or consent actions
        $loginError = null;
        if ($request->isMethod('POST')) {
            if ($this->loginLimiter !== null) {
                $limiter = $this->loginLimiter->create($request->getClientIp() ?: '127.0.0.1');
                if (!$limiter->consume()->isAccepted()) {
                    $msg = $this->trans('project_oauth.error.rate_limited_login', [], 'Too many login attempts. Please retry later.');
                    $this->auditService?->log('oauth_rate_limit_login', $project, ['ip' => $request->getClientIp()], null, 'blocked', 'Rate limit exceeded for OAuth login.', $request->getClientIp(), 'project_oauth');
                    $this->auditService?->flush();
                    if ($request->getContentTypeFormat() === 'json' || $request->query->get('format') === 'json') {
                        return $this->secureJson(['error' => 'slow_down', 'error_description' => $msg], 429);
                    }
                    return $this->render('oauth/authorize.html.twig', [
                        'project'               => $project,
                        'client'                => $client,
                        'redirect_uri'          => $redirectUri,
                        'state'                 => $state,
                        'scope'                 => $scope,
                        'code_challenge'        => $codeChallenge,
                        'code_challenge_method' => $codeChallengeMethod,
                        'login_error'           => $msg,
                    ], new Response(status: 429));
                }
            }

            $action = $request->request->get('action', 'authorize');
            if ($action === 'deny') {
                $this->auditService?->log('oauth_authorize_denied', $project, ['client_id' => $clientId, 'redirect_uri' => $redirectUri], null, 'denied', 'User denied authorization request.', $request->getClientIp(), 'project_oauth');
                $this->auditService?->flush();
                $sep = str_contains($redirectUri, '?') ? '&' : '?';
                $denyUrl = $redirectUri . $sep . http_build_query(array_filter([
                    'error' => 'access_denied',
                    'error_description' => $this->trans('project_oauth.error.access_denied', [], 'The user denied the authorization request.'),
                    'state' => $state,
                ]));
                return new RedirectResponse($denyUrl);
            }

            if ($endUser === null) {
                $email = trim((string) $request->request->get('email', ''));
                $password = (string) $request->request->get('password', '');
                $normalizedEmail = strtolower($email);
                $clientIp = $request->getClientIp() ?: '127.0.0.1';
                $projectUuidStr = $project->uuid?->toString() ?? $projectUuid;

                $accountLockoutKey = 'enduser_login:' . $projectUuidStr . ':' . $normalizedEmail;
                $ipLockoutKey = 'enduser_login_ip:' . $projectUuidStr . ':' . $clientIp;

                if ($this->lockoutService->isLockedOut($ipLockoutKey)) {
                    $retryAfter = $this->lockoutService->getRemainingLockoutSeconds($ipLockoutKey);
                    $loginError = $this->trans('project_oauth.error.ip_locked_out', ['%seconds%' => (string) $retryAfter], "Too many failed attempts from this IP address. Please retry in {$retryAfter} seconds.");
                    $this->auditService?->log('oauth_login_lockout', $project, ['ip' => $clientIp], null, 'blocked', 'IP locked out', $clientIp, 'project_oauth');
                    $this->auditService?->flush();
                    if ($request->getContentTypeFormat() === 'json' || $request->query->get('format') === 'json') {
                        return $this->secureJson(['error' => 'slow_down', 'error_description' => $loginError], 429, ['Retry-After' => (string) $retryAfter]);
                    }
                } elseif ($normalizedEmail !== '' && $this->lockoutService->isLockedOut($accountLockoutKey)) {
                    $retryAfter = $this->lockoutService->getRemainingLockoutSeconds($accountLockoutKey);
                    $loginError = $this->trans('project_oauth.error.account_locked_out', ['%seconds%' => (string) $retryAfter], "Too many failed login attempts. Account temporarily locked for security. Please retry in {$retryAfter} seconds.");
                    $this->auditService?->log('oauth_login_lockout', $project, ['email' => $normalizedEmail, 'ip' => $clientIp], null, 'blocked', 'Account locked out', $clientIp, 'project_oauth');
                    $this->auditService?->flush();
                    if ($request->getContentTypeFormat() === 'json' || $request->query->get('format') === 'json') {
                        return $this->secureJson(['error' => 'slow_down', 'error_description' => $loginError], 429, ['Retry-After' => (string) $retryAfter]);
                    }
                } elseif ($normalizedEmail === '' || $password === '') {
                    $loginError = $this->trans('project_oauth.error.credentials_required', [], 'Email and password are required.');
                } elseif (strlen($normalizedEmail) > 180 || !filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
                    // Timing-safe equalization: dummy password check to eliminate timing leak
                    $dummyUser = new EndUser($project, 'timing-defense@local.internal');
                    $dummyUser->password = '$2y$13$BIHXwdLFZAOYbWPDBT2n6uUEtB81DTDcRMD.a4VGm0SWZJ48TIjt.';
                    $this->passwordHasher->isPasswordValid($dummyUser, $password);
                    $this->lockoutService->recordTieredFailedAttempt($accountLockoutKey);
                    $this->lockoutService->recordTieredFailedAttempt($ipLockoutKey, [10 => 300, 20 => 900, 30 => 3600]);
                    $loginError = $this->trans('project_oauth.error.invalid_credentials', [], 'Invalid credentials.');
                    $this->auditService?->log('oauth_login_failed', $project, ['email' => $normalizedEmail, 'ip' => $clientIp], null, 'failed', 'Invalid credentials format', $clientIp, 'project_oauth');
                    $this->auditService?->flush();
                } else {
                    $candidate = $this->endUserRepository->findOneByProjectAndEmail($project, $normalizedEmail);
                    if ($candidate === null) {
                        $dummyUser = new EndUser($project, 'timing-defense@local.internal');
                        $dummyUser->password = '$2y$13$BIHXwdLFZAOYbWPDBT2n6uUEtB81DTDcRMD.a4VGm0SWZJ48TIjt.';
                        $this->passwordHasher->isPasswordValid($dummyUser, $password);
                        $this->lockoutService->recordTieredFailedAttempt($accountLockoutKey);
                        $this->lockoutService->recordTieredFailedAttempt($ipLockoutKey, [10 => 300, 20 => 900, 30 => 3600]);
                        $loginError = $this->trans('project_oauth.error.invalid_credentials', [], 'Invalid credentials.');
                        $this->auditService?->log('oauth_login_failed', $project, ['email' => $normalizedEmail, 'ip' => $clientIp], null, 'failed', 'Invalid credentials: user not found', $clientIp, 'project_oauth');
                        $this->auditService?->flush();
                    } elseif (!$this->passwordHasher->isPasswordValid($candidate, $password)) {
                        $this->lockoutService->recordTieredFailedAttempt($accountLockoutKey);
                        $this->lockoutService->recordTieredFailedAttempt($ipLockoutKey, [10 => 300, 20 => 900, 30 => 3600]);
                        $loginError = $this->trans('project_oauth.error.invalid_credentials', [], 'Invalid credentials.');
                        $this->auditService?->log('oauth_login_failed', $project, ['email' => $normalizedEmail, 'ip' => $clientIp], null, 'failed', 'Invalid credentials: bad password', $clientIp, 'project_oauth');
                        $this->auditService?->flush();
                    } elseif (!$candidate->isActive()) {
                        $loginError = $this->trans('project_oauth.error.account_inactive', [], 'Account is inactive or banned.');
                        $this->auditService?->log('oauth_login_inactive', $project, ['email' => $normalizedEmail, 'ip' => $clientIp], null, 'blocked', 'Account inactive', $clientIp, 'project_oauth');
                        $this->auditService?->flush();
                    } else {
                        $this->lockoutService->clearAttempts($accountLockoutKey);
                        $endUser = $candidate;
                    }
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

                $this->auditService?->log(
                    'oauth_code_issued',
                    $project,
                    ['client_id' => $client->id, 'user_id' => $endUser->id, 'scope' => $scope],
                    null,
                    'success',
                    null,
                    (string) ($endUser->email ?? $endUser->id),
                    'project_oauth'
                );
                $this->auditService?->flush();

                if ($request->getContentTypeFormat() === 'json' || $request->query->get('format') === 'json') {
                    return $this->secureJson([
                        'redirect_url' => $redirectUrl,
                        'code' => $code,
                        'state' => $state,
                    ]);
                }

                return new RedirectResponse($redirectUrl);
            } catch (\Throwable $e) {
                $this->auditService?->log('oauth_code_failed', $project, ['client_id' => $client->id], null, 'failed', $e->getMessage(), $request->getClientIp(), 'project_oauth');
                $this->auditService?->flush();
                return $this->secureJson(['error' => 'server_error', 'error_description' => $e->getMessage() ?: $this->trans('project_oauth.error.server_error', [], 'An internal server error occurred during authorization.')], 500);
            }
        }

        // Return JSON 401 for headless API clients requiring login
        if ($request->getContentTypeFormat() === 'json' || $request->query->get('format') === 'json') {
            return $this->secureJson([
                'error' => 'login_required',
                'error_description' => $loginError ?? $this->trans('project_oauth.error.login_required', [], 'End-user authentication is required.'),
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
        if (strlen($request->getContent()) > 65536) {
            return $this->secureJson([
                'error' => 'invalid_request',
                'error_description' => $this->trans('project_oauth.error.payload_too_large', [], 'Request payload too large (max 64KB).'),
            ], 413);
        }

        if ($contentError = $this->validateContentType($request)) {
            return $contentError;
        }

        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return $this->secureJson(['error' => 'invalid_client', 'error_description' => $this->trans('project_oauth.error.project_not_found', [], 'Project not found.')], 404);
        }

        if ($this->loginLimiter !== null) {
            $limiter = $this->loginLimiter->create($request->getClientIp() ?: '127.0.0.1');
            if (!$limiter->consume()->isAccepted()) {
                $this->auditService?->log('oauth_rate_limit_token', $project, ['ip' => $request->getClientIp()], null, 'blocked', 'Rate limit exceeded for token endpoint', $request->getClientIp(), 'project_oauth');
                $this->auditService?->flush();
                return $this->secureJson([
                    'error' => 'slow_down',
                    'error_description' => $this->trans('project_oauth.error.rate_limited_token', [], 'Too many token exchange attempts. Please retry later.'),
                ], 429);
            }
        }

        $clientId = $this->getRequestParam($request, 'client_id');
        $clientSecret = $this->getRequestParam($request, 'client_secret');
        $grantType = $this->getRequestParam($request, 'grant_type');

        // Vérifier protection lockout sur l'IP cliente
        $clientIp = $request->getClientIp() ?? 'unknown';
        $projectUuidStr = $project->uuid?->toString() ?? $projectUuid;
        $ipLockoutKey = 'oauth_token_ip:' . $projectUuidStr . ':' . $clientIp;

        if ($this->lockoutService->isLockedOut($clientIp) || $this->lockoutService->isLockedOut($ipLockoutKey)) {
            $remaining = max(
                $this->lockoutService->getRemainingLockoutSeconds($clientIp),
                $this->lockoutService->getRemainingLockoutSeconds($ipLockoutKey)
            );
            $this->auditService?->log('oauth_token_lockout', $project, ['ip' => $clientIp, 'client_id' => $clientId], null, 'blocked', 'IP locked out for token requests', $clientIp, 'project_oauth');
            $this->auditService?->flush();
            return $this->secureJson([
                'error' => 'slow_down',
                'error_description' => $this->trans('project_oauth.error.rate_limited', ['%seconds%' => (string) $remaining], "Too many failed attempts. Locked out for {$remaining} seconds."),
            ], 429, ['Retry-After' => (string) $remaining]);
        }

        try {
            $client = $this->oauthService->validateClient((string) $clientId, $clientSecret);

            if ($grantType === 'authorization_code') {
                $code = (string) $this->getRequestParam($request, 'code', '');
                $redirectUri = (string) $this->getRequestParam($request, 'redirect_uri', '');
                $codeVerifier = $this->getRequestParam($request, 'code_verifier');

                $tokens = $this->oauthService->exchangeAuthorizationCode($client, $code, $redirectUri, $codeVerifier);
                $this->lockoutService->clearAttempts($clientIp);
                $this->lockoutService->clearAttempts($ipLockoutKey);
                $this->auditService?->log('oauth_token_issued', $project, ['client_id' => $client->id, 'grant_type' => 'authorization_code'], null, 'success', null, (string) $clientId, 'project_oauth');
                $this->auditService?->flush();
                return $this->secureJson($tokens);
            }

            if ($grantType === 'refresh_token') {
                $refreshToken = (string) $this->getRequestParam($request, 'refresh_token', '');
                $tokens = $this->oauthService->refreshAccessToken($client, $refreshToken);
                $this->lockoutService->clearAttempts($clientIp);
                $this->lockoutService->clearAttempts($ipLockoutKey);
                $this->auditService?->log('oauth_token_issued', $project, ['client_id' => $client->id, 'grant_type' => 'refresh_token'], null, 'success', null, (string) $clientId, 'project_oauth');
                $this->auditService?->flush();
                return $this->secureJson($tokens);
            }

            $this->auditService?->log('oauth_unsupported_grant', $project, ['client_id' => $client->id, 'grant_type' => $grantType], null, 'failed', 'Unsupported grant type', (string) $clientId, 'project_oauth');
            $this->auditService?->flush();
            return $this->secureJson([
                'error' => 'unsupported_grant_type',
                'error_description' => $this->trans('project_oauth.error.unsupported_grant_type', ['%type%' => (string) $grantType], "Grant type '{$grantType}' is not supported."),
            ], 400);

        } catch (\Throwable $e) {
            $this->lockoutService->recordFailedAttempt($clientIp);
            $this->lockoutService->recordTieredFailedAttempt($ipLockoutKey, [10 => 300, 20 => 900, 30 => 3600]);
            $this->auditService?->log('oauth_token_failed', $project, ['client_id' => $clientId, 'grant_type' => $grantType], null, 'failed', $e->getMessage(), (string) $clientId, 'project_oauth');
            $this->auditService?->flush();
            return $this->secureJson([
                'error' => 'invalid_grant',
                'error_description' => $e->getMessage() ?: $this->trans('project_oauth.error.invalid_grant', [], 'Invalid grant or token exchange parameter.'),
            ], 400);
        }
    }

    #[Route('/userinfo', name: 'userinfo', methods: ['GET', 'POST'])]
    public function userinfo(string $projectUuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return $this->secureJson(['error' => 'invalid_client', 'error_description' => $this->trans('project_oauth.error.project_not_found', [], 'Project not found.')], 404);
        }

        if ($this->loginLimiter !== null) {
            $limiter = $this->loginLimiter->create($request->getClientIp() ?: '127.0.0.1');
            if (!$limiter->consume()->isAccepted()) {
                return $this->secureJson([
                    'error' => 'slow_down',
                    'error_description' => $this->trans('project_oauth.error.rate_limited_token', [], 'Too many token exchange attempts. Please retry later.'),
                ], 429);
            }
        }

        $authHeader = $request->headers->get('Authorization', '');
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return $this->secureJson(['error' => 'invalid_token', 'error_description' => $this->trans('project_oauth.error.bearer_required', [], 'Bearer token required.')], 401);
        }

        $token = substr($authHeader, 7);
        $claims = $this->jwtService->validateToken($token);
        if ($claims === null || !empty($claims['ref']) || !empty($claims['tfa'])) {
            return $this->secureJson(['error' => 'invalid_token', 'error_description' => $this->trans('project_oauth.error.invalid_or_expired_token', [], 'Invalid or expired token.')], 401);
        }

        $userId = $claims['sub'] ?? $claims['euid'] ?? null;
        $endUser = null;
        if ($userId !== null) {
            $endUser = is_numeric($userId)
                ? $this->endUserRepository->find((int) $userId)
                : $this->endUserRepository->findOneBy(['uuid' => $userId, 'project' => $project]);
        }
        if (!$endUser) {
            return $this->secureJson(['error' => 'user_not_found', 'error_description' => $this->trans('project_oauth.error.user_not_found', [], 'User no longer exists.')], 404);
        }

        if ($endUser->project->id !== $project->id) {
            $this->auditService?->log('oauth_userinfo_mismatch', $project, ['user_id' => $endUser->id], null, 'blocked', 'Token does not belong to this project', (string) $endUser->id, 'project_oauth');
            $this->auditService?->flush();
            return $this->secureJson(['error' => 'invalid_token', 'error_description' => $this->trans('project_oauth.error.token_mismatch_project', [], 'Token does not belong to this project.')], 403);
        }

        return $this->secureJson([
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
        if (strlen($request->getContent()) > 65536) {
            return $this->secureJson([
                'error' => 'invalid_request',
                'error_description' => $this->trans('project_oauth.error.payload_too_large', [], 'Request payload too large (max 64KB).'),
            ], 413);
        }

        if ($contentError = $this->validateContentType($request)) {
            return $contentError;
        }

        $project = $this->resolveProject($projectUuid);
        if (!$project) {
            return $this->secureJson(['error' => 'invalid_client', 'error_description' => $this->trans('project_oauth.error.project_not_found', [], 'Project not found.')], 404);
        }

        $clientIp = $request->getClientIp() ?: '127.0.0.1';
        $projectUuidStr = $project->uuid?->toString() ?? $projectUuid;
        $revokeIpKey = 'oauth_revoke_ip:' . $projectUuidStr . ':' . $clientIp;

        if ($this->lockoutService->isLockedOut($revokeIpKey)) {
            $remaining = $this->lockoutService->getRemainingLockoutSeconds($revokeIpKey);
            $this->auditService?->log('oauth_revoke_lockout', $project, ['ip' => $clientIp], null, 'blocked', 'IP locked out for token revocation', $clientIp, 'project_oauth');
            $this->auditService?->flush();
            return $this->secureJson([
                'error' => 'slow_down',
                'error_description' => $this->trans('project_oauth.error.revoke_rate_limited', ['%seconds%' => (string) $remaining], "Too many token revocation requests from this IP address. Please retry in {$remaining} seconds."),
            ], 429, ['Retry-After' => (string) $remaining]);
        }

        $clientId = (string) $this->getRequestParam($request, 'client_id', '');
        $clientSecret = $this->getRequestParam($request, 'client_secret');
        $token = (string) $this->getRequestParam($request, 'token', '');

        try {
            $client = $this->oauthService->validateClient($clientId, $clientSecret);
            $this->oauthService->revokeToken($client, $token);
            $this->lockoutService->clearAttempts($revokeIpKey);
            $this->auditService?->log('oauth_token_revoked', $project, ['client_id' => $client->id], null, 'success', null, (string) $clientId, 'project_oauth');
            $this->auditService?->flush();
            return $this->secureJson(['status' => 'revoked']);
        } catch (\Throwable $e) {
            $this->lockoutService->recordTieredFailedAttempt($revokeIpKey, [10 => 300, 20 => 900, 30 => 3600]);
            $this->auditService?->log('oauth_revoke_failed', $project, ['client_id' => $clientId], null, 'failed', $e->getMessage(), (string) $clientId, 'project_oauth');
            $this->auditService?->flush();
            return $this->secureJson(['error' => 'invalid_request', 'error_description' => $e->getMessage() ?: $this->trans('project_oauth.error.revocation_failed', [], 'Token revocation failed.')], 400);
        }
    }

    private function getRequestParam(Request $request, string $key, ?string $default = null): ?string
    {
        $val = $request->request->get($key) ?? $request->query->get($key);
        if ($val === null) {
            try {
                $payload = $request->toArray();
                $val = $payload[$key] ?? null;
            } catch (\Throwable) {}
        }
        return $val !== null ? (string) $val : $default;
    }
}
