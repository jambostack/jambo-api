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

class NewPasswordController extends InertiaController
{
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
                'error' => 'This password reset link is invalid or has expired.',
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
        if ($this->passwordResetLimiter !== null) {
            $limiter = $this->passwordResetLimiter->create($request->getClientIp() ?: '127.0.0.1');
            if (!$limiter->consume()->isAccepted()) {
                return $this->inertia($request, 'auth/reset-password', [
                    'token' => $token,
                    'errors' => ['token' => 'Too many requests. Please retry later.'],
                ]);
            }
        }

        $resetToken = $this->tokenRepository->findValidByToken($token);

        if ($resetToken === null) {
            return $this->inertia($request, 'auth/reset-password', [
                'token' => $token,
                'errors' => ['token' => 'This password reset link is invalid or has expired.'],
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
                'errors' => ['password' => 'Password must be between 8 and 4096 characters.'],
            ]);
        }

        if ($password !== $confirmation) {
            return $this->inertia($request, 'auth/reset-password', [
                'token' => $token,
                'email' => $resetToken->email,
                'errors' => ['password_confirmation' => 'Passwords do not match.'],
            ]);
        }

        $user = $this->userRepository->findByEmail($resetToken->email);
        if ($user === null) {
            return $this->inertia($request, 'auth/reset-password', [
                'token' => $token,
                'email' => $resetToken->email,
                'errors' => ['email' => 'User not found.'],
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
                'errors' => ['password' => 'New password must be different from current password.'],
            ]);
        }

        $user->password = $this->hasher->hashPassword($user, $password);
        $user->passwordChangedAt = new \DateTimeImmutable();
        $this->em->remove($resetToken);
        $this->em->flush();

        return $this->redirectToRoute('app_login');
    }
}
