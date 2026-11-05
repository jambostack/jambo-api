<?php

namespace App\Controller\Api;

use App\Entity\EndUser;
use App\Entity\PasswordResetToken;
use App\Entity\Project;
use App\Entity\ProjectAuthRefreshToken;
use App\Repository\EndUserRepository;
use App\Repository\ProjectAuthRefreshTokenRepository;
use App\Repository\ProjectAuthSessionRepository;
use App\Repository\ProjectRepository;
use App\Service\EndUserCustomFieldsSanitizer;
use App\Service\EndUserJwtService;
use App\Service\ProjectAuthLockoutService;
use App\Service\ProjectMailerService;
use App\Service\Security\PasswordPolicyValidator;
use App\Service\SocialLoginService;
use App\Service\TwoFactorService;
use App\Controller\EndUserSerializerTrait;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[OA\Tag(name: 'End-User Auth')]
#[Route('/api/{projectId}/auth', name: 'enduser_auth_',
    requirements: ['projectId' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'],
    priority: 10)]
class EndUserAuthController extends AbstractController
{
    use EndUserSerializerTrait;

    public function __construct(
        private ProjectRepository $projectRepository,
        private EndUserRepository $endUserRepository,
        private EndUserJwtService $jwtService,
        private TwoFactorService $twoFactorService,
        private UserPasswordHasherInterface $hasher,
        private EntityManagerInterface $em,
        private ?SocialLoginService $socialLogin = null,
        private ?ProjectMailerService $projectMailer = null,
        private ?MailerInterface $mailer = null,
        private ?ProjectAuthRefreshTokenRepository $refreshTokenRepo = null,
        private ?ProjectAuthSessionRepository $sessionRepo = null,
        private ?PasswordPolicyValidator $passwordValidator = null,
        #[Autowire(service: 'limiter.two_factor_limiter')]
        private ?RateLimiterFactory $twoFactorLimiter = null,
        #[Autowire(service: 'limiter.login_limiter')]
        private ?RateLimiterFactory $loginLimiter = null,
        #[Autowire(service: 'limiter.register_limiter')]
        private ?RateLimiterFactory $registerLimiter = null,
        #[Autowire(service: 'limiter.password_reset_limiter')]
        private ?RateLimiterFactory $passwordResetLimiter = null,
        #[Autowire(service: 'limiter.social_auth_limiter')]
        private ?RateLimiterFactory $socialAuthLimiter = null,
        private readonly ?TranslatorInterface $translator = null,
        private ?EndUserCustomFieldsSanitizer $customFieldsSanitizer = null,
        private ?ProjectAuthLockoutService $lockoutService = null,
    ) {
        $this->customFieldsSanitizer ??= new EndUserCustomFieldsSanitizer($this->translator);
    }

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters);
        }
        return $default ?? $id;
    }

    #[OA\Post(
        path: '/api/{projectId}/auth/register',
        summary: 'Register a new end-user',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email', 'password'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email'),
                new OA\Property(property: 'password', type: 'string', minLength: 8),
                new OA\Property(property: 'username', type: 'string', nullable: true),
            ]
        )),
        parameters: [new OA\Parameter(name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'User created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'user', ref: '#/components/schemas/EndUser'),
                    new OA\Property(property: 'access_token', type: 'string'),
                    new OA\Property(property: 'refresh_token', type: 'string'),
                ]),
            ])),
            new OA\Response(response: 409, description: 'Email already registered', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('/register', name: 'register', methods: ['POST'])]
    public function register(Request $request, string $projectId): JsonResponse
    {
        if ($this->registerLimiter !== null) {
            $limiter = $this->registerLimiter->create($request->getClientIp() ?: '127.0.0.1');
            if (!$limiter->consume()->isAccepted()) {
                return new JsonResponse(['error' => $this->trans('enduser.auth.rate_limit_register', [], 'Too many registration requests. Please retry later.')], 429);
            }
        }

        $project = $this->findProject($projectId);
        if (!$project) {
            return $this->json(['error' => $this->trans('enduser.auth.project_not_found', [], 'Project not found')], 404);
        }

        $data = $this->getJsonPayload($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $rawUsername = $data['username'] ?? $data['name'] ?? null;
        $username = null;
        if ($rawUsername !== null && $rawUsername !== '') {
            $cleaned = trim((string) $rawUsername);
            if (mb_strlen($cleaned) > 100 || preg_match('/[\x00-\x1F\x7F]/', $cleaned)) {
                return $this->json(['error' => $this->trans('enduser.auth.invalid_username', [], 'Invalid username format or too long (max 100 characters).')], 422);
            }
            $username = $cleaned !== '' ? $cleaned : null;
        }

        $sanitizedCustomFields = null;
        if (isset($data['custom_fields'])) {
            $sanitizer = $this->customFieldsSanitizer ?? new EndUserCustomFieldsSanitizer($this->translator);
            $cfRes = $sanitizer->sanitize($data['custom_fields']);
            if (!$cfRes['valid']) {
                return $this->json(['error' => $cfRes['error']], 422);
            }
            $sanitizedCustomFields = $cfRes['sanitized'];
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => $this->trans('enduser.auth.email_required', [], 'Valid email is required')], 422);
        }

        $normalizedEmail = strtolower(trim((string) $email));
        $regTargetKey = 'reg_' . $projectId . '_' . $normalizedEmail;
        if ($this->lockoutService !== null && $this->lockoutService->isLockedOut($regTargetKey)) {
            $retryAfter = $this->lockoutService->getRemainingLockoutSeconds($regTargetKey);
            return new JsonResponse(['error' => $this->trans('enduser.auth.rate_limit_register', [], 'Too many registration requests. Please retry later.')], 429, ['Retry-After' => (string) $retryAfter]);
        }

        $validator = $this->passwordValidator ?? new PasswordPolicyValidator($this->translator);
        $policyResult = $validator->validate((string) $password, $normalizedEmail, is_string($username) ? $username : null, $project);
        if (!$policyResult['valid']) {
            return $this->json(['error' => $this->trans('enduser.auth.password_too_weak', [], 'Password must be at least 8 characters'), 'details' => $policyResult['errors']], 422);
        }

        $existing = $this->endUserRepository->findOneByProjectAndEmail($project, $normalizedEmail);
        if ($existing) {
            // Timing-safe equalization: execute full hash computation to prevent email enumeration timing oracle
            $this->hasher->hashPassword($existing, 'DummyTimingDefensePassword!123');
            $this->lockoutService?->recordTieredFailedAttempt($regTargetKey, [3 => 300, 6 => 900]);
            return $this->json(['error' => $this->trans('enduser.auth.email_exists', [], 'Email already registered')], 409);
        }

        $endUser = new EndUser($project, $normalizedEmail);
        $endUser->username = $username;
        $endUser->password = $this->hasher->hashPassword($endUser, $password);
        $endUser->passwordChangedAt = new \DateTimeImmutable();
        if ($sanitizedCustomFields !== null) {
            $endUser->customFields = $sanitizedCustomFields;
        }

        $this->em->persist($endUser);
        $this->em->flush();

        $this->lockoutService?->clearAttempts($regTargetKey);

        $session = $this->sessionRepo?->createSession($endUser, $request->getClientIp(), $request->headers->get('User-Agent'));
        $accessToken = $this->jwtService->createAccessToken($endUser);
        $refreshToken = $this->issueRefreshToken($endUser, $session?->sessionId);

        return $this->json([
            'data' => [
                'user' => $this->serializeEndUser($endUser),
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
            ],
        ], 201);
    }

    #[OA\Post(
        path: '/api/{projectId}/auth/login',
        summary: 'Authenticate an end-user',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email', 'password'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email'),
                new OA\Property(property: 'password', type: 'string'),
            ]
        )),
        parameters: [new OA\Parameter(name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Login successful', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'user', ref: '#/components/schemas/EndUser'),
                    new OA\Property(property: 'access_token', type: 'string'),
                    new OA\Property(property: 'refresh_token', type: 'string'),
                ]),
            ])),
            new OA\Response(response: 401, description: 'Invalid credentials', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 403, description: 'Account banned or inactive', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('/login', name: 'login', methods: ['POST'])]
    public function login(Request $request, string $projectId): JsonResponse
    {
        if ($this->loginLimiter !== null) {
            $limiter = $this->loginLimiter->create($request->getClientIp() ?: '127.0.0.1');
            if (!$limiter->consume()->isAccepted()) {
                return new JsonResponse(['error' => $this->trans('enduser.auth.rate_limit_login', [], 'Too many login attempts. Please retry later.')], 429);
            }
        }

        $project = $this->findProject($projectId);
        if (!$project) {
            return $this->json(['error' => $this->trans('enduser.auth.project_not_found', [], 'Project not found')], 404);
        }

        $data = $this->getJsonPayload($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        if (empty($email) || empty($password)) {
            return $this->json(['error' => $this->trans('enduser.auth.email_password_required', [], 'Email and password are required')], 422);
        }

        $normalizedEmail = strtolower(trim((string) $email));
        $lockoutKey = 'enduser_login:' . $projectId . ':' . $normalizedEmail;
        $ipLockoutKey = 'enduser_login_ip:' . $projectId . ':' . ($request->getClientIp() ?: '127.0.0.1');

        if ($this->lockoutService !== null && $this->lockoutService->isLockedOut($ipLockoutKey)) {
            $retryAfter = $this->lockoutService->getRemainingLockoutSeconds($ipLockoutKey);
            return new JsonResponse([
                'error' => $this->trans('enduser.auth.ip_locked_out', ['%seconds%' => (string) $retryAfter], "Too many failed attempts from this IP address. Please retry in {$retryAfter} seconds."),
            ], 429, ['Retry-After' => (string) $retryAfter]);
        }

        if ($this->lockoutService !== null && $this->lockoutService->isLockedOut($lockoutKey)) {
            $retryAfter = $this->lockoutService->getRemainingLockoutSeconds($lockoutKey);
            return new JsonResponse([
                'error' => $this->trans('enduser.auth.account_locked_out', ['%seconds%' => (string) $retryAfter], "Too many failed login attempts. Account temporarily locked for security. Please retry in {$retryAfter} seconds."),
            ], 429, ['Retry-After' => (string) $retryAfter]);
        }

        $endUser = $this->endUserRepository->findOneByProjectAndEmail($project, $normalizedEmail);
        if ($endUser === null || $endUser->password === null) {
            // Timing-safe comparison: perform simulated password verification to eliminate timing leakage
            $dummyUser = new EndUser($project, 'timing-defense@local.internal');
            $dummyUser->password = '$2y$13$BIHXwdLFZAOYbWPDBT2n6uUEtB81DTDcRMD.a4VGm0SWZJ48TIjt.';
            $this->hasher->isPasswordValid($dummyUser, (string) $password);
            $this->lockoutService?->recordTieredFailedAttempt($lockoutKey);
            $this->lockoutService?->recordTieredFailedAttempt($ipLockoutKey, [10 => 300, 20 => 900, 30 => 3600]);
            return $this->json(['error' => $this->trans('enduser.auth.invalid_credentials', [], 'Invalid credentials')], 401);
        }

        if (!$this->hasher->isPasswordValid($endUser, (string) $password)) {
            $this->lockoutService?->recordTieredFailedAttempt($lockoutKey);
            $this->lockoutService?->recordTieredFailedAttempt($ipLockoutKey, [10 => 300, 20 => 900, 30 => 3600]);
            return $this->json(['error' => $this->trans('enduser.auth.invalid_credentials', [], 'Invalid credentials')], 401);
        }

        if (!$endUser->isActive()) {
            return $this->json(['error' => $this->trans('enduser.auth.account_inactive', [], 'Account is inactive or banned')], 403);
        }

        $this->lockoutService?->clearAttempts($lockoutKey);

        // Check 2FA
        $projectSettings = $project->settings ?? [];
        $endUserTwoFactorEnabled = ($projectSettings['security'] ?? [])['endUserTwoFactor'] ?? false;

        if ($endUserTwoFactorEnabled && $endUser->twoFactorEnabled) {
            $emailCodeHash = null;
            if ($endUser->twoFactorMethod === 'email') {
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                // Envoyer le code par email, stocker uniquement le hash dans le JWT
                $this->container->get(\App\Service\TwoFactorMailer::class)
                    ->sendCode($endUser->email, $code, 'JamboAPI');
                $emailCodeHash = hash('sha256', $code);
            }
            $twoFactorToken = $this->jwtService->createTwoFactorToken($endUser, $emailCodeHash);
            return $this->json([
                'requires_2fa' => true,
                'two_factor_token' => $twoFactorToken,
                'two_factor_method' => $endUser->twoFactorMethod,
            ]);
        }

        $session = $this->sessionRepo?->createSession($endUser, $request->getClientIp(), $request->headers->get('User-Agent'));
        $accessToken = $this->jwtService->createAccessToken($endUser);
        $refreshToken = $this->issueRefreshToken($endUser, $session?->sessionId);

        return $this->json([
            'data' => [
                'user' => $this->serializeEndUser($endUser),
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/{projectId}/auth/verify-2fa',
        summary: 'Verify 2FA code and exchange ephemeral token for JWT pair',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['two_factor_token', 'code'],
            properties: [
                new OA\Property(property: 'two_factor_token', type: 'string'),
                new OA\Property(property: 'code', type: 'string'),
            ]
        )),
        parameters: [new OA\Parameter(name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: '2FA verified, JWT pair returned', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'user', ref: '#/components/schemas/EndUser'),
                    new OA\Property(property: 'access_token', type: 'string'),
                    new OA\Property(property: 'refresh_token', type: 'string'),
                ]),
            ])),
            new OA\Response(response: 401, description: 'Invalid 2FA token', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 422, description: 'Invalid code or missing fields', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('/verify-2fa', name: 'verify_2fa', methods: ['POST'])]
    public function verifyTwoFactor(Request $request, string $projectId): JsonResponse
    {
        if ($this->twoFactorLimiter !== null) {
            $limiter = $this->twoFactorLimiter->create($request->getClientIp() ?: '127.0.0.1');
            if (!$limiter->consume()->isAccepted()) {
                return new JsonResponse(['error' => $this->trans('enduser.auth.rate_limit_2fa', [], 'Too many 2FA verification attempts. Please retry later.')], 429);
            }
        }

        $project = $this->findProject($projectId);
        if (!$project) {
            return $this->json(['error' => $this->trans('enduser.auth.project_not_found', [], 'Project not found')], 404);
        }

        $data = $this->getJsonPayload($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $twoFactorToken = $data['two_factor_token'] ?? '';
        $code = (string) ($data['code'] ?? '');

        if (empty($twoFactorToken) || empty($code)) {
            return $this->json(['error' => $this->trans('enduser.auth.token_code_required', [], 'Token and code are required')], 422);
        }

        // Validate the 2FA JWT
        $claims = $this->jwtService->validateTwoFactorToken($twoFactorToken);
        if ($claims === null) {
            return $this->json(['error' => $this->trans('enduser.auth.invalid_2fa_token', [], 'Invalid or expired 2FA token. Please login again.')], 401);
        }

        try {
            $endUser = $this->endUserRepository->findOneBy(['uuid' => $claims['euid'], 'project' => $project]);
        } catch (\Throwable) {
            $endUser = null;
        }
        if (!$endUser || !$endUser->isActive()) {
            return $this->json(['error' => $this->trans('enduser.auth.invalid_2fa_token', [], 'Invalid or expired 2FA token. Please login again.')], 401);
        }

        $tfaLockoutKey = 'enduser_2fa:' . $endUser->uuid?->toString();
        $tfaBackupLockoutKey = 'enduser_2fa_backup:' . $endUser->uuid?->toString();
        $tfaIpLockoutKey = 'enduser_2fa_ip:' . $projectId . ':' . ($request->getClientIp() ?: '127.0.0.1');

        if ($this->lockoutService !== null && ($this->lockoutService->isLockedOut($tfaLockoutKey) || $this->lockoutService->isLockedOut($tfaIpLockoutKey))) {
            $retryAfter = max(
                $this->lockoutService->getRemainingLockoutSeconds($tfaLockoutKey),
                $this->lockoutService->getRemainingLockoutSeconds($tfaIpLockoutKey)
            );
            return new JsonResponse([
                'error' => $this->trans('enduser.auth.rate_limit_2fa_lockout', ['%seconds%' => (string) $retryAfter], "Too many invalid two-factor verification attempts. Challenge locked. Please retry in {$retryAfter} seconds."),
            ], 429, ['Retry-After' => (string) $retryAfter]);
        }

        $isBackup = (!empty($data['use_backup'])) || $this->twoFactorService->isBackupCodeFormat($code);
        if ($isBackup && $this->lockoutService !== null && $this->lockoutService->isLockedOut($tfaBackupLockoutKey)) {
            $retryAfter = $this->lockoutService->getRemainingLockoutSeconds($tfaBackupLockoutKey);
            return new JsonResponse([
                'error' => $this->trans('enduser.auth.backup_codes_locked', ['%seconds%' => (string) $retryAfter], "Too many invalid backup code attempts. Recovery codes are temporarily locked. Please retry in {$retryAfter} seconds."),
            ], 429, ['Retry-After' => (string) $retryAfter]);
        }

        if (strlen($code) > \App\Service\TwoFactorService::MAX_CODE_LENGTH) {
            $this->lockoutService?->recordTieredFailedAttempt($tfaLockoutKey, [3 => 180, 5 => 600, 8 => 1800]);
            $this->lockoutService?->recordTieredFailedAttempt($tfaIpLockoutKey, [5 => 180, 10 => 600, 15 => 1800]);
            if ($isBackup) {
                $this->lockoutService?->recordTieredFailedAttempt($tfaBackupLockoutKey, [3 => 300, 5 => 900, 8 => 3600]);
            }
            return $this->json(['error' => $this->trans('enduser.auth.invalid_code', [], 'Invalid code')], 422);
        }

        // Verify the code
        $valid = false;
        $remainingBackupCodes = null;
        if ($endUser->twoFactorMethod === 'totp' && !$isBackup) {
            $valid = $this->twoFactorService->verifyTotp($endUser->twoFactorSecret ?? '', $code);
        } elseif ($endUser->twoFactorMethod === 'email' && !$isBackup) {
            // Le JWT contient un hash du code (pas le code en clair)
            $storedHash = $claims['ech'] ?? null;
            $valid = $this->twoFactorService->verifyEmailHash($storedHash, $code);
        }

        // Fallback: backup codes
        if (!$valid && $endUser->twoFactorBackupCodes) {
            $codes = $endUser->twoFactorBackupCodes;
            $valid = $this->twoFactorService->verifyAndConsumeBackupCode($codes, $code);
            if ($valid) {
                $endUser->twoFactorBackupCodes = $codes;
                $this->em->flush();
                $remainingBackupCodes = $this->twoFactorService->getRemainingBackupCodesCount($codes);
            }
        }

        if (!$valid) {
            $this->lockoutService?->recordTieredFailedAttempt($tfaLockoutKey, [3 => 180, 5 => 600, 8 => 1800]);
            $this->lockoutService?->recordTieredFailedAttempt($tfaIpLockoutKey, [5 => 180, 10 => 600, 15 => 1800]);
            if ($isBackup) {
                $this->lockoutService?->recordTieredFailedAttempt($tfaBackupLockoutKey, [3 => 300, 5 => 900, 8 => 3600]);
            }
            return $this->json(['error' => $this->trans('enduser.auth.invalid_code', [], 'Invalid code')], 422);
        }

        $this->lockoutService?->clearAttempts($tfaLockoutKey);
        $this->lockoutService?->clearAttempts($tfaBackupLockoutKey);
        $this->lockoutService?->clearAttempts($tfaIpLockoutKey);

        $session = $this->sessionRepo?->createSession($endUser, $request->getClientIp(), $request->headers->get('User-Agent'));
        $accessToken = $this->jwtService->createAccessToken($endUser);
        $refreshToken = $this->issueRefreshToken($endUser, $session?->sessionId);

        return $this->json([
            'data' => [
                'user' => $this->serializeEndUser($endUser),
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/{projectId}/auth/refresh',
        summary: 'Refresh access and refresh tokens',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['refresh_token'],
            properties: [new OA\Property(property: 'refresh_token', type: 'string')]
        )),
        parameters: [new OA\Parameter(name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'New token pair', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/TokenPair'),
            ])),
            new OA\Response(response: 401, description: 'Invalid or expired refresh token', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('/refresh', name: 'refresh', methods: ['POST'])]
    public function refresh(Request $request, string $projectId): JsonResponse
    {
        $project = $this->findProject($projectId);
        if (!$project) {
            return $this->json(['error' => $this->trans('enduser.auth.project_not_found', [], 'Project not found')], 404);
        }

        $data = $this->getJsonPayload($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $refreshJwt = $data['refresh_token'] ?? '';

        if (empty($refreshJwt)) {
            return $this->json(['error' => $this->trans('enduser.auth.refresh_token_required', [], 'Refresh token is required')], 422);
        }

        $claims = $this->jwtService->validateToken($refreshJwt);
        if ($claims === null || !$this->jwtService->isRefreshToken($claims)) {
            return $this->json(['error' => $this->trans('enduser.auth.invalid_refresh_token', [], 'Invalid or expired refresh token')], 401);
        }

        $projectUuidStr = $project->uuid?->toString();
        if (isset($claims['pid']) && $claims['pid'] !== $projectUuidStr && (int) $claims['pid'] !== $project->id) {
            return $this->json(['error' => $this->trans('enduser.auth.invalid_refresh_token', [], 'Invalid or expired refresh token')], 401);
        }

        try {
            $endUser = $this->endUserRepository->findOneBy(['uuid' => $claims['euid'], 'project' => $project]);
        } catch (\Throwable) {
            $endUser = null;
        }
        if (!$endUser || !$endUser->isActive()) {
            return $this->json(['error' => $this->trans('enduser.auth.invalid_refresh_token', [], 'Invalid or expired refresh token')], 401);
        }

        if ($endUser->tokenVersion !== $claims['tkn']) {
            return $this->json(['error' => $this->trans('enduser.auth.token_revoked', [], 'Token has been revoked')], 401);
        }

        $tokenHash = ProjectAuthRefreshToken::hash($refreshJwt);
        $storedToken = $this->refreshTokenRepo?->findByHash($tokenHash);

        if ($storedToken !== null && $storedToken->isRevoked) {
            // Détection de réutilisation de Refresh Token (RFC 6819 & RFC 9068)
            // L'ancien jeton a déjà été échangé : alerte compromission / rejeu !
            if ($storedToken->familyId !== null) {
                $this->refreshTokenRepo->revokeFamily($storedToken->familyId);
            }
            $this->refreshTokenRepo->revokeAllForEndUser($endUser);
            $this->sessionRepo?->revokeAllForEndUser($endUser);
            $endUser->tokenVersion++;
            $this->em->flush();

            return $this->json([
                'error' => $this->trans('enduser.auth.refresh_reuse_detected', [], 'Refresh token reuse detected. All tokens and sessions have been revoked.'),
            ], 401);
        }

        if ($storedToken !== null && $storedToken->isExpired()) {
            return $this->json(['error' => $this->trans('enduser.auth.invalid_refresh_token', [], 'Invalid or expired refresh token')], 401);
        }

        $familyId = $storedToken?->familyId ?? ($claims['fml'] ?? bin2hex(random_bytes(16)));

        if ($storedToken !== null) {
            // Rotation : invalider le token utilisé
            $storedToken->isRevoked = true;
        } elseif ($this->refreshTokenRepo !== null) {
            // Enregistrement du token hérité comme consommé pour détecter tout rejeu ultérieur
            $legacyToken = new ProjectAuthRefreshToken();
            $legacyToken->tokenHash = $tokenHash;
            $legacyToken->endUser = $endUser;
            $legacyToken->familyId = $familyId;
            $legacyToken->isRevoked = true;
            $this->em->persist($legacyToken);
        }

        $accessToken = $this->jwtService->createAccessToken($endUser);
        $refreshToken = $this->issueRefreshToken($endUser, $familyId, $tokenHash);
        $this->sessionRepo?->createSession($endUser, $request->getClientIp(), $request->headers->get('User-Agent'));

        return $this->json([
            'data' => [
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/{projectId}/auth/me',
        summary: 'Get authenticated end-user profile',
        security: [['EndUserJWT' => []]],
        parameters: [new OA\Parameter(name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Current user', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/EndUser'),
            ])),
            new OA\Response(response: 401, description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('/me', name: 'me', methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        $endUser = $request->attributes->get('_end_user');
        if (!$endUser instanceof EndUser) {
            return $this->json(['error' => $this->trans('enduser.auth.unauthorized', [], 'Unauthorized')], 401);
        }

        return $this->json(['data' => $this->serializeEndUser($endUser)]);
    }

    #[OA\Patch(
        path: '/api/{projectId}/auth/me',
        summary: 'Update authenticated end-user profile',
        security: [['EndUserJWT' => []]],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
            new OA\Property(property: 'name', type: 'string', nullable: true),
            new OA\Property(property: 'custom_fields', type: 'object', nullable: true),
            new OA\Property(property: 'password', type: 'string', minLength: 8, nullable: true),
        ])),
        parameters: [new OA\Parameter(name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Updated user', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/EndUser'),
            ])),
            new OA\Response(response: 401, description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('/me', name: 'me_update', methods: ['PATCH'])]
    public function updateMe(Request $request): JsonResponse
    {
        $endUser = $request->attributes->get('_end_user');
        if (!$endUser instanceof EndUser) {
            return $this->json(['error' => $this->trans('enduser.auth.unauthorized', [], 'Unauthorized')], 401);
        }

        $data = $this->getJsonPayload($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        if (isset($data['username'])) {
            $cleaned = trim((string) $data['username']);
            if ($cleaned !== '') {
                if (mb_strlen($cleaned) > 100 || preg_match('/[\x00-\x1F\x7F]/', $cleaned)) {
                    return $this->json(['error' => $this->trans('enduser.auth.invalid_username', [], 'Invalid username format or too long (max 100 characters).')], 422);
                }
            }
            $endUser->username = $cleaned !== '' ? $cleaned : null;
        } elseif (isset($data['name'])) {
            $cleaned = trim((string) $data['name']);
            if ($cleaned !== '') {
                if (mb_strlen($cleaned) > 100 || preg_match('/[\x00-\x1F\x7F]/', $cleaned)) {
                    return $this->json(['error' => $this->trans('enduser.auth.invalid_username', [], 'Invalid username format or too long (max 100 characters).')], 422);
                }
            }
            $endUser->username = $cleaned !== '' ? $cleaned : null;
        }

        if (isset($data['custom_fields'])) {
            $sanitizer = $this->customFieldsSanitizer ?? new EndUserCustomFieldsSanitizer($this->translator);
            $cfRes = $sanitizer->sanitize($data['custom_fields']);
            if (!$cfRes['valid']) {
                return $this->json(['error' => $cfRes['error']], 422);
            }
            $endUser->customFields = $cfRes['sanitized'];
        }
        if (isset($data['password'])) {
            $newPassword = (string) $data['password'];
            $currentPassword = isset($data['current_password']) ? (string) $data['current_password'] : null;

            $pwChangeLockoutKey = 'pw_change:' . $endUser->uuid?->toString();
            if ($this->lockoutService !== null && $this->lockoutService->isLockedOut($pwChangeLockoutKey)) {
                $retryAfter = $this->lockoutService->getRemainingLockoutSeconds($pwChangeLockoutKey);
                return new JsonResponse([
                    'error' => $this->trans('enduser.auth.password_change_locked_out', ['%seconds%' => (string) $retryAfter], "Too many failed current password attempts. Password update locked for security. Please retry in {$retryAfter} seconds."),
                ], 429, ['Retry-After' => (string) $retryAfter]);
            }

            $validator = $this->passwordValidator ?? new PasswordPolicyValidator($this->translator);
            $rot = $validator->validateRotation($currentPassword, $newPassword, $endUser, $this->hasher);
            if (!$rot['valid']) {
                $this->lockoutService?->recordTieredFailedAttempt($pwChangeLockoutKey, [5 => 300, 10 => 900, 15 => 3600]);
                return $this->json(['error' => $rot['error']], 422);
            }

            $this->lockoutService?->clearAttempts($pwChangeLockoutKey);

            $policyResult = $validator->validate($newPassword, $endUser->email, $endUser->username, $endUser->project);
            if (!$policyResult['valid']) {
                return $this->json(['error' => $this->trans('enduser.auth.password_too_weak', [], 'Password must be at least 8 characters'), 'details' => $policyResult['errors']], 422);
            }

            $endUser->password = $this->hasher->hashPassword($endUser, $newPassword);
            $endUser->passwordChangedAt = new \DateTimeImmutable();
            $endUser->tokenVersion++;
            $this->sessionRepo?->revokeAllForEndUser($endUser);
            $this->refreshTokenRepo?->revokeAllForEndUser($endUser);
        }

        $this->em->flush();

        return $this->json(['data' => $this->serializeEndUser($endUser)]);
    }

    #[OA\Post(
        path: '/api/{projectId}/auth/logout',
        summary: 'Invalidate all tokens for the authenticated end-user',
        security: [['EndUserJWT' => []]],
        parameters: [new OA\Parameter(name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 204, description: 'Logged out'),
        ]
    )]
    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $endUser = $request->attributes->get('_end_user');
        if ($endUser instanceof EndUser) {
            $endUser->tokenVersion++;
            $this->sessionRepo?->revokeAllForEndUser($endUser);
            $this->refreshTokenRepo?->revokeAllForEndUser($endUser);
            $this->em->flush();
        }

        return $this->json(null, 204);
    }

    #[OA\Get(
        path: '/api/{projectId}/auth/sessions',
        summary: 'List active sessions for authenticated end-user',
        security: [['EndUserJWT' => []]],
        parameters: [new OA\Parameter(name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'List of active sessions'),
            new OA\Response(response: 401, description: 'Unauthorized'),
        ]
    )]
    #[Route('/sessions', name: 'sessions_list', methods: ['GET'])]
    public function listSessions(Request $request): JsonResponse
    {
        $endUser = $request->attributes->get('_end_user');
        if (!$endUser instanceof EndUser) {
            return $this->json(['error' => $this->trans('enduser.auth.unauthorized', [], 'Unauthorized')], 401);
        }

        $sessions = $this->sessionRepo?->findByEndUser($endUser) ?? [];
        $data = array_map(fn ($s) => [
            'session_id'     => $s->sessionId,
            'ip_address'     => $s->ipAddress,
            'user_agent'     => $s->userAgent,
            'last_active_at' => $s->lastActiveAt->format(\DateTimeInterface::ATOM),
            'created_at'     => $s->createdAt->format(\DateTimeInterface::ATOM),
        ], $sessions);

        return $this->json(['data' => $data]);
    }

    #[OA\Delete(
        path: '/api/{projectId}/auth/sessions/{sessionId}',
        summary: 'Revoke a specific session for authenticated end-user',
        security: [['EndUserJWT' => []]],
        parameters: [
            new OA\Parameter(name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'sessionId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Session revoked'),
            new OA\Response(response: 404, description: 'Session not found'),
            new OA\Response(response: 401, description: 'Unauthorized'),
        ]
    )]
    #[Route('/sessions/{sessionId}', name: 'sessions_revoke', methods: ['DELETE'])]
    public function revokeSession(Request $request, string $sessionId): JsonResponse
    {
        $endUser = $request->attributes->get('_end_user');
        if (!$endUser instanceof EndUser) {
            return $this->json(['error' => $this->trans('enduser.auth.unauthorized', [], 'Unauthorized')], 401);
        }

        $session = $this->sessionRepo?->findOneBy(['sessionId' => $sessionId, 'endUser' => $endUser]);
        if (!$session) {
            return $this->json(['error' => $this->trans('enduser.auth.session_not_found', [], 'Session not found')], 404);
        }

        $this->refreshTokenRepo?->revokeFamily($sessionId);
        $this->sessionRepo->revokeSession($sessionId);

        return $this->json(null, 204);
    }

    #[OA\Delete(
        path: '/api/{projectId}/auth/sessions',
        summary: 'Revoke all active sessions for authenticated end-user',
        security: [['EndUserJWT' => []]],
        parameters: [
            new OA\Parameter(name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Sessions revoked'),
            new OA\Response(response: 401, description: 'Unauthorized'),
        ]
    )]
    #[Route('/sessions', name: 'sessions_revoke_all', methods: ['DELETE'])]
    public function revokeAllSessions(Request $request): JsonResponse
    {
        $endUser = $request->attributes->get('_end_user');
        if (!$endUser instanceof EndUser) {
            return $this->json(['error' => $this->trans('enduser.auth.unauthorized', [], 'Unauthorized')], 401);
        }

        $this->sessionRepo?->revokeAllForEndUser($endUser);
        $this->refreshTokenRepo?->revokeAllForEndUser($endUser);
        $endUser->tokenVersion++;
        $this->em->flush();

        return $this->json(['message' => $this->trans('enduser.auth.sessions_revoked', [], 'All sessions have been revoked successfully.')]);
    }

    #[OA\Post(
        path: '/api/{projectId}/auth/forgot-password',
        summary: 'Request a password reset link',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email'],
            properties: [new OA\Property(property: 'email', type: 'string', format: 'email')]
        )),
        parameters: [new OA\Parameter(name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Reset link sent (if email exists)', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string'),
            ])),
        ]
    )]
    #[Route('/forgot-password', name: 'forgot_password', methods: ['POST'])]
    public function forgotPassword(Request $request, string $projectId): JsonResponse
    {
        if ($this->passwordResetLimiter !== null) {
            $limiter = $this->passwordResetLimiter->create($request->getClientIp() ?: '127.0.0.1');
            if (!$limiter->consume()->isAccepted()) {
                return new JsonResponse(['error' => $this->trans('enduser.auth.rate_limit_forgot', [], 'Too many password reset requests. Please retry later.')], 429);
            }
        }

        $project = $this->findProject($projectId);
        if (!$project) {
            return $this->json(['error' => $this->trans('enduser.auth.project_not_found', [], 'Project not found')], 404);
        }

        $data = $this->getJsonPayload($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $email = $data['email'] ?? '';

        if (empty($email)) {
            return $this->json(['error' => $this->trans('enduser.auth.email_field_required', [], 'Email is required')], 422);
        }

        $normalizedEmail = strtolower(trim((string) $email));
        if (strlen($normalizedEmail) > 180 || !filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['error' => $this->trans('enduser.auth.invalid_email_format', [], 'Invalid email address format.')], 422);
        }

        $clientIp = $request->getClientIp() ?: '127.0.0.1';
        $ipSprayKey = 'pw_reset_spray_ip:' . $projectId . ':' . $clientIp;
        if ($this->lockoutService !== null && $this->lockoutService->isLockedOut($ipSprayKey)) {
            $retryAfter = $this->lockoutService->getRemainingLockoutSeconds($ipSprayKey);
            return new JsonResponse([
                'error' => $this->trans('enduser.auth.reset_ip_locked_out', ['%seconds%' => (string) $retryAfter], "Too many password reset requests from this IP address. Please retry in {$retryAfter} seconds."),
            ], 429, ['Retry-After' => (string) $retryAfter]);
        }
        $this->lockoutService?->recordTieredFailedAttempt($ipSprayKey, [20 => 300, 40 => 900, 60 => 3600]);

        $cdKey = 'pw_reset_' . $projectId . '_' . $normalizedEmail;

        if ($this->lockoutService !== null && $this->lockoutService->hasCooldown($cdKey)) {
            // Cooldown active: return 200 without duplicate email dispatch to prevent mail-bombing
            return $this->json(['message' => $this->trans('enduser.auth.reset_link_sent', [], 'If the email exists, a reset link has been sent.')]);
        }

        // Passive cleanup of expired reset tokens for this project
        /** @var PasswordResetTokenRepository $tokenRepo */
        $tokenRepo = $this->em->getRepository(PasswordResetToken::class);
        $tokenRepo->deleteExpired($project);

        // Always return success to prevent email enumeration
        $endUser = $this->endUserRepository->findOneByProjectAndEmail($project, $normalizedEmail);
        if ($endUser) {
            $this->lockoutService?->recordProgressiveCooldown($cdKey);

            $this->em->getRepository(PasswordResetToken::class)
                ->createQueryBuilder('t')
                ->delete()
                ->where('t.email = :email AND t.project = :project')
                ->setParameter('email', $normalizedEmail)
                ->setParameter('project', $project)
                ->getQuery()
                ->execute();

            $token = new PasswordResetToken($project, $normalizedEmail);
            $this->em->persist($token);
            $this->em->flush();

            $this->sendPasswordResetEmail($project, $normalizedEmail, $token->token);
        } else {
            // Timing equalization: simulate token creation, DB latency & hash computation
            $this->lockoutService?->recordProgressiveCooldown($cdKey);
            hash('sha256', bin2hex(random_bytes(32)));
            usleep(random_int(3000, 8000));
        }

        return $this->json(['message' => $this->trans('enduser.auth.reset_link_sent', [], 'If the email exists, a reset link has been sent.')]);
    }

    private function sendPasswordResetEmail(Project $project, string $email, string $token): void
    {
        $subject = 'Password reset request';
        $body = "Your password reset token is: " . $token;
        $html = "<p>You requested a password reset. Your reset token is: <code>" . htmlspecialchars($token) . "</code></p>";

        if ($this->projectMailer !== null) {
            try {
                $this->projectMailer->send($project, $email, $subject, $body, $html);
                return;
            } catch (\Throwable) {
                // Fallback to system mailer if project mailer is not enabled
            }
        }

        if ($this->mailer !== null) {
            try {
                $this->mailer->send(
                    (new Email())
                        ->from('noreply@jambostack.site')
                        ->to($email)
                        ->subject($subject)
                        ->html($html)
                );
            } catch (\Throwable) {
                // Silently ignore mail transport errors in test/dev
            }
        }
    }

    #[OA\Post(
        path: '/api/{projectId}/auth/reset-password',
        summary: 'Reset password using a token',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['token', 'password'],
            properties: [
                new OA\Property(property: 'token', type: 'string'),
                new OA\Property(property: 'password', type: 'string', minLength: 8),
            ]
        )),
        parameters: [new OA\Parameter(name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Password reset successfully', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string'),
            ])),
            new OA\Response(response: 400, description: 'Invalid or expired token', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('/reset-password', name: 'reset_password', methods: ['POST'])]
    public function resetPassword(Request $request, string $projectId): JsonResponse
    {
        if ($this->passwordResetLimiter !== null) {
            $limiter = $this->passwordResetLimiter->create($request->getClientIp() ?: '127.0.0.1');
            if (!$limiter->consume()->isAccepted()) {
                return new JsonResponse(['error' => $this->trans('enduser.auth.rate_limit_reset', [], 'Too many password reset attempts. Please retry later.')], 429);
            }
        }

        $project = $this->findProject($projectId);
        if (!$project) {
            return $this->json(['error' => $this->trans('enduser.auth.project_not_found', [], 'Project not found')], 404);
        }

        $ipTokenKey = 'pw_reset_token_ip:' . $projectId . ':' . ($request->getClientIp() ?: '127.0.0.1');
        if ($this->lockoutService !== null && $this->lockoutService->isLockedOut($ipTokenKey)) {
            $retryAfter = $this->lockoutService->getRemainingLockoutSeconds($ipTokenKey);
            return new JsonResponse([
                'error' => $this->trans('enduser.auth.token_rate_limit_lockout', ['%seconds%' => (string) $retryAfter], "Too many invalid token attempts. Token validation temporarily locked. Please retry in {$retryAfter} seconds."),
            ], 429, ['Retry-After' => (string) $retryAfter]);
        }

        $data = $this->getJsonPayload($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $tokenStr = (string) ($data['token'] ?? '');
        $newPassword = (string) ($data['password'] ?? '');
        $confirmation = $data['password_confirmation'] ?? null;

        if (empty($tokenStr) || empty($newPassword)) {
            return $this->json(['error' => $this->trans('enduser.auth.token_password_required', [], 'Token and password are required')], 422);
        }

        if ($confirmation !== null && (string) $confirmation !== $newPassword) {
            return new JsonResponse(['error' => $this->trans('enduser.auth.password_confirmation_mismatch', [], 'Password and password confirmation do not match.')], 422);
        }

        if (strlen($tokenStr) !== 64 || !ctype_xdigit($tokenStr)) {
            $this->lockoutService?->recordTieredFailedAttempt($ipTokenKey, [5 => 300, 10 => 900, 15 => 3600]);
            return $this->json(['error' => $this->trans('enduser.auth.invalid_reset_token', [], 'Invalid or expired token')], 400);
        }

        /** @var PasswordResetTokenRepository $tokenRepo */
        $tokenRepo = $this->em->getRepository(PasswordResetToken::class);
        $token = $tokenRepo->findOneBy(['token' => $tokenStr, 'project' => $project]);

        if (!$token || !hash_equals($token->token, $tokenStr) || $token->isExpired()) {
            $this->lockoutService?->recordTieredFailedAttempt($ipTokenKey, [5 => 300, 10 => 900, 15 => 3600]);
            return $this->json(['error' => $this->trans('enduser.auth.invalid_reset_token', [], 'Invalid or expired token')], 400);
        }

        $endUser = $this->endUserRepository->findOneByProjectAndEmail($project, $token->email);
        if (!$endUser) {
            // Anti-enumeration defense: uniform 400 response
            $this->lockoutService?->recordTieredFailedAttempt($ipTokenKey, [5 => 300, 10 => 900, 15 => 3600]);
            return $this->json(['error' => $this->trans('enduser.auth.invalid_reset_token', [], 'Invalid or expired token')], 400);
        }

        $validator = $this->passwordValidator ?? new PasswordPolicyValidator($this->translator);
        $policyResult = $validator->validate($newPassword, $endUser->email, $endUser->username, $project);
        if (!$policyResult['valid']) {
            return $this->json(['error' => $this->trans('enduser.auth.password_too_weak', [], 'Password must be at least 8 characters'), 'details' => $policyResult['errors']], 422);
        }

        if ($endUser->password !== null && $this->hasher->isPasswordValid($endUser, $newPassword)) {
            return $this->json(['error' => $this->trans('enduser.auth.password_same_as_current', [], 'New password must be different from previous password.')], 422);
        }

        $endUser->password = $this->hasher->hashPassword($endUser, $newPassword);
        $endUser->passwordChangedAt = new \DateTimeImmutable();
        $endUser->tokenVersion++;
        $this->sessionRepo?->revokeAllForEndUser($endUser);
        $this->refreshTokenRepo?->revokeAllForEndUser($endUser);
        $this->em->remove($token);

        // Passive cleanup of other expired tokens for this project
        $tokenRepo->deleteExpired($project);

        $this->em->flush();

        $this->lockoutService?->clearAttempts('enduser_login:' . $projectId . ':' . strtolower(trim($endUser->email)));
        $this->lockoutService?->clearAttempts($ipTokenKey);

        return $this->json(['message' => $this->trans('enduser.auth.password_reset_success', [], 'Password has been reset successfully.')]);
    }

    // ─── Social Login ──────────────────────────────────────────────────────

    #[OA\Get(
        path: '/api/{projectId}/auth/social/{provider}',
        summary: 'Social login — obtain OAuth authorization URL and state',
        parameters: [
            new OA\Parameter(name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['google', 'microsoft', 'github', 'gitlab'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Authorization URL and CSRF state', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'url', type: 'string'),
                new OA\Property(property: 'state', type: 'string'),
            ])),
            new OA\Response(response: 400, description: 'Provider not configured'),
            new OA\Response(response: 404, description: 'Project not found'),
        ]
    )]
    #[Route('/social/{provider}', name: 'social_url', methods: ['GET'])]
    public function getSocialUrl(Request $request, string $projectId, string $provider): JsonResponse
    {
        if ($this->socialAuthLimiter !== null) {
            $limiter = $this->socialAuthLimiter->create($request->getClientIp() ?: '127.0.0.1');
            if (!$limiter->consume()->isAccepted()) {
                return new JsonResponse(['error' => $this->trans('enduser.auth.rate_limit_social', [], 'Too many social login requests. Please retry later.')], 429);
            }
        }

        $project = $this->findProject($projectId);
        if (!$project) {
            return $this->json(['error' => $this->trans('enduser.auth.project_not_found', [], 'Project not found')], 404);
        }

        if (!in_array($provider, ['google', 'microsoft', 'github', 'gitlab'], true)) {
            return $this->json(['error' => $this->trans('enduser.auth.unknown_provider', [], 'Unknown provider')], 400);
        }

        $security = ($project->settings ?? [])['security'] ?? [];
        if (!($security['endUserSocialLogin'] ?? false)) {
            return $this->json(['error' => $this->trans('enduser.auth.social_not_enabled', [], 'Social login is not enabled for this project')], 400);
        }

        if ($this->socialLogin === null) {
            return $this->json(['error' => $this->trans('enduser.auth.social_not_available', [], 'Social login is not available')], 500);
        }

        $credentials = $this->socialLogin->getProjectCredentials($project, $provider);
        if (!$credentials) {
            return $this->json(['error' => $this->trans('enduser.auth.social_provider_not_configured', ['%provider%' => $provider], "Provider '{$provider}' is not configured for this project")], 400);
        }

        $redirectUri = $request->getSchemeAndHttpHost() . "/api/{$projectId}/auth/social/{$provider}";
        $codeChallenge = $request->query->get('code_challenge');
        $codeChallengeMethod = $request->query->get('code_challenge_method', 'S256');

        try {
            $data = $this->socialLogin->getAuthorizationUrlAndState(
                $provider,
                $redirectUri,
                $credentials,
                is_string($codeChallenge) && $codeChallenge !== '' ? $codeChallenge : null,
                is_string($codeChallengeMethod) ? $codeChallengeMethod : 'S256',
            );
            return $this->json($data);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
    }

    #[OA\Post(
        path: '/api/{projectId}/auth/social/{provider}',
        summary: 'Social login — exchange OAuth code for JWT',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['code'],
            properties: [new OA\Property(property: 'code', type: 'string')],
        )),
        parameters: [
            new OA\Parameter(name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['google', 'microsoft', 'github', 'gitlab'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'JWT issued', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'user', ref: '#/components/schemas/EndUser'),
                    new OA\Property(property: 'access_token', type: 'string'),
                    new OA\Property(property: 'refresh_token', type: 'string'),
                ]),
            ])),
            new OA\Response(response: 400, description: 'Provider not configured or invalid code'),
        ]
    )]
    #[Route('/social/{provider}', name: 'social_login', methods: ['POST'])]
    public function socialLogin(Request $request, string $projectId, string $provider): JsonResponse
    {
        if ($this->loginLimiter !== null) {
            $limiter = $this->loginLimiter->create($request->getClientIp() ?: '127.0.0.1');
            if (!$limiter->consume()->isAccepted()) {
                return new JsonResponse(['error' => $this->trans('enduser.auth.rate_limit_login', [], 'Too many login attempts. Please retry later.')], 429);
            }
        }

        if ($this->socialAuthLimiter !== null) {
            $socialLimiter = $this->socialAuthLimiter->create($request->getClientIp() ?: '127.0.0.1');
            if (!$socialLimiter->consume()->isAccepted()) {
                return new JsonResponse(['error' => $this->trans('enduser.auth.rate_limit_social', [], 'Too many social login requests. Please retry later.')], 429);
            }
        }

        if (strlen($request->getContent()) > 1048576) {
            return new JsonResponse(['error' => $this->trans('enduser.auth.payload_too_large', [], 'Payload too large (max 1MB).')], 413);
        }

        $project = $this->findProject($projectId);
        if (!$project) {
            return $this->json(['error' => $this->trans('enduser.auth.project_not_found', [], 'Project not found')], 404);
        }

        if (!in_array($provider, ['google', 'microsoft', 'github', 'gitlab'], true)) {
            return $this->json(['error' => $this->trans('enduser.auth.unknown_provider', [], 'Unknown provider')], 400);
        }

        // Vérifier que le Social Login est activé pour ce projet
        $security = ($project->settings ?? [])['security'] ?? [];
        if (!($security['endUserSocialLogin'] ?? false)) {
            return $this->json(['error' => $this->trans('enduser.auth.social_not_enabled', [], 'Social login is not enabled for this project')], 400);
        }

        $data = $this->getJsonPayload($request);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $code = (string) ($data['code'] ?? '');
        $codeVerifier = isset($data['code_verifier']) && is_string($data['code_verifier']) && $data['code_verifier'] !== ''
            ? $data['code_verifier']
            : null;

        if (empty($code)) {
            return $this->json(['error' => $this->trans('enduser.auth.social_code_required', [], 'Authorization code is required')], 422);
        }

        try {
            if ($this->socialLogin === null) {
                return $this->json(['error' => $this->trans('enduser.auth.social_not_available', [], 'Social login is not available')], 500);
            }

            $redirectUri = $request->getSchemeAndHttpHost() . "/api/{$projectId}/auth/social/{$provider}";
            $credentials = $this->socialLogin->getProjectCredentials($project, $provider);
            if (!$credentials) {
                return $this->json(['error' => $this->trans('enduser.auth.social_provider_not_configured', ['%provider%' => $provider], "Provider '{$provider}' is not configured for this project")], 400);
            }

            $socialUser = $this->socialLogin->getUserFromProvider(
                $provider,
                $code,
                $redirectUri,
                $credentials,
                $codeVerifier,
            );

            $endUser = $this->socialLogin->findOrCreateEndUser($project, $socialUser, $provider);

            if (!$endUser->isActive()) {
                return $this->json(['error' => $this->trans('enduser.auth.account_inactive', [], 'Account is inactive or banned')], 403);
            }

            // Vérifier 2FA
            if ($endUser->twoFactorEnabled) {
                $emailCodeHash = null;
                if ($endUser->twoFactorMethod === 'email') {
                    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    try {
                        $this->container->get(\App\Service\TwoFactorMailer::class)
                            ?->sendCode($endUser->email, $code, 'JamboAPI');
                    } catch (\Throwable) {}
                    $emailCodeHash = hash('sha256', $code);
                }
                $twoFactorToken = $this->jwtService->createTwoFactorToken($endUser, $emailCodeHash);

                return $this->json([
                    'requires_2fa' => true,
                    'two_factor_token' => $twoFactorToken,
                    'two_factor_method' => $endUser->twoFactorMethod,
                ]);
            }

            $session = $this->sessionRepo?->createSession($endUser, $request->getClientIp(), $request->headers->get('User-Agent'));
            $accessToken = $this->jwtService->createAccessToken($endUser);
            $refreshToken = $this->issueRefreshToken($endUser, $session?->sessionId);

            return $this->json([
                'data' => [
                    'user'          => $this->serializeEndUser($endUser),
                    'access_token'  => $accessToken,
                    'refresh_token' => $refreshToken,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $this->trans('enduser.auth.social_login_failed', ['%message%' => $e->getMessage()], 'Social login failed: ' . $e->getMessage())], 400);
        }
    }

    private function getJsonPayload(Request $request): array|JsonResponse
    {
        if (strlen($request->getContent()) > 65536) {
            return $this->json(['error' => $this->trans('enduser.auth.payload_too_large', [], 'Request payload too large (max 64KB).')], 413);
        }

        try {
            return $request->toArray();
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('enduser.auth.invalid_json', [], 'Invalid JSON')], 400);
        }
    }

    private function findProject(string $projectId): ?Project
    {
        try {
            return $this->projectRepository->findOneBy(['uuid' => $projectId]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function issueRefreshToken(EndUser $endUser, ?string $familyId = null, ?string $parentTokenHash = null): string
    {
        $familyId ??= bin2hex(random_bytes(16));
        $refreshToken = $this->jwtService->createRefreshToken($endUser, $familyId);

        if ($this->refreshTokenRepo !== null) {
            $ttl = EndUserJwtService::resolveTtl($endUser->project?->jwtRefreshTtl, EndUserJwtService::DEFAULT_REFRESH_TTL);
            $tokenEntity = new ProjectAuthRefreshToken();
            $tokenEntity->tokenHash = ProjectAuthRefreshToken::hash($refreshToken);
            $tokenEntity->endUser = $endUser;
            $tokenEntity->familyId = $familyId;
            $tokenEntity->parentTokenHash = $parentTokenHash;
            $tokenEntity->expiresAt = (new \DateTimeImmutable())->modify("+{$ttl} seconds");

            $this->em->persist($tokenEntity);
            $this->em->flush();
        }

        return $refreshToken;
    }
}
