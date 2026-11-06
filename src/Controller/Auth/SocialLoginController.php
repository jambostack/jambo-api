<?php

namespace App\Controller\Auth;

use App\Service\AuditService;
use App\Service\SocialLoginService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class SocialLoginController extends AbstractController
{
    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-cache, no-store, must-revalidate',
        'Pragma'                 => 'no-cache',
    ];

    public function __construct(
        private SocialLoginService $socialLogin,
        #[Autowire(service: 'limiter.social_auth_limiter')]
        private ?RateLimiterFactory $socialAuthLimiter = null,
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
                source: 'social_auth',
            );
            $this->auditService->flush();
        }
    }

    #[Route('/connect/{provider}', name: 'connect_start')]
    public function connect(string $provider, Request $request): Response
    {
        $clientIp = $request->getClientIp() ?: '127.0.0.1';

        if ($this->socialAuthLimiter !== null) {
            $limiter = $this->socialAuthLimiter->create($clientIp);
            if (!$limiter->consume()->isAccepted()) {
                $this->audit('auth_social_rate_limited', ['provider' => $provider, 'ip' => $clientIp], 'failure');
                return new Response(
                    $this->trans('auth.social.rate_limit_exceeded', [], 'Too many social login attempts. Please retry later.'),
                    429,
                    array_merge(['Retry-After' => '60'], self::SECURITY_HEADERS)
                );
            }
        }

        if (!in_array($provider, ['google', 'microsoft', 'github', 'gitlab'], true)) {
            $this->audit('auth_social_unknown_provider', ['provider' => $provider, 'ip' => $clientIp], 'failure');
            throw $this->createNotFoundException($this->trans('auth.social.unknown_provider', [], 'Unknown provider.'));
        }

        $redirectUri = $this->generateUrl(
            'connect_check',
            ['provider' => $provider],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $codeVerifier = bin2hex(random_bytes(32));
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $authData = $this->socialLogin->getAuthorizationUrlAndState(
            $provider,
            $redirectUri,
            null,
            $codeChallenge,
            'S256',
        );
        if ($request->hasSession()) {
            $session = $request->getSession();
            if (!$session->isStarted()) {
                $session->start();
            }
            $session->set('oauth2state_' . $provider, $authData['state']);
            $session->set('oauth2verifier_' . $provider, $codeVerifier);
        }

        $this->audit('auth_social_connect_start', ['provider' => $provider, 'ip' => $clientIp]);

        $redirect = $this->redirect($authData['url']);
        foreach (self::SECURITY_HEADERS as $headerName => $headerValue) {
            $redirect->headers->set($headerName, $headerValue);
        }
        return $redirect;
    }

    #[Route('/connect/{provider}/check', name: 'connect_check')]
    public function check(string $provider, Request $request): Response
    {
        $clientIp = $request->getClientIp() ?: '127.0.0.1';

        if ($this->socialAuthLimiter !== null) {
            $limiter = $this->socialAuthLimiter->create($clientIp);
            if (!$limiter->consume()->isAccepted()) {
                $this->audit('auth_social_callback_rate_limited', ['provider' => $provider, 'ip' => $clientIp], 'failure');
                $resp = $this->redirectToRoute('app_login', ['error' => 'auth.social.callback_rate_limited']);
                foreach (self::SECURITY_HEADERS as $headerName => $headerValue) {
                    $resp->headers->set($headerName, $headerValue);
                }
                return $resp;
            }
        }

        // When user cancels or OAuth provider returns an error without code
        $error = $request->query->get('error');
        if ($request->hasSession()) {
            $session = $request->getSession();
            $session->remove('oauth2state_' . $provider);
            $session->remove('oauth2verifier_' . $provider);
        }

        if ($error !== null && $error !== '') {
            $this->audit('auth_social_callback_cancelled', ['provider' => $provider, 'error' => (string) $error, 'ip' => $clientIp], 'failure');
        }

        $errorKey = ($error === 'access_denied') ? 'auth.social.cancelled' : 'auth.social.error_failed';
        $redirect = $this->redirectToRoute('app_login', ['error' => $errorKey]);
        foreach (self::SECURITY_HEADERS as $headerName => $headerValue) {
            $redirect->headers->set($headerName, $headerValue);
        }
        return $redirect;
    }
}
