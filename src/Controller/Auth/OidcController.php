<?php

namespace App\Controller\Auth;

use App\Security\Oidc\OidcProviderManager;
use App\Repository\AppSettingsRepository;
use App\Service\WebhookSecretService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class OidcController extends AbstractController
{
    public function __construct(
        private OidcProviderManager $oidcManager,
        private AppSettingsRepository $appSettingsRepo,
        private WebhookSecretService $secretService,
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire(service: 'limiter.oidc_limiter')]
        private ?RateLimiterFactory $oidcLimiter = null,
        private ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters);
        }
        return $default !== null ? strtr($default, $parameters) : $id;
    }

    #[Route('/oidc/start/{providerId}', name: 'oidc_start')]
    public function start(string $providerId, Request $request): Response
    {
        if ($this->oidcLimiter !== null) {
            $limiter = $this->oidcLimiter->create($request->getClientIp() ?: '127.0.0.1');
            if (!$limiter->consume()->isAccepted()) {
                return new Response(
                    $this->trans('oidc.rate_limit_exceeded', [], 'Too many OIDC login attempts. Please retry later.'),
                    429,
                    ['Retry-After' => 60]
                );
            }
        }

        if (!preg_match('/^[a-zA-Z0-9_\-]{1,64}$/', $providerId)) {
            throw $this->createNotFoundException($this->trans('oidc.provider_not_found', [], 'Unknown or disabled OIDC provider.'));
        }

        // Determiner si c'est admin ou end-user
        $adminProviders = $this->getAdminProviders();

        $provider = null;
        $isAdmin = false;
        $clientId = '';
        $clientSecret = '';
        $issuer = '';

        foreach ($adminProviders as $ap) {
            if ($ap['id'] === $providerId && ($ap['enabled'] ?? false)) {
                $provider = $ap;
                $isAdmin = true;
                $clientId = $ap['clientId'];
                $clientSecret = $ap['clientSecret'];
                $issuer = $ap['issuer'];
                break;
            }
        }

        if (!$provider) {
            throw $this->createNotFoundException($this->trans('oidc.provider_not_found', [], 'Unknown or disabled OIDC provider.'));
        }

        // Decrypter le secret si chiffre
        if (str_starts_with($clientSecret, 'enc:')) {
            try {
                $clientSecret = $this->secretService->decrypt(substr($clientSecret, 4));
            } catch (\Throwable) {
                throw $this->createNotFoundException($this->trans('oidc.provider_not_found', [], 'Unknown or disabled OIDC provider.'));
            }
        }

        $config = $this->oidcManager->discover($issuer);

        $result = $this->oidcManager->generateState('admin', $providerId, null);

        $redirectUri = $this->urlGenerator->generate('oidc_check', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $challenge = $this->oidcManager->computeCodeChallenge($result['codeVerifier']);
        $authUrl = $this->oidcManager->buildAuthorizationUrl(
            $config,
            $redirectUri,
            $challenge,
            $result['state'],
            $result['nonce'],
            $clientId,
        );

        $redirect = $this->redirect($authUrl);
        $redirect->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $redirect->headers->set('Pragma', 'no-cache');
        return $redirect;
    }

    #[Route('/oidc/check', name: 'oidc_check')]
    public function check(Request $request): Response
    {
        // When user cancels or OIDC provider returns an error without code
        $error = $request->query->get('error');
        if ($request->hasSession()) {
            $session = $request->getSession();
            $session->remove('oidc_state');
            $session->remove('oidc_code_verifier');
            $session->remove('oidc_nonce');
        }

        $errorKey = ($error === 'access_denied') ? 'oidc.cancelled' : 'oidc.error_failed';
        return $this->redirectToRoute('app_login', ['error' => $errorKey]);
    }

    /** @return array<array{id: string, name: string, issuer: string, clientId: string, clientSecret: string, enabled: bool}> */
    public function getAdminProviders(): array
    {
        $settings = $this->appSettingsRepo->getOrCreate();
        $providers = $settings->oauthProviders['oidcProviders'] ?? [];
        return $providers;
    }
}
