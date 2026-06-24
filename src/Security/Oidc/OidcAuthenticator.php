<?php

namespace App\Security\Oidc;

use App\Dto\SocialUser;
use App\Entity\EndUser;
use App\Entity\User;
use App\Repository\EndUserRepository;
use App\Repository\UserRepository;
use App\Security\Oidc\OidcProviderManager;
use App\Repository\AppSettingsRepository;
use App\Service\WebhookSecretService;
use App\Service\EndUserJwtService;
use Doctrine\ORM\EntityManagerInterface;
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
use Symfony\Component\Uid\Uuid;

class OidcAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private OidcProviderManager $oidcManager,
        private UserRepository $userRepo,
        private EndUserRepository $endUserRepo,
        private EntityManagerInterface $em,
        private UrlGeneratorInterface $urlGenerator,
        private AppSettingsRepository $appSettingsRepo,
        private WebhookSecretService $secretService,
        private ?EndUserJwtService $endUserJwtService = null,
    ) {}

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'oidc_check'
            && $request->query->has('code');
    }

    public function authenticate(Request $request): Passport
    {
        $code = $request->query->get('code');
        $stateJwt = $request->query->get('state');

        if (!$code || !$stateJwt) {
            throw new AuthenticationException('Missing code or state parameter.');
        }

        // 1. Valider le state
        $payload = $this->oidcManager->validateState($stateJwt);

        // 2. Recuperer code_verifier + nonce de la session
        $session = $request->getSession();
        $codeVerifier = $session->get('oidc_code_verifier');
        $expectedNonce = $session->get('oidc_nonce');
        $session->remove('oidc_code_verifier');
        $session->remove('oidc_nonce');

        if (!$codeVerifier || !$expectedNonce) {
            throw new AuthenticationException('Session expired. Please try again.');
        }

        // 3. Resoudre le provider
        $type = $payload['type'];
        $providerId = $payload['providerId'] ?? null;
        $projectUuid = $payload['projectUuid'] ?? null;

        if ($type === 'admin') {
            $provider = $this->getAdminProvider($providerId);
            if (!$provider) {
                throw new AuthenticationException('Unknown OIDC provider.');
            }
        } else {
            throw new AuthenticationException('End-user OIDC not yet implemented.');
        }

        $clientId = $provider['clientId'];
        $clientSecret = $provider['clientSecret'];
        if (str_starts_with($clientSecret, 'enc:')) {
            $clientSecret = $this->secretService->decrypt(substr($clientSecret, 4));
        }
        $issuer = $provider['issuer'];

        $config = $this->oidcManager->discover($issuer);

        $redirectUri = $this->urlGenerator->generate('oidc_check', [], UrlGeneratorInterface::ABSOLUTE_URL);

        // 4. Echanger le code
        $tokens = $this->oidcManager->exchangeCode(
            $config, (string) $code, $redirectUri, $clientId, $clientSecret, $codeVerifier,
        );

        // 5. Valider l'id_token
        $claims = $this->oidcManager->validateIdToken(
            $tokens['idToken'], $config, $clientId, $clientSecret, $expectedNonce,
        );

        // 6. Trouver ou creer l'utilisateur
        $socialUser = new SocialUser(
            providerId: $claims['sub'],
            email: $claims['email'],
            username: $claims['name'],
            avatarUrl: $claims['picture'],
        );

        $user = $this->findOrCreateAdminUser($socialUser, $issuer);

        return new SelfValidatingPassport(
            new UserBadge($user->getUserIdentifier(), fn () => $user),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null; // Laisser le redirect handler gerer
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new RedirectResponse(
            $this->urlGenerator->generate('app_login') . '?error=oidc_failed',
        );
    }

    // --- Helpers -----------------------------------------------------------

    private function findOrCreateAdminUser(SocialUser $socialUser, string $issuer): User
    {
        // 1. Chercher par (sub, issuer)
        $user = $this->userRepo->findOneByOidc($socialUser->providerId, $issuer);
        if ($user) {
            return $user;
        }

        // 2. Chercher par email
        $user = $this->userRepo->findByEmail($socialUser->email);
        if ($user) {
            $user->oidcSub = $socialUser->providerId;
            $user->oidcIssuer = $issuer;
            $this->em->flush();
            return $user;
        }

        // 3. Creer
        $user = new User();
        $user->email = $socialUser->email;
        $user->name = $socialUser->username;
        $user->password = null;
        $user->oidcSub = $socialUser->providerId;
        $user->oidcIssuer = $issuer;
        $user->locale = 'en';
        $user->uuid = Uuid::v4();

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** @return array|null */
    private function getAdminProvider(?string $providerId): ?array
    {
        $settings = $this->appSettingsRepo->getOrCreate();
        $providers = $settings->oauthProviders['oidcProviders'] ?? [];
        foreach ($providers as $p) {
            if (($p['id'] ?? '') === $providerId && ($p['enabled'] ?? false)) {
                return $p;
            }
        }
        return null;
    }
}
