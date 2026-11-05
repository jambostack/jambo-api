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
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private ?TranslatorInterface $translator = null,
    ) {}

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            return $this->translator->trans($id, $parameters);
        }
        return $default !== null ? strtr($default, $parameters) : $id;
    }

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
            throw new CustomUserMessageAuthenticationException(
                $this->trans('oidc.missing_parameters', [], 'Missing code or state parameter.')
            );
        }

        if (!$request->hasSession()) {
            throw new CustomUserMessageAuthenticationException(
                $this->trans('oidc.session_expired', [], 'Session expired. Please try again.')
            );
        }

        $session = $request->getSession();

        // 1. Valider le state et vérifier la liaison CSRF de session
        try {
            $payload = $this->oidcManager->validateState((string) $stateJwt);
        } catch (\Throwable $e) {
            throw new CustomUserMessageAuthenticationException(
                $this->trans('oidc.invalid_state', [], 'Invalid or expired OIDC state parameter.'),
                [],
                0,
                $e,
            );
        }

        $savedState = $session->get('oidc_state');
        $session->remove('oidc_state');
        if (empty($savedState) || !hash_equals((string) $savedState, (string) $stateJwt)) {
            throw new CustomUserMessageAuthenticationException(
                $this->trans('oidc.invalid_state', [], 'Invalid or expired OIDC state parameter.')
            );
        }

        // 2. Récupérer code_verifier + nonce de la session avec consommation unique
        $codeVerifier = $session->get('oidc_code_verifier');
        $expectedNonce = $session->get('oidc_nonce');
        $session->remove('oidc_code_verifier');
        $session->remove('oidc_nonce');

        if (!$codeVerifier || !$expectedNonce) {
            throw new CustomUserMessageAuthenticationException(
                $this->trans('oidc.session_expired', [], 'Session expired. Please try again.')
            );
        }

        // 3. Résoudre le provider
        $type = $payload['type'] ?? 'admin';
        $providerId = $payload['providerId'] ?? null;
        $projectUuid = $payload['projectUuid'] ?? null;

        if ($type === 'admin') {
            $provider = $this->getAdminProvider($providerId);
            if (!$provider) {
                throw new CustomUserMessageAuthenticationException(
                    $this->trans('oidc.provider_not_found', [], 'Unknown or disabled OIDC provider.')
                );
            }
        } else {
            throw new CustomUserMessageAuthenticationException(
                $this->trans('oidc.end_user_not_supported', [], 'End-user OIDC not yet implemented.')
            );
        }

        $clientId = $provider['clientId'];
        $clientSecret = $provider['clientSecret'];
        if (str_starts_with($clientSecret, 'enc:')) {
            $clientSecret = $this->secretService->decrypt(substr($clientSecret, 4));
        }
        $issuer = $provider['issuer'];

        $config = $this->oidcManager->discover($issuer);

        $redirectUri = $this->urlGenerator->generate('oidc_check', [], UrlGeneratorInterface::ABSOLUTE_URL);

        // 4. Échanger le code
        try {
            $tokens = $this->oidcManager->exchangeCode(
                $config,
                (string) $code,
                $redirectUri,
                $clientId,
                $clientSecret,
                (string) $codeVerifier,
            );
        } catch (\Throwable $e) {
            throw new CustomUserMessageAuthenticationException(
                $this->trans('oidc.error_failed', [], 'SSO login failed. Please try again.'),
                [],
                0,
                $e,
            );
        }

        // 5. Valider l'id_token
        try {
            $claims = $this->oidcManager->validateIdToken(
                $tokens['idToken'],
                $config,
                $clientId,
                $clientSecret,
                (string) $expectedNonce,
            );
        } catch (\Throwable $e) {
            throw new CustomUserMessageAuthenticationException(
                $this->trans('oidc.invalid_id_token', [], 'Invalid OIDC ID token: ') . $e->getMessage(),
                [],
                0,
                $e,
            );
        }

        // 6. Trouver ou créer l'utilisateur avec validation de l'adresse email et protection anti-takeover
        $socialUser = new SocialUser(
            providerId: $claims['sub'],
            email:      $claims['email'],
            username:   $claims['name'],
            avatarUrl:  $this->sanitizeAvatarUrl($claims['picture'] ?? null),
        );

        $user = $this->findOrCreateAdminUser($socialUser, $issuer, $claims['email_verified'] ?? null);

        return new SelfValidatingPassport(
            new UserBadge($user->getUserIdentifier(), fn () => $user),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null; // Laisser le redirect handler gérer
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new RedirectResponse(
            $this->urlGenerator->generate('app_login') . '?error=oidc_failed',
        );
    }

    // --- Helpers -----------------------------------------------------------

    private function findOrCreateAdminUser(SocialUser $socialUser, string $issuer, ?bool $emailVerified = null): User
    {
        if (empty($socialUser->email) || !filter_var($socialUser->email, FILTER_VALIDATE_EMAIL)) {
            throw new CustomUserMessageAuthenticationException(
                $this->trans('oidc.invalid_email', [], 'The OIDC provider did not return a valid email address.')
            );
        }

        if ($emailVerified === false) {
            throw new CustomUserMessageAuthenticationException(
                $this->trans('oidc.unverified_email', [], 'The email address from the OIDC provider is not verified.')
            );
        }

        // 1. Chercher par (sub, issuer)
        $user = $this->userRepo->findOneByOidc($socialUser->providerId, $issuer);
        if ($user) {
            return $user;
        }

        // 2. Chercher par email pour lier le compte existant
        $user = $this->userRepo->findByEmail($socialUser->email);
        if ($user) {
            $user->oidcSub = $socialUser->providerId;
            $user->oidcIssuer = $issuer;
            $this->em->flush();
            return $user;
        }

        // 3. Créer un nouveau User
        $user = new User();
        $user->email = $socialUser->email;
        $cleanName = preg_replace('/[\x00-\x1F\x7F]/', '', trim($socialUser->username));
        $user->name = mb_substr($cleanName !== '' ? $cleanName : 'OIDC User', 0, 100);
        $user->password = null;
        $user->oidcSub = $socialUser->providerId;
        $user->oidcIssuer = $issuer;
        $user->locale = 'en';
        $user->uuid = Uuid::v4();

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function sanitizeAvatarUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            return null;
        }
        return $url;
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
