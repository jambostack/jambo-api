<?php

namespace App\Controller;

use App\Service\SocialLoginService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends InertiaController
{
    public function __construct(
        private ?SocialLoginService $socialLogin = null,
    ) {}

    #[Route('/login', name: 'app_login')]
    public function login(
        Request $request,
        AuthenticationUtils $authUtils,
        CsrfTokenManagerInterface $csrfTokenManager,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $socialProviders = $this->socialLogin ? $this->socialLogin->getAvailableProviders() : [];

        return $this->inertia($request, 'auth/login', [
            'error'           => $authUtils->getLastAuthenticationError()?->getMessageKey(),
            'lastUsername'    => $authUtils->getLastUsername(),
            'csrfToken'       => $csrfTokenManager->getToken('authenticate')->getValue(),
            'socialProviders' => $socialProviders,
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        // Intercepted by Symfony's security layer — controller body never executes
    }
}
