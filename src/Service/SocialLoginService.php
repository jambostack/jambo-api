<?php

namespace App\Service;

use App\Dto\SocialUser;
use App\Entity\EndUser;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\AppSettingsRepository;
use App\Repository\EndUserRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Github as GithubProvider;
use League\OAuth2\Client\Provider\Google as GoogleProvider;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use Omines\OAuth2\Client\Provider\Gitlab as GitlabProvider;
use Symfony\Component\Uid\Uuid;
use TheNetworg\OAuth2\Client\Provider\Azure as MicrosoftProvider;

class SocialLoginService
{
    private const PROVIDER_CONFIG = [
        'google'    => ['class' => GoogleProvider::class,    'scopes' => ['openid', 'email', 'profile']],
        'microsoft' => ['class' => MicrosoftProvider::class, 'scopes' => ['openid', 'email', 'profile']],
        'github'    => ['class' => GithubProvider::class,    'scopes' => ['user:email']],
        'gitlab'    => ['class' => GitlabProvider::class,    'scopes' => ['read_user']],
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepo,
        private EndUserRepository $endUserRepo,
        private AppSettingsRepository $appSettingsRepo,
        private WebhookSecretService $secretService,
    ) {}

    // ─── Provider OAuth ────────────────────────────────────────────────────

    /** @return array{clientId: string, clientSecret: string}|null */
    private function getAdminCredentials(string $provider): ?array
    {
        $settings = $this->appSettingsRepo->getOrCreate();
        $providers = $settings->oauthProviders ?? [];
        $config = $providers[$provider] ?? null;

        // Le provider doit être explicitement activé (toggle UI), pas seulement renseigné :
        // sinon le bouton resterait visible après désactivation alors que des identifiants
        // ont été saisis une fois.
        if (!$config || empty($config['enabled']) || empty($config['clientId']) || empty($config['clientSecret'])) {
            return null;
        }

        $clientSecret = $config['clientSecret'];
        // Décrypter si nécessaire (stocké chiffré via WebhookSecretService)
        if (str_starts_with($clientSecret, 'enc:')) {
            try {
                $clientSecret = $this->secretService->decrypt(substr($clientSecret, 4));
            } catch (\Exception) {
                // Le secret n'a pas pu être déchiffré — provider mal configuré
                return null;
            }
        }

        return ['clientId' => $config['clientId'], 'clientSecret' => $clientSecret];
    }

    /** @return array{clientId: string, clientSecret: string}|null */
    public function getProjectCredentials(Project $project, string $provider): ?array
    {
        $security = $project->settings['security'] ?? [];
        $providers = $security['endUserSocialProviders'] ?? [];
        $config = $providers[$provider] ?? null;

        // Idem admin : le provider doit être explicitement activé pour le projet.
        if (!$config || empty($config['enabled']) || empty($config['clientId']) || empty($config['clientSecret'])) {
            return null;
        }

        $clientSecret = $config['clientSecret'];
        if (str_starts_with($clientSecret, 'enc:')) {
            try {
                $clientSecret = $this->secretService->decrypt(substr($clientSecret, 4));
            } catch (\Exception) {
                return null;
            }
        }

        return ['clientId' => $config['clientId'], 'clientSecret' => $clientSecret];
    }

    public function getProviderForAdmin(string $provider): ?AbstractProvider
    {
        $credentials = $this->getAdminCredentials($provider);
        if (!$credentials) {
            return null;
        }

        return $this->buildProvider($provider, $credentials);
    }

    public function getProviderForProject(Project $project, string $provider): ?AbstractProvider
    {
        $credentials = $this->getProjectCredentials($project, $provider);
        if (!$credentials) {
            return null;
        }

        return $this->buildProvider($provider, $credentials);
    }

    /** @param array{clientId: string, clientSecret: string} $credentials */
    private function buildProvider(string $provider, array $credentials): AbstractProvider
    {
        if (!isset(self::PROVIDER_CONFIG[$provider])) {
            throw new \InvalidArgumentException("Unknown provider: $provider");
        }

        $cfg = self::PROVIDER_CONFIG[$provider];
        $class = $cfg['class'];

        $options = [
            'clientId'     => $credentials['clientId'],
            'clientSecret' => $credentials['clientSecret'],
        ];

        // Microsoft / Azure nécessite tenant
        if ($provider === 'microsoft') {
            $options['tenant'] = 'common';
        }

        // GitLab nécessite une instance
        if ($provider === 'gitlab') {
            $options['domain'] = 'https://gitlab.com';
        }

        return new $class($options);
    }

    // ─── URLs ──────────────────────────────────────────────────────────────

