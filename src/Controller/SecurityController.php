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

        $error = $authUtils->getLastAuthenticationError()?->getMessageKey();
        if (!$error && $request->query->has('error')) {
            $rawError = (string) $request->query->get('error');
            $allowedErrors = [
                'oidc_failed' => 'oidc.error_failed',
                'social_auth_failed' => 'auth.social.error_failed',
                'auth.social.cancelled' => 'auth.social.cancelled',
                'auth.social.error_failed' => 'auth.social.error_failed',
                'auth.social.session_required' => 'auth.social.session_required',
                'auth.social.invalid_csrf_state' => 'auth.social.invalid_csrf_state',
                'auth.social.email_not_verified' => 'auth.social.email_not_verified',
                'auth.social.code_required' => 'auth.social.code_required',
                'auth.social.unknown_provider' => 'auth.social.unknown_provider',
                'auth.social.rate_limit_exceeded' => 'auth.social.rate_limit_exceeded',
                'oidc.cancelled' => 'oidc.cancelled',
                'oidc.error_failed' => 'oidc.error_failed',
                'oidc.session_expired' => 'oidc.session_expired',
                'oidc.invalid_state' => 'oidc.invalid_state',
                'oidc.provider_not_found' => 'oidc.provider_not_found',
                'oidc.missing_parameters' => 'oidc.missing_parameters',
            ];
            if (isset($allowedErrors[$rawError])) {
                $error = $allowedErrors[$rawError];
            }
        }

        return $this->inertia($request, 'auth/login', [
            'error'           => $error,
            'lastUsername'    => $authUtils->getLastUsername(),
            'csrfToken'       => $csrfTokenManager->getToken('authenticate')->getValue(),
            'canResetPassword' => true,
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
