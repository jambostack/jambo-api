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

        $authData = $this->socialLogin->getAuthorizationUrlAndState($provider, $redirectUri);
        if ($request->hasSession()) {
            $request->getSession()->set('oauth2state_' . $provider, $authData['state']);
        }

        return $this->redirect($authData['url']);
    }

    #[Route('/connect/{provider}/check', name: 'connect_check')]
    public function check(string $provider): Response
    {
        // Jamais exécuté — intercepté par SocialLoginAuthenticator
        throw new \LogicException('This route is handled by SocialLoginAuthenticator.');
    }
}
