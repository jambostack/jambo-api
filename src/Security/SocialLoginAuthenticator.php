<?php

namespace App\Security;

use App\Service\SocialLoginService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class SocialLoginAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private SocialLoginService $socialLogin,
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
        $state = $request->query->get('state');

        if (!$code || !$provider) {
            throw new AuthenticationException('No authorization code provided.');
        }

        if (!in_array($provider, ['google', 'microsoft', 'github', 'gitlab'], true)) {
            throw new AuthenticationException("Unknown provider: $provider");
        }

        // Protection anti-CSRF OAuth : vérification stricte du state avec session active obligatoire
        if (!$request->hasSession()) {
            throw new \Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException(
                'OAuth authentication requires an active session.'
            );
        }

        $sessionKey = 'oauth2state_' . $provider;
        $savedState = $request->getSession()->get($sessionKey);
        if (empty($savedState) || empty($state) || !hash_equals((string) $savedState, (string) $state)) {
            throw new \Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException(
                'Invalid or expired OAuth CSRF state parameter.'
            );
        }
        // Consommer immédiatement le state pour prévenir toute attaque par rejeu
        $request->getSession()->remove($sessionKey);

        $verifierKey = 'oauth2verifier_' . $provider;
        $codeVerifier = $request->getSession()->get($verifierKey);
        if ($codeVerifier !== null) {
            $request->getSession()->remove($verifierKey);
        }

        $redirectUri = $this->urlGenerator->generate(
            'connect_check',
            ['provider' => $provider],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        try {
            $socialUser = $this->socialLogin->getUserFromProvider(
                $provider,
                (string) $code,
                $redirectUri,
                null,
                is_string($codeVerifier) ? $codeVerifier : null,
            );
            $user = $this->socialLogin->findOrCreateAdminUser($socialUser, $provider);
        } catch (\Throwable $e) {
            throw new \Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException(
                'Social authentication failed: ' . $e->getMessage(),
                [],
                0,
                $e,
            );
        }

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