    /**
     * Génère l'URL d'autorisation OAuth et renvoie également l'état CSRF (state).
     * Supporte PKCE RFC 7636 via code_challenge et code_challenge_method.
     *
     * @return array{url: string, state: string}
     */
    public function getAuthorizationUrlAndState(
        string $provider,
        string $redirectUri,
        ?array $config = null,
        ?string $codeChallenge = null,
        string $codeChallengeMethod = 'S256',
    ): array {
        if (!isset(self::PROVIDER_CONFIG[$provider])) {
            throw new \InvalidArgumentException("Unknown provider: $provider");
        }

        if ($codeChallenge !== null && !preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $codeChallenge)) {
            throw new \InvalidArgumentException('Invalid PKCE code_challenge format.');
        }

        $oauthProvider = $config
            ? $this->buildProvider($provider, $config)
            : $this->getProviderForAdmin($provider);

        if (!$oauthProvider) {
            throw new \RuntimeException("Provider '$provider' is not configured.");
        }

        $cfg = self::PROVIDER_CONFIG[$provider];
        $options = ['scope' => $cfg['scopes']];
        if ($codeChallenge !== null) {
            $options['code_challenge'] = $codeChallenge;
            $options['code_challenge_method'] = in_array(strtoupper($codeChallengeMethod), ['S256', 'PLAIN'], true)
                ? strtoupper($codeChallengeMethod)
                : 'S256';
        }

        $url = $oauthProvider->getAuthorizationUrl($options);
        $state = (string) $oauthProvider->getState();

