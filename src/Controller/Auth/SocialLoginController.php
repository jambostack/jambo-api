<?php

namespace App\Controller\Auth;

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
    public function __construct(
        private SocialLoginService $socialLogin,
        #[Autowire(service: 'limiter.social_auth_limiter')]
        private ?RateLimiterFactory $socialAuthLimiter = null,
        private ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters);
        }
        return $default !== null ? strtr($default, $parameters) : $id;
    }

    #[Route('/connect/{provider}', name: 'connect_start')]
    public function connect(string $provider, Request $request): Response
    {
        if ($this->socialAuthLimiter !== null) {
            $limiter = $this->socialAuthLimiter->create($request->getClientIp() ?: '127.0.0.1');
            if (!$limiter->consume()->isAccepted()) {
                return new Response(
                    $this->trans('auth.social.rate_limit_exceeded', [], 'Too many social login attempts. Please retry later.'),
                    429,
                    ['Retry-After' => 60]
                );
            }
        }

        if (!in_array($provider, ['google', 'microsoft', 'github', 'gitlab'], true)) {
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

        $redirect = $this->redirect($authData['url']);
        $redirect->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $redirect->headers->set('Pragma', 'no-cache');
        return $redirect;
    }

    #[Route('/connect/{provider}/check', name: 'connect_check')]
    public function check(string $provider, Request $request): Response
    {
        // When user cancels or OAuth provider returns an error without code
        $error = $request->query->get('error');
        if ($request->hasSession()) {
            $session = $request->getSession();
            $session->remove('oauth2state_' . $provider);
            $session->remove('oauth2verifier_' . $provider);
        }

        $errorKey = ($error === 'access_denied') ? 'auth.social.cancelled' : 'auth.social.error_failed';
        return $this->redirectToRoute('app_login', ['error' => $errorKey]);
    }
}
