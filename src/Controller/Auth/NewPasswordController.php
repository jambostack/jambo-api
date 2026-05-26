<?php

namespace App\Controller\Auth;

use App\Controller\InertiaController;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class NewPasswordController extends InertiaController
{
    public function __construct(
        private PasswordResetTokenRepository $tokenRepository,
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
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

        return $this->inertia($request, 'auth/reset-password', ['token' => $token]);
    }

    #[Route('/reset-password/{token}', name: 'app_reset_password_store', methods: ['POST'])]
    public function store(Request $request, string $token): Response
    {
        $resetToken = $this->tokenRepository->findValidByToken($token);

        if ($resetToken === null) {
            return $this->json(['errors' => ['token' => 'This link is invalid or has expired.']], 422);
        }

        $password = $request->getPayload()->getString('password');
        $confirmation = $request->getPayload()->getString('password_confirmation');

        if (strlen($password) < 8) {
            return $this->json(['errors' => ['password' => 'Password must be at least 8 characters.']], 422);
        }

        if ($password !== $confirmation) {
            return $this->json(['errors' => ['password_confirmation' => 'Passwords do not match.']], 422);
        }

        $user = $this->userRepository->findByEmail($resetToken->email);
        if ($user === null) {
            return $this->json(['errors' => ['email' => 'User not found.']], 422);
        }

        $user->password = $this->hasher->hashPassword($user, $password);
        $this->em->remove($resetToken);
        $this->em->flush();

        return $this->redirectToRoute('app_login');
    }
}
