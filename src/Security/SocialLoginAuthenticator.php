<?php

namespace App\Security;

use App\Service\SocialLoginService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class SocialLoginAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private SocialLoginService $socialLogin,
        private UserProviderInterface $userProvider,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'connect_check'
            && $request->query->has('code');
    }

    public function authenticate(Request $request): Passport
    {
        $provider = $request->attributes->get('provider');
        $code = $request->query->get('code');

        if (!$code || !$provider) {
            throw new AuthenticationException('No authorization code provided.');
        }

        if (!in_array($provider, ['google', 'microsoft', 'github', 'gitlab'], true)) {
            throw new AuthenticationException("Unknown provider: $provider");
        }

        $redirectUri = $this->urlGenerator->generate(
            'connect_check',
            ['provider' => $provider],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $socialUser = $this->socialLogin->getUserFromProvider($provider, (string) $code, $redirectUri);
        $user = $this->socialLogin->findOrCreateAdminUser($socialUser, $provider);

        return new SelfValidatingPassport(
            new UserBadge($user->getUserIdentifier(), fn () => $user),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Laisser le TwoFactorRedirectSubscriber ou le redirect handler gérer
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new RedirectResponse(
            $this->urlGenerator->generate('app_login') . '?error=social_auth_failed',
        );
    }
}
