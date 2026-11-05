<?php

namespace App\Controller\Auth;

use App\Controller\InertiaController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;

class ConfirmablePasswordController extends InertiaController
{
    public function __construct(
        private UserPasswordHasherInterface $hasher,
        #[Autowire(service: 'limiter.login_limiter')]
        private ?RateLimiterFactory $loginLimiter = null,
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
        if (!$user instanceof \App\Entity\User) {
            return $this->json(['error' => $this->trans('auth.confirm_password.auth_required', [], 'Authentication required')], 401);
        }

        if ($this->loginLimiter !== null) {
            $identifier = ($request->getClientIp() ?: '127.0.0.1') . '_' . $user->id;
            if (!$this->loginLimiter->create($identifier)->consume()->isAccepted()) {
                return $this->json(['error' => $this->trans('auth.confirm_password.rate_limit_exceeded', [], 'Too many confirmation attempts. Please retry later.')], 429);
            }
        }

        try {
            $password = $request->getPayload()->getString('password');
        } catch (\Throwable) {
            return $this->json(['error' => $this->trans('auth.confirm_password.invalid_json', [], 'Invalid JSON body')], 400);
        }

        if (!$this->hasher->isPasswordValid($user, $password)) {
            return $this->json(['errors' => ['password' => $this->trans('auth.confirm_password.incorrect_password', [], 'The provided password was incorrect.')]], 422);
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
