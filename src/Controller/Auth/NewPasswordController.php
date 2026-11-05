<?php

namespace App\Controller\Auth;

use App\Controller\InertiaController;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\UserRepository;
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

    public function __construct(
        private PasswordResetTokenRepository $tokenRepository,
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
        #[Autowire(service: 'limiter.password_reset_limiter')]
        private ?RateLimiterFactory $passwordResetLimiter = null,
    ) {}

    #[Route('/reset-password/{token}', name: 'app_reset_password', methods: ['GET'])]
    public function create(Request $request, string $token): Response
    {
        $resetToken = $this->tokenRepository->findValidByToken($token);

        if ($resetToken === null) {
            return $this->inertia($request, 'auth/reset-password', [
                'error' => $this->trans('auth.password_reset.invalid_or_expired_link', [], 'This password reset link is invalid or has expired.'),
            ]);
        }

        return $this->inertia($request, 'auth/reset-password', [
            'token' => $token,
            'email' => $resetToken->email,
        ]);
    }

    #[Route('/reset-password/{token}', name: 'app_reset_password_store', methods: ['POST'])]
    public function store(Request $request, string $token): Response
    {
        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->inertia($request, 'auth/reset-password', [
                'token' => $token,
                'errors' => ['token' => $this->trans('auth.password_reset.payload_too_large', [], 'Payload exceeds maximum allowed size (16 KB).')],
            ], 413);
        }

        if ($this->passwordResetLimiter !== null) {
            $limiter = $this->passwordResetLimiter->create($request->getClientIp() ?: '127.0.0.1');
            if (!$limiter->consume()->isAccepted()) {
                return $this->inertia($request, 'auth/reset-password', [
                    'token' => $token,
                    'errors' => ['token' => $this->trans('auth.password_reset.rate_limit_exceeded', [], 'Too many requests. Please retry later.')],
                ]);
            }
        }

        $resetToken = $this->tokenRepository->findValidByToken($token);

        if ($resetToken === null) {
            return $this->inertia($request, 'auth/reset-password', [
                'token' => $token,
                'errors' => ['token' => $this->trans('auth.password_reset.invalid_or_expired_link', [], 'This password reset link is invalid or has expired.')],
            ]);
        }

        try {
            $password = $request->getPayload()->getString('password');
            $confirmation = $request->getPayload()->getString('password_confirmation');
        } catch (\Throwable) {
            $password = (string) $request->request->get('password', '');
            $confirmation = (string) $request->request->get('password_confirmation', '');
        }

        if (strlen($password) < 8 || strlen($password) > 4096) {
            return $this->inertia($request, 'auth/reset-password', [
                'token' => $token,
                'email' => $resetToken->email,
                'errors' => ['password' => $this->trans('auth.password_reset.length_invalid', [], 'Password must be between 8 and 4096 characters.')],
            ]);
        }

        if ($password !== $confirmation) {
            return $this->inertia($request, 'auth/reset-password', [
                'token' => $token,
                'email' => $resetToken->email,
                'errors' => ['password_confirmation' => $this->trans('auth.password_reset.passwords_do_not_match', [], 'Passwords do not match.')],
            ]);
        }

        $user = $this->userRepository->findByEmail($resetToken->email);
        if ($user === null) {
            return $this->inertia($request, 'auth/reset-password', [
                'token' => $token,
                'email' => $resetToken->email,
                'errors' => ['email' => $this->trans('auth.password_reset.user_not_found', [], 'User not found.')],
            ]);
        }

        $pwdValidator = new PasswordPolicyValidator();
        $valResult = $pwdValidator->validateAdminPassword($password, $user->email, $user->name);
        if (!$valResult['valid']) {
            return $this->inertia($request, 'auth/reset-password', [
                'token' => $token,
                'email' => $resetToken->email,
                'errors' => ['password' => $valResult['errors'][0]],
            ]);
        }

        if ($user->password !== null && $this->hasher->isPasswordValid($user, $password)) {
            return $this->inertia($request, 'auth/reset-password', [
                'token' => $token,
                'email' => $resetToken->email,
                'errors' => ['password' => $this->trans('auth.password_reset.same_as_current', [], 'New password must be different from current password.')],
            ]);
        }

        $user->password = $this->hasher->hashPassword($user, $password);
        $user->passwordChangedAt = new \DateTimeImmutable();
        $this->em->remove($resetToken);
        $this->em->flush();

        return $this->redirectToRoute('app_login');
    }
}
