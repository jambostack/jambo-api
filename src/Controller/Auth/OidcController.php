<?php

namespace App\Controller\Auth;

use App\Security\Oidc\OidcProviderManager;
use App\Repository\AppSettingsRepository;
use App\Service\AuditService;
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
    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-cache, no-store, must-revalidate',
        'Pragma'                 => 'no-cache',
    ];

    public function __construct(
        private OidcProviderManager $oidcManager,
        private AppSettingsRepository $appSettingsRepo,
        private WebhookSecretService $secretService,
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire(service: 'limiter.oidc_limiter')]
        private ?RateLimiterFactory $oidcLimiter = null,
        private ?TranslatorInterface $translator = null,
        private ?AuditService $auditService = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters);
        }
        return $default !== null ? strtr($default, $parameters) : $id;
    }

    private function audit(string $toolName, array $data = [], string $status = 'success'): void
    {
        if ($this->auditService !== null) {
            $this->auditService->log(
                toolName: $toolName,
                project: null,
                input: $data,
                output: null,
                status: $status,
                source: 'oidc',
            );
            $this->auditService->flush();
        }
    }

    #[Route('/oidc/start/{providerId}', name: 'oidc_start')]
    public function start(string $providerId, Request $request): Response
    {
        $clientIp = $request->getClientIp() ?: '127.0.0.1';

        if ($this->oidcLimiter !== null) {
            $limiter = $this->oidcLimiter->create($clientIp);
            if (!$limiter->consume()->isAccepted()) {
                $this->audit('auth_oidc_rate_limited', ['provider_id' => $providerId, 'ip' => $clientIp], 'failure');
                return new Response(
                    $this->trans('oidc.rate_limit_exceeded', [], 'Too many OIDC login attempts. Please retry later.'),
                    429,
                    array_merge(['Retry-After' => '60'], self::SECURITY_HEADERS)
                );
            }
        }

        if (!preg_match('/^[a-zA-Z0-9_\-]{1,64}$/', $providerId)) {
            $this->audit('auth_oidc_unknown_provider', ['provider_id' => $providerId, 'ip' => $clientIp], 'failure');
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
            $this->audit('auth_oidc_unknown_provider', ['provider_id' => $providerId, 'ip' => $clientIp], 'failure');
            throw $this->createNotFoundException($this->trans('oidc.provider_not_found', [], 'Unknown or disabled OIDC provider.'));
        }

        // Decrypter le secret si chiffre
        if (str_starts_with($clientSecret, 'enc:')) {
            try {
                $clientSecret = $this->secretService->decrypt(substr($clientSecret, 4));
            } catch (\Throwable) {
                $this->audit('auth_oidc_unknown_provider', ['provider_id' => $providerId, 'ip' => $clientIp], 'failure');
                throw $this->createNotFoundException($this->trans('oidc.provider_not_found', [], 'Unknown or disabled OIDC provider.'));
            }
        }

        try {
            $config = $this->oidcManager->discover($issuer);
        } catch (\Throwable $e) {
            $this->audit('auth_oidc_discovery_failed', [
                'provider_id' => $providerId,
                'issuer'      => $issuer,
                'error'       => $e->getMessage(),
                'ip'          => $clientIp,
            ], 'failure');
            $resp = $this->redirectToRoute('app_login', ['error' => 'oidc.discovery_failed']);
            foreach (self::SECURITY_HEADERS as $headerName => $headerValue) {
                $resp->headers->set($headerName, $headerValue);
            }
            return $resp;
        }

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

        $this->audit('auth_oidc_start', ['provider_id' => $providerId, 'ip' => $clientIp]);

        $redirect = $this->redirect($authUrl);
        foreach (self::SECURITY_HEADERS as $headerName => $headerValue) {
            $redirect->headers->set($headerName, $headerValue);
        }
        return $redirect;
    }

    #[Route('/oidc/check', name: 'oidc_check')]
    public function check(Request $request): Response
    {
        $clientIp = $request->getClientIp() ?: '127.0.0.1';

        if ($this->oidcLimiter !== null) {
            $limiter = $this->oidcLimiter->create($clientIp);
            if (!$limiter->consume()->isAccepted()) {
                $this->audit('auth_oidc_callback_rate_limited', ['ip' => $clientIp], 'failure');
                $resp = $this->redirectToRoute('app_login', ['error' => 'oidc.callback_rate_limited']);
                foreach (self::SECURITY_HEADERS as $headerName => $headerValue) {
                    $resp->headers->set($headerName, $headerValue);
                }
                return $resp;
            }
        }

        // When user cancels or OIDC provider returns an error without code
        $error = $request->query->get('error');
        if ($request->hasSession()) {
            $session = $request->getSession();
            $session->remove('oidc_state');
            $session->remove('oidc_code_verifier');
            $session->remove('oidc_nonce');
        }

        if ($error !== null && $error !== '') {
            $this->audit('auth_oidc_callback_cancelled', ['error' => (string) $error, 'ip' => $clientIp], 'failure');
        }

        $errorKey = ($error === 'access_denied') ? 'oidc.cancelled' : 'oidc.error_failed';
        $redirect = $this->redirectToRoute('app_login', ['error' => $errorKey]);
        foreach (self::SECURITY_HEADERS as $headerName => $headerValue) {
            $redirect->headers->set($headerName, $headerValue);
        }
        return $redirect;
    }

    /** @return array<array{id: string, name: string, issuer: string, clientId: string, clientSecret: string, enabled: bool}> */
    public function getAdminProviders(): array
    {
        $settings = $this->appSettingsRepo->getOrCreate();
        $providers = $settings->oauthProviders['oidcProviders'] ?? [];
        return $providers;
    }
}