        return [
            'url'   => $url,
            'state' => $state,
        ];
    }

    public function getRedirectUrl(string $provider, string $redirectUri, ?array $config = null): string
    {
        return $this->getAuthorizationUrlAndState($provider, $redirectUri, $config)['url'];
    }

    // ─── User from Provider ────────────────────────────────────────────────

    /**
     * Échange le code d'autorisation contre un token et récupère le profil utilisateur.
     * Supporte la validation PKCE RFC 7636 avec code_verifier.
     */
    public function getUserFromProvider(
        string $provider,
        string $code,
        string $redirectUri,
        ?array $config = null,
        ?string $codeVerifier = null,
    ): SocialUser {
        if (!isset(self::PROVIDER_CONFIG[$provider])) {
            throw new \InvalidArgumentException("Unknown provider: $provider");
        }

        if (strlen($code) < 1 || strlen($code) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $code)) {
            throw new \InvalidArgumentException('Invalid authorization code format.');
        }

        if ($codeVerifier !== null && !preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $codeVerifier)) {
            throw new \InvalidArgumentException('Invalid PKCE code_verifier format.');
        }

        $oauthProvider = $config
            ? $this->buildProvider($provider, $config)
            : $this->getProviderForAdmin($provider);

        if (!$oauthProvider) {
            throw new \RuntimeException("Provider '$provider' is not configured.");
        }

        $tokenParams = ['code' => $code, 'redirect_uri' => $redirectUri];
        if ($codeVerifier !== null) {
            $tokenParams['code_verifier'] = $codeVerifier;
        }

        $token = $oauthProvider->getAccessToken('authorization_code', $tokenParams);
        $owner = $oauthProvider->getResourceOwner($token);

        return $this->mapToSocialUser($provider, $owner);
    }

    private function mapToSocialUser(string $provider, ResourceOwnerInterface $owner): SocialUser
    {
        $data = $owner->toArray();

        // Sécurité OAuth : refuser les comptes dont l'adresse email n'a pas été validée chez le fournisseur
        if (isset($data['email_verified']) && $data['email_verified'] === false) {
            throw new \RuntimeException("The email address from $provider is not verified.");
        }

        return match ($provider) {
            'google' => new SocialUser(
                providerId: (string) ($data['sub'] ?? ''),
                email:      strtolower(trim((string) ($data['email'] ?? ''))),
                username:   $this->sanitizeUsername((string) ($data['name'] ?? '')),
                avatarUrl:  $this->sanitizeAvatarUrl($data['picture'] ?? null),
            ),
            'microsoft' => new SocialUser(
                providerId: (string) ($data['oid'] ?? ''),
                email:      strtolower(trim((string) ($data['email'] ?? ($data['userPrincipalName'] ?? '')))),
                username:   $this->sanitizeUsername((string) ($data['displayName'] ?? ($data['name'] ?? ''))),
                avatarUrl:  null,
            ),
            'github' => new SocialUser(
                providerId: (string) ($data['id'] ?? ''),
                email:      strtolower(trim((string) ($data['email'] ?? ''))),
                username:   $this->sanitizeUsername((string) ($data['login'] ?? '')),
                avatarUrl:  $this->sanitizeAvatarUrl($data['avatar_url'] ?? null),
            ),
            'gitlab' => new SocialUser(
                providerId: (string) ($data['id'] ?? ''),
                email:      strtolower(trim((string) ($data['email'] ?? ''))),
                username:   $this->sanitizeUsername((string) ($data['username'] ?? '')),
                avatarUrl:  $this->sanitizeAvatarUrl($data['avatar_url'] ?? null),
            ),
            default => throw new \InvalidArgumentException("Unknown provider: $provider"),
        };
    }

    private function sanitizeUsername(string $username): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]/', '', trim($username));
        return mb_substr($clean ?? '', 0, 100);
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

    // ─── Admin User ────────────────────────────────────────────────────────

    public function findOrCreateAdminUser(SocialUser $socialUser, string $provider): User
    {
        if (empty($socialUser->email) || !filter_var($socialUser->email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException("The $provider account did not return a valid email address.");
        }

        $providerField = $this->getProviderIdField($provider);

        // 1. Chercher par ID provider
        $user = $this->userRepo->findOneBy([$providerField => $socialUser->providerId]);
        if ($user) {
            return $user;
        }

        // 2. Chercher par email
        $user = $this->userRepo->findByEmail($socialUser->email);
        if ($user) {
            // Lier le provider au compte existant
            $user->{$providerField} = $socialUser->providerId;
            $this->em->flush();
            return $user;
        }

        // 3. Créer un nouveau User
        $user = new User();
        $user->email = $socialUser->email;
        $user->name = $socialUser->username;
        $user->password = null; // pas de mot de passe — connexion sociale uniquement
        $user->{$providerField} = $socialUser->providerId;
        $user->locale = 'en';
        $user->uuid = Uuid::v4();

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    // ─── EndUser ───────────────────────────────────────────────────────────

    public function findOrCreateEndUser(Project $project, SocialUser $socialUser, string $provider): EndUser
    {
        if (empty($socialUser->email) || !filter_var($socialUser->email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException("The $provider account did not return a valid email address.");
        }

        $providerField = $this->getProviderIdField($provider);

        // 1. Chercher par ID provider dans ce projet
        $endUser = $this->endUserRepo->findOneBy(['project' => $project, $providerField => $socialUser->providerId]);
        if ($endUser) {
            return $endUser;
        }

        // 2. Chercher par email dans ce projet
        $endUser = $this->endUserRepo->findOneByProjectAndEmail($project, $socialUser->email);
        if ($endUser) {
            // Lier le provider au compte existant
            $endUser->{$providerField} = $socialUser->providerId;
            $this->em->flush();
            return $endUser;
        }

        // 3. Créer un nouveau EndUser
        $endUser = new EndUser($project, $socialUser->email);
        $endUser->username = $socialUser->username;
        $endUser->password = null; // connexion sociale uniquement
        $endUser->{$providerField} = $socialUser->providerId;
        $endUser->avatarUrl = $socialUser->avatarUrl;

        $this->em->persist($endUser);
        $this->em->flush();

        return $endUser;
    }

    // ─── Link / Unlink ─────────────────────────────────────────────────────

    public function linkProvider(User|EndUser $user, string $provider, string $providerId): void
    {
        $field = $this->getProviderIdField($provider);
        $user->{$field} = $providerId;
        $this->em->flush();
    }

    public function unlinkProvider(User|EndUser $user, string $provider): void
    {
        if (!$this->canUnlinkProvider($user, $provider)) {
            throw new \RuntimeException('Cannot unlink the last authentication method. Set a password or link another provider first.');
        }

        $field = $this->getProviderIdField($provider);
        $user->{$field} = null;
        $this->em->flush();
    }

    public function canUnlinkProvider(User|EndUser $user, string $provider): bool
    {
        // Un utilisateur doit toujours avoir un moyen de se connecter
        $hasPassword = $user->password !== null && $user->password !== '';

        $hasOtherProvider = false;
        foreach (['google', 'microsoft', 'github', 'gitlab'] as $p) {
            if ($p === $provider) continue;
            $field = $this->getProviderIdField($p);
            if (!empty($user->{$field})) {
                $hasOtherProvider = true;
                break;
            }
        }

        return $hasPassword || $hasOtherProvider;
    }

    /** @return string[] */
    public function getLinkedProviders(User|EndUser $user): array
    {
        $linked = [];
        foreach (['google', 'microsoft', 'github', 'gitlab'] as $p) {
            $field = $this->getProviderIdField($p);
            if (!empty($user->{$field})) {
                $linked[] = $p;
            }
        }
        return $linked;
    }

    /** @return string[] */
    public function getAvailableProviders(): array
    {
        $available = [];
        foreach (['google', 'microsoft', 'github', 'gitlab'] as $p) {
            if ($this->getAdminCredentials($p) !== null) {
                $available[] = $p;
            }
        }
        return $available;
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function getProviderIdField(string $provider): string
    {
        return match ($provider) {
            'google'    => 'googleId',
            'microsoft' => 'microsoftId',
            'github'    => 'githubId',
            'gitlab'    => 'gitlabId',
            default     => throw new \InvalidArgumentException("Unknown provider: $provider"),
        };
    }
}
