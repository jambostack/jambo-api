<?php

namespace App\Controller\Auth;

use App\Controller\InertiaController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class ConfirmablePasswordController extends InertiaController
{
    public function __construct(
        private UserPasswordHasherInterface $hasher,
    ) {}

    #[Route('/confirm-password', name: 'app_confirm_password', methods: ['GET'])]
    public function show(Request $request): Response
    {
        return $this->inertia($request, 'auth/confirm-password');
    }

    #[Route('/confirm-password', name: 'app_confirm_password_store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $user = $this->getUser();
        $password = $request->getPayload()->getString('password');

        if (!$this->hasher->isPasswordValid($user, $password)) {
            return $this->json(['errors' => ['password' => 'The provided password was incorrect.']], 422);
        }

        // Mark session as password-confirmed
        $request->getSession()->set('auth.password_confirmed_at', time());

        $intended = $request->getSession()->get('url.intended', '/');
        // Reject absolute URLs to prevent open redirect attacks
        if (!str_starts_with($intended, '/') || str_starts_with($intended, '//')) {
            $intended = '/';
        }
        return $this->redirect($intended);
    }
}
