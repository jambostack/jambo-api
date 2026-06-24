<?php

namespace App\Controller\Auth;

use App\Security\Oidc\OidcProviderManager;
use App\Repository\AppSettingsRepository;
use App\Service\WebhookSecretService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class OidcController extends AbstractController
{
    public function __construct(
        private OidcProviderManager $oidcManager,
        private AppSettingsRepository $appSettingsRepo,
        private WebhookSecretService $secretService,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    #[Route('/oidc/start/{providerId}', name: 'oidc_start')]
    public function start(string $providerId, Request $request): Response
    {
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
            throw $this->createNotFoundException('Unknown or disabled OIDC provider.');
        }

        // Decrypter le secret si chiffre
        if (str_starts_with($clientSecret, 'enc:')) {
            $clientSecret = $this->secretService->decrypt(substr($clientSecret, 4));
        }

        $config = $this->oidcManager->discover($issuer);

        $result = $this->oidcManager->generateState('admin', $providerId, null);

        $redirectUri = $this->urlGenerator->generate('oidc_check', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $challenge = $this->oidcManager->computeCodeChallenge($result['codeVerifier']);
        $authUrl = $this->oidcManager->buildAuthorizationUrl(
            $config, $redirectUri, $challenge, $result['state'], $result['nonce'],
        );

        // Injecter le client_id dans l'URL
        $authUrl = preg_replace('/client_id=/', 'client_id=' . urlencode($clientId), $authUrl);

        return $this->redirect($authUrl);
    }

    #[Route('/oidc/check', name: 'oidc_check')]
    public function check(): Response
    {
        // Intercepte par OidcAuthenticator
        throw new \LogicException('This route is handled by OidcAuthenticator.');
    }

    /** @return array<array{id: string, name: string, issuer: string, clientId: string, clientSecret: string, enabled: bool}> */
    public function getAdminProviders(): array
    {
        $settings = $this->appSettingsRepo->getOrCreate();
        $providers = $settings->oauthProviders['oidcProviders'] ?? [];
        return $providers;
    }
}
