<?php

namespace App\Controller\Auth;

use App\Controller\InertiaController;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\UserRepository;
use App\Service\AuditService;
use App\Service\Security\PasswordPolicyValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class NewPasswordController extends InertiaController
{
    private const MAX_PAYLOAD_BYTES = 16384; // 16 KB
    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-store, no-cache, must-revalidate',
        'Pragma'                 => 'no-cache',
    ];

    public function __construct(
        private PasswordResetTokenRepository $tokenRepository,
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
        #[Autowire(service: 'limiter.password_reset_limiter')]
        private ?RateLimiterFactory $passwordResetLimiter = null,
        private ?AuditService $auditService = null,
        private ?TranslatorInterface $customTranslator = null,
    ) {}

    protected function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->customTranslator !== null) {
            $res = $this->customTranslator->trans($id, $parameters, 'messages');
            if ($res !== $id) {
                return $res;
            }
        }
        return parent::trans($id, $parameters, $default);
    }

    #[Route('/reset-password/{token}', name: 'app_reset_password', methods: ['GET'])]
    public function create(Request $request, string $token): Response
    {
        if (strlen($token) < 16 || strlen($token) > 128 || !preg_match('/^[a-zA-Z0-9_\-]+$/', $token)) {
            $resp = $this->inertia($request, 'auth/reset-password', [
                'error' => $this->trans('auth.password_reset.invalid_or_expired_link', [], 'This password reset link is invalid or has expired.'),
            ]);
            foreach (self::SECURITY_HEADERS as $k => $v) {
                $resp->headers->set($k, $v);
            }
            return $resp;
        }

        $resetToken = $this->tokenRepository->findValidByToken($token);

        if ($resetToken === null) {
            $resp = $this->inertia($request, 'auth/reset-password', [
                'error' => $this->trans('auth.password_reset.invalid_or_expired_link', [], 'This password reset link is invalid or has expired.'),
            ]);
            foreach (self::SECURITY_HEADERS as $k => $v) {
                $resp->headers->set($k, $v);
            }
            return $resp;
        }

        $resp = $this->inertia($request, 'auth/reset-password', [
            'token' => $token,
            'email' => $resetToken->email,
        ]);
        foreach (self::SECURITY_HEADERS as $k => $v) {
            $resp->headers->set($k, $v);
        }
        return $resp;
    }

    #[Route('/reset-password/{token}', name: 'app_reset_password_store', methods: ['POST'])]
    public function store(Request $request, string $token): Response
    {
        $ip = $request->getClientIp() ?: '127.0.0.1';
        $contentType = $request->headers->get('Content-Type', '');
        $rawContent = $request->getContent();

        if ($rawContent !== '' && !empty($contentType)) {
            $isJson = str_contains($contentType, 'application/json');
            $isForm = str_contains($contentType, 'application/x-www-form-urlencoded') || str_contains($contentType, 'multipart/form-data');
            if (!$isJson && !$isForm) {
                if ($this->auditService !== null) {
                    $this->auditService->log(
                        toolName: 'password_reset_invalid_content_type',
                        project: null,
                        input: ['ip' => $ip, 'token' => substr($token, 0, 8) . '...', 'content_type' => $contentType],
                        output: null,
                        status: 'failure',
                        errorMessage: 'Invalid Content-Type for password reset execution.',
                        createdBy: null,
                        source: 'reset_password'
                    );
                    $this->auditService->flush();
                }
                $resp = $this->inertia($request, 'auth/reset-password', [
                    'token' => $token,
                    'errors' => ['token' => $this->trans('auth.password_reset.invalid_content_type', [], 'Content-Type must be application/json or application/x-www-form-urlencoded.')],
                ], 415);
                foreach (self::SECURITY_HEADERS as $k => $v) {
                    $resp->headers->set($k, $v);
                }
                return $resp;
            }
        }

        if (strlen($rawContent) > self::MAX_PAYLOAD_BYTES) {
            if ($this->auditService !== null) {
                $this->auditService->log(
                    toolName: 'password_reset_payload_too_large',
                    project: null,
                    input: ['ip' => $ip, 'size' => strlen($rawContent)],
                    output: null,
                    status: 'failure',
                    errorMessage: 'Payload exceeds maximum allowed size (16 KB).',
                    createdBy: null,
                    source: 'reset_password'
                );
                $this->auditService->flush();
            }
            $resp = $this->inertia($request, 'auth/reset-password', [
                'token' => $token,
                'errors' => ['token' => $this->trans('auth.password_reset.payload_too_large', [], 'Payload exceeds maximum allowed size (16 KB).')],
            ], 413);
            foreach (self::SECURITY_HEADERS as $k => $v) {
                $resp->headers->set($k, $v);
            }
            return $resp;
        }

        if ($this->passwordResetLimiter !== null) {
            $limiter = $this->passwordResetLimiter->create($ip);
            if (!$limiter->consume()->isAccepted()) {
                if ($this->auditService !== null) {
                    $this->auditService->log(
                        toolName: 'password_reset_rate_limited',
                        project: null,
                        input: ['ip' => $ip],
                        output: null,
                        status: 'failure',
                        errorMessage: 'Too many password reset attempts.',
                        createdBy: null,
                        source: 'reset_password'
                    );
                    $this->auditService->flush();
                }
                $resp = $this->inertia($request, 'auth/reset-password', [
                    'token' => $token,
                    'errors' => ['token' => $this->trans('auth.password_reset.rate_limit_exceeded', [], 'Too many requests. Please try again later.')],
                ], 429);
                foreach (self::SECURITY_HEADERS as $k => $v) {
                    $resp->headers->set($k, $v);
                }
                return $resp;
            }
        }

        if (strlen($token) < 16 || strlen($token) > 128 || !preg_match('/^[a-zA-Z0-9_\-]+$/', $token)) {
            if ($this->auditService !== null) {
                $this->auditService->log(
                    toolName: 'password_reset_token_format_invalid',
                    project: null,
                    input: ['ip' => $ip, 'token_len' => strlen($token)],
                    output: null,
                    status: 'failure',
                    errorMessage: 'Reset token format is invalid.',
                    createdBy: null,
                    source: 'reset_password'
                );
                $this->auditService->flush();
            }
            $resp = $this->inertia($request, 'auth/reset-password', [
                'token' => $token,
                'errors' => ['token' => $this->trans('auth.password_reset.token_format_invalid', [], 'Reset token format is invalid.')],
            ]);
            foreach (self::SECURITY_HEADERS as $k => $v) {
                $resp->headers->set($k, $v);
            }
            return $resp;
        }

        $resetToken = $this->tokenRepository->findValidByToken($token);

        if ($resetToken === null) {
            if ($this->auditService !== null) {
                $this->auditService->log(
                    toolName: 'password_reset_invalid_or_expired_token',
                    project: null,
                    input: ['ip' => $ip, 'token' => substr($token, 0, 8) . '...'],
                    output: null,
                    status: 'failure',
                    errorMessage: 'Password reset link is invalid or expired.',
                    createdBy: null,
                    source: 'reset_password'
                );
                $this->auditService->flush();
            }
            $resp = $this->inertia($request, 'auth/reset-password', [
                'token' => $token,
                'errors' => ['token' => $this->trans('auth.password_reset.invalid_or_expired_link', [], 'This password reset link is invalid or has expired.')],
            ]);
            foreach (self::SECURITY_HEADERS as $k => $v) {
                $resp->headers->set($k, $v);
            }
            return $resp;
        }

        try {
            $password = $request->getPayload()->getString('password');
            $confirmation = $request->getPayload()->getString('password_confirmation');
        } catch (\Throwable) {
            $password = (string) $request->request->get('password', '');
            $confirmation = (string) $request->request->get('password_confirmation', '');
        }

        if (strlen($password) < 8 || strlen($password) > 4096) {
            $resp = $this->inertia($request, 'auth/reset-password', [
                'token' => $token,
                'email' => $resetToken->email,
                'errors' => ['password' => $this->trans('auth.password_reset.length_invalid', [], 'Password must be between 8 and 4096 characters.')],
            ]);
            foreach (self::SECURITY_HEADERS as $k => $v) {
                $resp->headers->set($k, $v);
            }
            return $resp;
        }

        if ($password !== $confirmation) {
            $resp = $this->inertia($request, 'auth/reset-password', [
                'token' => $token,
                'email' => $resetToken->email,
                'errors' => ['password_confirmation' => $this->trans('auth.password_reset.passwords_do_not_match', [], 'Passwords do not match.')],
            ]);
            foreach (self::SECURITY_HEADERS as $k => $v) {
                $resp->headers->set($k, $v);
            }
            return $resp;
        }

        $user = $this->userRepository->findByEmail($resetToken->email);
        if ($user === null) {
            $resp = $this->inertia($request, 'auth/reset-password', [
                'token' => $token,
                'email' => $resetToken->email,
                'errors' => ['email' => $this->trans('auth.password_reset.user_not_found', [], 'User not found.')],
            ]);
            foreach (self::SECURITY_HEADERS as $k => $v) {
                $resp->headers->set($k, $v);
            }
            return $resp;
        }

        $pwdValidator = new PasswordPolicyValidator();
        $valResult = $pwdValidator->validateAdminPassword($password, $user->email, $user->name);
        if (!$valResult['valid']) {
            if ($this->auditService !== null) {
                $this->auditService->log(
                    toolName: 'password_reset_policy_failed',
                    project: null,
                    input: ['ip' => $ip, 'email' => $user->email],
                    output: null,
                    status: 'failure',
                    errorMessage: $valResult['errors'][0] ?? 'Password policy validation failed.',
                    createdBy: $user->getUserIdentifier(),
                    source: 'reset_password'
                );
                $this->auditService->flush();
            }
            $resp = $this->inertia($request, 'auth/reset-password', [
                'token' => $token,
                'email' => $resetToken->email,
                'errors' => ['password' => $valResult['errors'][0]],
            ]);
            foreach (self::SECURITY_HEADERS as $k => $v) {
                $resp->headers->set($k, $v);
            }
            return $resp;
        }

        if ($user->password !== null && $this->hasher->isPasswordValid($user, $password)) {
            $resp = $this->inertia($request, 'auth/reset-password', [
                'token' => $token,
                'email' => $resetToken->email,
                'errors' => ['password' => $this->trans('auth.password_reset.same_as_current', [], 'New password must be different from current password.')],
            ]);
            foreach (self::SECURITY_HEADERS as $k => $v) {
                $resp->headers->set($k, $v);
            }
            return $resp;
        }

        $user->password = $this->hasher->hashPassword($user, $password);
        $user->passwordChangedAt = new \DateTimeImmutable();

        // Remove all reset tokens for this user
        $allTokens = $this->tokenRepository->findBy(['email' => $resetToken->email]);
        foreach ($allTokens as $t) {
            $this->em->remove($t);
        }
        $this->em->flush();

        if ($this->auditService !== null) {
            $this->auditService->log(
                toolName: 'password_reset_success',
                project: null,
                input: ['ip' => $ip, 'email' => $user->email],
                output: ['success' => true],
                status: 'success',
                createdBy: $user->getUserIdentifier(),
                source: 'reset_password'
            );
            $this->auditService->flush();
        }

        $resp = $this->redirectToRoute('app_login');
        foreach (self::SECURITY_HEADERS as $k => $v) {
            $resp->headers->set($k, $v);
        }
        return $resp;
    }
}
