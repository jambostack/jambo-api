<?php

namespace App\Tests\Security;

use App\Dto\OidcConfig;
use App\Dto\SocialUser;
use App\Entity\AppSettings;
use App\Entity\User;
use App\Repository\AppSettingsRepository;
use App\Repository\EndUserRepository;
use App\Repository\UserRepository;
use App\Security\Oidc\OidcAuthenticator;
use App\Security\Oidc\OidcProviderManager;
use App\Service\Security\SsrfProtectionService;
use App\Service\WebhookSecretService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class Cycle46AuthOidcSecurityHardeningTest extends TestCase
{
    private string $appSecret = 'test-secret-key-32-characters-exact!!';

    private function createOidcConfig(string $alg = 'HS256', string $issuer = 'https://sso.example.com'): OidcConfig
    {
        return new OidcConfig(
            issuer: $issuer,
            authorizationEndpoint: 'https://sso.example.com/oauth/authorize',
            tokenEndpoint: 'https://sso.example.com/oauth/token',
            userinfoEndpoint: 'https://sso.example.com/oauth/userinfo',
            jwksUri: null,
            scopesSupported: ['openid', 'email', 'profile'],
            idTokenSigningAlg: $alg,
        );
    }

    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function makeJwt(array $header, array $payload, string $secret): string
    {
        $h = $this->base64url(json_encode($header));
        $b = $this->base64url(json_encode($payload));
        $sig = $this->base64url(hash_hmac('sha256', $h . '.' . $b, $secret, true));
        return $h . '.' . $b . '.' . $sig;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 1. OidcProviderManager Tests
    // ──────────────────────────────────────────────────────────────────────────

    public function testBuildAuthorizationUrlSetsClientIdAndS256Challenge(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $requestStack = new RequestStack();
        $manager = new OidcProviderManager($httpClient, $requestStack, $this->appSecret);

        $config = $this->createOidcConfig();
        $url = $manager->buildAuthorizationUrl(
            $config,
            'https://api.jambostack.site/oidc/check',
            'test_challenge_123',
            'test_state_456',
            'test_nonce_789',
            'client_abc_999'
        );

        self::assertStringContainsString('client_id=client_abc_999', $url);
        self::assertStringContainsString('code_challenge=test_challenge_123', $url);
        self::assertStringContainsString('code_challenge_method=S256', $url);
        self::assertStringContainsString('state=test_state_456', $url);
        self::assertStringContainsString('nonce=test_nonce_789', $url);
        self::assertStringStartsWith('https://sso.example.com/oauth/authorize?', $url);
    }

    public function testGenerateStateStoresStateVerifierAndNonceInSession(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $manager = new OidcProviderManager($httpClient, $requestStack, $this->appSecret);
        $result = $manager->generateState('admin', 'provider-1', null);

        self::assertNotEmpty($result['state']);
        self::assertNotEmpty($result['nonce']);
        self::assertNotEmpty($result['codeVerifier']);

        self::assertSame($result['state'], $session->get('oidc_state'));
        self::assertSame($result['codeVerifier'], $session->get('oidc_code_verifier'));
        self::assertSame($result['nonce'], $session->get('oidc_nonce'));
    }

    public function testValidateIdTokenRejectsAlgNone(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $requestStack = new RequestStack();
        $manager = new OidcProviderManager($httpClient, $requestStack, $this->appSecret);

        $config = $this->createOidcConfig('HS256');
        $header = ['alg' => 'none', 'typ' => 'JWT'];
        $payload = [
            'iss' => 'https://sso.example.com',
            'aud' => 'client_123',
            'sub' => 'user_456',
            'exp' => time() + 300,
            'nonce' => 'nonce_abc',
        ];
        $h = $this->base64url(json_encode($header));
        $b = $this->base64url(json_encode($payload));
        $token = $h . '.' . $b . '.';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Algorithm "none" is not permitted in id_token.');
        $manager->validateIdToken($token, $config, 'client_123', 'secret_xyz', 'nonce_abc');
    }

    public function testValidateIdTokenRejectsAlgMismatch(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $requestStack = new RequestStack();
        $manager = new OidcProviderManager($httpClient, $requestStack, $this->appSecret);

        $config = $this->createOidcConfig('HS256');
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $payload = [
            'iss' => 'https://sso.example.com',
            'aud' => 'client_123',
            'sub' => 'user_456',
            'exp' => time() + 300,
            'nonce' => 'nonce_abc',
        ];
        $token = $this->makeJwt($header, $payload, 'secret_xyz');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('id_token algorithm mismatch.');
        $manager->validateIdToken($token, $config, 'client_123', 'secret_xyz', 'nonce_abc');
    }

    public function testValidateIdTokenRejectsEmptySub(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $requestStack = new RequestStack();
        $manager = new OidcProviderManager($httpClient, $requestStack, $this->appSecret);

        $config = $this->createOidcConfig('HS256');
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = [
            'iss' => 'https://sso.example.com',
            'aud' => 'client_123',
            'sub' => '   ',
            'exp' => time() + 300,
            'nonce' => 'nonce_abc',
        ];
        $token = $this->makeJwt($header, $payload, 'secret_xyz');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('id_token sub claim cannot be empty.');
        $manager->validateIdToken($token, $config, 'client_123', 'secret_xyz', 'nonce_abc');
    }

    public function testValidateIdTokenRejectsInvalidEmailFormat(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $requestStack = new RequestStack();
        $manager = new OidcProviderManager($httpClient, $requestStack, $this->appSecret);

        $config = $this->createOidcConfig('HS256');
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = [
            'iss' => 'https://sso.example.com',
            'aud' => 'client_123',
            'sub' => 'user_456',
            'email' => 'not-an-email',
            'exp' => time() + 300,
            'nonce' => 'nonce_abc',
        ];
        $token = $this->makeJwt($header, $payload, 'secret_xyz');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid email address format in id_token.');
        $manager->validateIdToken($token, $config, 'client_123', 'secret_xyz', 'nonce_abc');
    }

    public function testValidateIdTokenRejectsUnverifiedEmail(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $requestStack = new RequestStack();
        $manager = new OidcProviderManager($httpClient, $requestStack, $this->appSecret);

        $config = $this->createOidcConfig('HS256');
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = [
            'iss' => 'https://sso.example.com',
            'aud' => 'client_123',
            'sub' => 'user_456',
            'email' => 'admin@example.com',
            'email_verified' => false,
            'exp' => time() + 300,
            'nonce' => 'nonce_abc',
        ];
        $token = $this->makeJwt($header, $payload, 'secret_xyz');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The email address from OIDC provider is not verified.');
        $manager->validateIdToken($token, $config, 'client_123', 'secret_xyz', 'nonce_abc');
    }

    public function testValidateIdTokenAcceptsValidTokenWithVerifiedEmail(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $requestStack = new RequestStack();
        $manager = new OidcProviderManager($httpClient, $requestStack, $this->appSecret);

        $config = $this->createOidcConfig('HS256');
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = [
            'iss' => 'https://sso.example.com',
            'aud' => 'client_123',
            'sub' => 'sub_789',
            'email' => 'Admin.User@example.com',
            'name' => 'Admin User',
            'email_verified' => true,
            'exp' => time() + 300,
            'nonce' => 'nonce_abc',
        ];
        $token = $this->makeJwt($header, $payload, 'secret_xyz');

        $claims = $manager->validateIdToken($token, $config, 'client_123', 'secret_xyz', 'nonce_abc');
        self::assertSame('sub_789', $claims['sub']);
        self::assertSame('admin.user@example.com', $claims['email']);
        self::assertSame('Admin User', $claims['name']);
        self::assertTrue($claims['email_verified']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 2. OidcAuthenticator Tests
    // ──────────────────────────────────────────────────────────────────────────

    public function testOidcAuthenticatorRejectsStateMismatch(): void
    {
        $oidcManager = $this->createMock(OidcProviderManager::class);
        $userRepo = $this->createMock(UserRepository::class);
        $endUserRepo = $this->createMock(EndUserRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $appSettingsRepo = $this->createMock(AppSettingsRepository::class);
        $secretService = $this->createMock(WebhookSecretService::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(fn ($k) => $k);

        $authenticator = new OidcAuthenticator(
            $oidcManager,
            $userRepo,
            $endUserRepo,
            $em,
            $urlGenerator,
            $appSettingsRepo,
            $secretService,
            null,
            $translator
        );

        $oidcManager->method('validateState')->willReturn([
            'type' => 'admin',
            'providerId' => 'prov-1',
            'nonce' => 'nonce123',
        ]);

        $session = new Session(new MockArraySessionStorage());
        $session->set('oidc_state', 'expected_state_abc');
        $session->set('oidc_code_verifier', 'verifier_123');
        $session->set('oidc_nonce', 'nonce123');

        $request = new Request(['code' => 'auth_code_123', 'state' => 'forged_state_xyz']);
        $request->setSession($session);
        $request->attributes->set('_route', 'oidc_check');

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('oidc.invalid_state');
        $authenticator->authenticate($request);
    }

    public function testOidcAuthenticatorSingleUseConsumesSessionStateAndNonce(): void
    {
        $oidcManager = $this->createMock(OidcProviderManager::class);
        $userRepo = $this->createMock(UserRepository::class);
        $endUserRepo = $this->createMock(EndUserRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $appSettingsRepo = $this->createMock(AppSettingsRepository::class);
        $secretService = $this->createMock(WebhookSecretService::class);

        $authenticator = new OidcAuthenticator(
            $oidcManager,
            $userRepo,
            $endUserRepo,
            $em,
            $urlGenerator,
            $appSettingsRepo,
            $secretService,
            null,
            null
        );

        $oidcManager->method('validateState')->willReturn([
            'type' => 'admin',
            'providerId' => 'prov-1',
            'nonce' => 'nonce123',
        ]);

        $session = new Session(new MockArraySessionStorage());
        $session->set('oidc_state', 'state_match_123');
        $session->set('oidc_code_verifier', 'verifier_123');
        $session->set('oidc_nonce', 'nonce123');

        $request = new Request(['code' => 'auth_code_123', 'state' => 'state_match_123']);
        $request->setSession($session);
        $request->attributes->set('_route', 'oidc_check');

        // Provider manager throws on token exchange to observe consumed session keys
        $oidcManager->method('exchangeCode')->willThrowException(new \RuntimeException('Network fail'));

        $settings = new AppSettings();
        $settings->oauthProviders = [
            'oidcProviders' => [
                [
                    'id' => 'prov-1',
                    'enabled' => true,
                    'clientId' => 'c1',
                    'clientSecret' => 's1',
                    'issuer' => 'https://sso.example.com',
                ],
            ],
        ];
        $appSettingsRepo->method('getOrCreate')->willReturn($settings);
        $oidcManager->method('discover')->willReturn($this->createOidcConfig());

        try {
            $authenticator->authenticate($request);
            self::fail('Expected CustomUserMessageAuthenticationException');
        } catch (CustomUserMessageAuthenticationException) {
            // Check that session keys were immediately consumed
            self::assertFalse($session->has('oidc_state'));
            self::assertFalse($session->has('oidc_code_verifier'));
            self::assertFalse($session->has('oidc_nonce'));
        }
    }

    public function testOidcAuthenticatorRejectsUnverifiedEmailDuringUserLinking(): void
    {
        $oidcManager = $this->createMock(OidcProviderManager::class);
        $userRepo = $this->createMock(UserRepository::class);
        $endUserRepo = $this->createMock(EndUserRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $appSettingsRepo = $this->createMock(AppSettingsRepository::class);
        $secretService = $this->createMock(WebhookSecretService::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(fn ($k) => $k);

        $authenticator = new OidcAuthenticator(
            $oidcManager,
            $userRepo,
            $endUserRepo,
            $em,
            $urlGenerator,
            $appSettingsRepo,
            $secretService,
            null,
            $translator
        );

        $session = new Session(new MockArraySessionStorage());
        $session->set('oidc_state', 'state_match_123');
        $session->set('oidc_code_verifier', 'verifier_123');
        $session->set('oidc_nonce', 'nonce123');

        $request = new Request(['code' => 'auth_code_123', 'state' => 'state_match_123']);
        $request->setSession($session);
        $request->attributes->set('_route', 'oidc_check');

        $oidcManager->method('validateState')->willReturn([
            'type' => 'admin',
            'providerId' => 'prov-1',
            'nonce' => 'nonce123',
        ]);

        $settings = new AppSettings();
        $settings->oauthProviders = [
            'oidcProviders' => [
                [
                    'id' => 'prov-1',
                    'enabled' => true,
                    'clientId' => 'c1',
                    'clientSecret' => 's1',
                    'issuer' => 'https://sso.example.com',
                ],
            ],
        ];
        $appSettingsRepo->method('getOrCreate')->willReturn($settings);
        $oidcManager->method('discover')->willReturn($this->createOidcConfig());
        $oidcManager->method('exchangeCode')->willReturn(['idToken' => 'token.jwt.abc']);
        $oidcManager->method('validateIdToken')->willReturn([
            'sub' => 'sub_456',
            'email' => 'victim@example.com',
            'name' => 'Attacker Claim',
            'picture' => null,
            'email_verified' => false,
            'claims' => [],
        ]);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('oidc.unverified_email');
        $authenticator->authenticate($request);
    }

    public function testOidcAuthenticatorLinksExistingAdminUserByEmailWhenVerified(): void
    {
        $oidcManager = $this->createMock(OidcProviderManager::class);
        $userRepo = $this->createMock(UserRepository::class);
        $endUserRepo = $this->createMock(EndUserRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $appSettingsRepo = $this->createMock(AppSettingsRepository::class);
        $secretService = $this->createMock(WebhookSecretService::class);

        $authenticator = new OidcAuthenticator(
            $oidcManager,
            $userRepo,
            $endUserRepo,
            $em,
            $urlGenerator,
            $appSettingsRepo,
            $secretService,
            null,
            null
        );

        $session = new Session(new MockArraySessionStorage());
        $session->set('oidc_state', 'valid_state_123');
        $session->set('oidc_code_verifier', 'verifier_123');
        $session->set('oidc_nonce', 'nonce123');

        $request = new Request(['code' => 'auth_code_123', 'state' => 'valid_state_123']);
        $request->setSession($session);
        $request->attributes->set('_route', 'oidc_check');

        $oidcManager->method('validateState')->willReturn([
            'type' => 'admin',
            'providerId' => 'prov-1',
            'nonce' => 'nonce123',
        ]);

        $settings = new AppSettings();
        $settings->oauthProviders = [
            'oidcProviders' => [
                [
                    'id' => 'prov-1',
                    'enabled' => true,
                    'clientId' => 'c1',
                    'clientSecret' => 's1',
                    'issuer' => 'https://sso.example.com',
                ],
            ],
        ];
        $appSettingsRepo->method('getOrCreate')->willReturn($settings);
        $oidcManager->method('discover')->willReturn($this->createOidcConfig());
        $oidcManager->method('exchangeCode')->willReturn(['idToken' => 'token.jwt.abc']);
        $oidcManager->method('validateIdToken')->willReturn([
            'sub' => 'sub_unique_123',
            'email' => 'legit.admin@example.com',
            'name' => 'Legit Admin',
            'picture' => 'https://example.com/avatar.png',
            'email_verified' => true,
            'claims' => [],
        ]);

        $existingUser = new User();
        $existingUser->email = 'legit.admin@example.com';
        $existingUser->name = 'Original Name';

        $userRepo->method('findOneByOidc')->willReturn(null);
        $userRepo->method('findByEmail')->with('legit.admin@example.com')->willReturn($existingUser);
        $em->expects(self::once())->method('flush');

        $passport = $authenticator->authenticate($request);
        self::assertSame('legit.admin@example.com', $passport->getUser()->getUserIdentifier());
        self::assertSame('sub_unique_123', $existingUser->oidcSub);
        self::assertSame('https://sso.example.com', $existingUser->oidcIssuer);
    }

    public function testOidcAuthenticatorCreatesNewUserWithSanitizedName(): void
    {
        $oidcManager = $this->createMock(OidcProviderManager::class);
        $userRepo = $this->createMock(UserRepository::class);
        $endUserRepo = $this->createMock(EndUserRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $appSettingsRepo = $this->createMock(AppSettingsRepository::class);
        $secretService = $this->createMock(WebhookSecretService::class);

        $authenticator = new OidcAuthenticator(
            $oidcManager,
            $userRepo,
            $endUserRepo,
            $em,
            $urlGenerator,
            $appSettingsRepo,
            $secretService,
            null,
            null
        );

        $session = new Session(new MockArraySessionStorage());
        $session->set('oidc_state', 'valid_state_brand_new');
        $session->set('oidc_code_verifier', 'verifier_456');
        $session->set('oidc_nonce', 'nonce456');

        $request = new Request(['code' => 'auth_code_brand_new', 'state' => 'valid_state_brand_new']);
        $request->setSession($session);
        $request->attributes->set('_route', 'oidc_check');

        $oidcManager->method('validateState')->willReturn([
            'type' => 'admin',
            'providerId' => 'prov-2',
            'nonce' => 'nonce456',
        ]);

        $settings = new AppSettings();
        $settings->oauthProviders = [
            'oidcProviders' => [
                [
                    'id' => 'prov-2',
                    'enabled' => true,
                    'clientId' => 'c2',
                    'clientSecret' => 's2',
                    'issuer' => 'https://sso.example.com',
                ],
            ],
        ];
        $appSettingsRepo->method('getOrCreate')->willReturn($settings);
        $oidcManager->method('discover')->willReturn($this->createOidcConfig());
        $oidcManager->method('exchangeCode')->willReturn(['idToken' => 'token.jwt.brand_new']);
        $oidcManager->method('validateIdToken')->willReturn([
            'sub' => 'sub_new_999',
            'email' => 'new.admin@example.com',
            'name' => "Admin\x00\x1fName",
            'picture' => 'javascript:alert(1)',
            'email_verified' => true,
            'claims' => [],
        ]);

        $userRepo->method('findOneByOidc')->willReturn(null);
        $userRepo->method('findByEmail')->with('new.admin@example.com')->willReturn(null);

        $persistedUser = null;
        $em->expects(self::once())->method('persist')->willReturnCallback(function (User $u) use (&$persistedUser) {
            $persistedUser = $u;
        });
        $em->expects(self::once())->method('flush');

        $passport = $authenticator->authenticate($request);
        self::assertInstanceOf(User::class, $persistedUser);
        self::assertSame('new.admin@example.com', $persistedUser->email);
        self::assertSame('AdminName', $persistedUser->name); // sanitized control characters
        self::assertSame('sub_new_999', $persistedUser->oidcSub);
        self::assertSame('https://sso.example.com', $persistedUser->oidcIssuer);
    }

    public function testOidcAuthenticatorRejectsInvalidEmailFormat(): void
    {
        $oidcManager = $this->createMock(OidcProviderManager::class);
        $userRepo = $this->createMock(UserRepository::class);
        $endUserRepo = $this->createMock(EndUserRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $appSettingsRepo = $this->createMock(AppSettingsRepository::class);
        $secretService = $this->createMock(WebhookSecretService::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(fn ($k) => $k);

        $authenticator = new OidcAuthenticator(
            $oidcManager,
            $userRepo,
            $endUserRepo,
            $em,
            $urlGenerator,
            $appSettingsRepo,
            $secretService,
            null,
            $translator
        );

        $session = new Session(new MockArraySessionStorage());
        $session->set('oidc_state', 'valid_state_invalid_email');
        $session->set('oidc_code_verifier', 'verifier_789');
        $session->set('oidc_nonce', 'nonce789');

        $request = new Request(['code' => 'auth_code_789', 'state' => 'valid_state_invalid_email']);
        $request->setSession($session);
        $request->attributes->set('_route', 'oidc_check');

        $oidcManager->method('validateState')->willReturn([
            'type' => 'admin',
            'providerId' => 'prov-1',
            'nonce' => 'nonce789',
        ]);

        $settings = new AppSettings();
        $settings->oauthProviders = [
            'oidcProviders' => [
                [
                    'id' => 'prov-1',
                    'enabled' => true,
                    'clientId' => 'c1',
                    'clientSecret' => 's1',
                    'issuer' => 'https://sso.example.com',
                ],
            ],
        ];
        $appSettingsRepo->method('getOrCreate')->willReturn($settings);
        $oidcManager->method('discover')->willReturn($this->createOidcConfig());
        $oidcManager->method('exchangeCode')->willReturn(['idToken' => 'token.jwt.abc']);
        $oidcManager->method('validateIdToken')->willReturn([
            'sub' => 'sub_456',
            'email' => 'bad-email-format',
            'name' => 'Name',
            'picture' => null,
            'email_verified' => true,
            'claims' => [],
        ]);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('oidc.invalid_email');
        $authenticator->authenticate($request);
    }

    public function testTranslationsParityAcrossAllFourLocales(): void
    {
        $en = require __DIR__ . '/../../translations/messages.en.php';
        $fr = require __DIR__ . '/../../translations/messages.fr.php';
        $es = require __DIR__ . '/../../translations/messages.es.php';
        $ar = require __DIR__ . '/../../translations/messages.ar.php';

        self::assertCount(2656, $en);
        self::assertCount(2656, $fr);
        self::assertCount(2656, $es);
        self::assertCount(2656, $ar);

        self::assertEmpty(array_diff_key($en, $fr), 'fr has missing keys compared to en');
        self::assertEmpty(array_diff_key($en, $es), 'es has missing keys compared to en');
        self::assertEmpty(array_diff_key($en, $ar), 'ar has missing keys compared to en');

        $criticalKeys = [
            'oidc.rate_limit_exceeded',
            'oidc.provider_not_found',
            'oidc.missing_parameters',
            'oidc.session_expired',
            'oidc.invalid_state',
            'oidc.end_user_not_supported',
            'oidc.invalid_id_token',
            'oidc.invalid_email',
            'oidc.unverified_email',
            'auth.confirm_password.auth_required',
            'auth.confirm_password.rate_limit_exceeded',
            'auth.confirm_password.invalid_json',
            'auth.confirm_password.incorrect_password',
            'auth.password_reset.rate_limit_exceeded',
            'auth.password_reset.invalid_email',
            'auth.password_reset.email_sent_status',
            'auth.password_reset.invalid_or_expired_link',
            'auth.password_reset.length_invalid',
            'auth.password_reset.passwords_do_not_match',
            'auth.password_reset.user_not_found',
            'auth.password_reset.same_as_current',
            'auth.register.rate_limit_exceeded',
            'auth.register.invalid_json',
            'auth.register.passwords_do_not_match',
            'auth.register.email_in_use',
        ];

        foreach ($criticalKeys as $key) {
            self::assertArrayHasKey($key, $en);
            self::assertArrayHasKey($key, $fr);
            self::assertArrayHasKey($key, $es);
            self::assertArrayHasKey($key, $ar);
        }
    }
}
