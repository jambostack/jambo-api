<?php

namespace App\Controller;

use App\Repository\AppSettingsRepository;
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
        private ?AppSettingsRepository $appSettingsRepo = null,
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

        $oidcProviders = [];
        if ($this->appSettingsRepo) {
            $settings = $this->appSettingsRepo->getOrCreate();
            $allOidc = $settings->oauthProviders['oidcProviders'] ?? [];
            foreach ($allOidc as $p) {
                if ($p['enabled'] ?? false) {
                    $oidcProviders[] = ['id' => $p['id'], 'name' => $p['name'] ?? 'SSO'];
                }
            }
        }

        return $this->inertia($request, 'auth/login', [
            'error'           => $authUtils->getLastAuthenticationError()?->getMessageKey(),
            'lastUsername'    => $authUtils->getLastUsername(),
            'csrfToken'       => $csrfTokenManager->getToken('authenticate')->getValue(),
            'socialProviders' => $socialProviders,
            'oidcProviders'   => $oidcProviders,
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        // Intercepted by Symfony's security layer — controller body never executes
    }
}
