<?php

namespace App\Tests\Service;

use App\Entity\AppSettings;
use App\Repository\AppSettingsRepository;
use App\Repository\EndUserRepository;
use App\Repository\UserRepository;
use App\Service\SocialLoginService;
use App\Service\WebhookSecretService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class SocialLoginServiceTest extends TestCase
{
    private function createServiceWithGoogleConfig(): SocialLoginService
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $userRepo = $this->createMock(UserRepository::class);
        $endUserRepo = $this->createMock(EndUserRepository::class);

        $appSettingsRepo = $this->createMock(AppSettingsRepository::class);
        $settings = new AppSettings();
        $settings->oauthProviders = [
            'google' => [
                'enabled'      => true,
                'clientId'     => 'test-google-client-id',
                'clientSecret' => 'test-google-client-secret',
            ],
        ];
        $appSettingsRepo->method('getOrCreate')->willReturn($settings);

        $secretService = $this->createMock(WebhookSecretService::class);
        $secretService->method('decrypt')->willReturnArgument(0);

        return new SocialLoginService($em, $userRepo, $endUserRepo, $appSettingsRepo, $secretService);
    }

    /** Identifiants présents mais provider désactivé (toggle UI off). */
    private function createServiceWithDisabledGoogleConfig(): SocialLoginService
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $userRepo = $this->createMock(UserRepository::class);
        $endUserRepo = $this->createMock(EndUserRepository::class);

        $appSettingsRepo = $this->createMock(AppSettingsRepository::class);
        $settings = new AppSettings();
        $settings->oauthProviders = [
            'google' => [
                'enabled'      => false,
                'clientId'     => 'test-google-client-id',
                'clientSecret' => 'test-google-client-secret',
            ],
        ];
        $appSettingsRepo->method('getOrCreate')->willReturn($settings);

        $secretService = $this->createMock(WebhookSecretService::class);
        $secretService->method('decrypt')->willReturnArgument(0);

        return new SocialLoginService($em, $userRepo, $endUserRepo, $appSettingsRepo, $secretService);
    }

    private function createServiceWithoutConfig(): SocialLoginService
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $userRepo = $this->createMock(UserRepository::class);
        $endUserRepo = $this->createMock(EndUserRepository::class);

        $appSettingsRepo = $this->createMock(AppSettingsRepository::class);
        $settings = new AppSettings();
        $settings->oauthProviders = [];
        $appSettingsRepo->method('getOrCreate')->willReturn($settings);

        $secretService = $this->createMock(WebhookSecretService::class);

        return new SocialLoginService($em, $userRepo, $endUserRepo, $appSettingsRepo, $secretService);
    }

    public function testGetProviderConfigForGoogle(): void
    {
        $service = $this->createServiceWithGoogleConfig();

        $provider = $service->getProviderForAdmin('google');

        $this->assertNotNull($provider, 'Le provider Google doit etre retourne quand la configuration est presente');
        $this->assertInstanceOf(\League\OAuth2\Client\Provider\Google::class, $provider);
    }

    public function testGetProviderConfigForUnknown(): void
    {
        $service = $this->createServiceWithoutConfig();

        // Un provider inconnu (non listé dans PROVIDER_CONFIG) n'est pas supporté
        // getProviderForAdmin retourne null si la config est absente
        $result = $service->getProviderForAdmin('unknown_provider');

        $this->assertNull($result, 'Un provider inconnu doit retourner null');
    }

    public function testGetProviderConfigForUnconfiguredReturnsNull(): void
    {
        $service = $this->createServiceWithoutConfig();

        // getProviderForAdmin('google') quand aucun oauthProvider n'est defini
        $result = $service->getProviderForAdmin('google');

        $this->assertNull($result, 'Un provider non configure doit retourner null');
    }

    public function testGetAllProviders(): void
    {
        $service = $this->createServiceWithGoogleConfig();

        $providers = $service->getAvailableProviders();

        $this->assertIsArray($providers, 'getAvailableProviders doit retourner un tableau');
        $this->assertContains('google', $providers, 'google doit apparaitre dans la liste des providers disponibles');
    }

    public function testDisabledProviderIsNotReturned(): void
    {
        // Régression #1 : des identifiants saisis mais le provider désactivé
        // ne doit PAS rendre le bouton de connexion disponible.
        $service = $this->createServiceWithDisabledGoogleConfig();

        $this->assertNull(
            $service->getProviderForAdmin('google'),
            'Un provider désactivé ne doit pas être retourné même avec des identifiants',
        );
        $this->assertNotContains(
            'google',
            $service->getAvailableProviders(),
            'Un provider désactivé ne doit pas apparaître dans la liste',
        );
    }

    public function testGetAvailableProvidersEmptyWithNoConfig(): void
    {
        $service = $this->createServiceWithoutConfig();

        $providers = $service->getAvailableProviders();

        $this->assertIsArray($providers);
        $this->assertEmpty($providers, 'Sans configuration, la liste des providers doit etre vide');
    }

    public function testGetAuthorizationUrlAndStateReturnsUrlAndCsrfState(): void
    {
        $service = $this->createServiceWithGoogleConfig();
        $authData = $service->getAuthorizationUrlAndState('google', 'https://example.com/connect/google/check');

        $this->assertArrayHasKey('url', $authData);
        $this->assertArrayHasKey('state', $authData);
        $this->assertNotEmpty($authData['url']);
        $this->assertNotEmpty($authData['state']);
        $this->assertStringContainsString('state=' . $authData['state'], $authData['url']);
    }

    public function testGetRedirectUrlThrowsOnUnconfiguredProvider(): void
    {
        $service = $this->createServiceWithoutConfig();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not configured');

        $service->getRedirectUrl('google', 'http://localhost/callback');
    }

    public function testGetLinkedProvidersReturnsEmptyForNewUser(): void
    {
        $service = $this->createServiceWithoutConfig();

        $user = new \App\Entity\User();
        $user->email = 'test@example.com';
        $user->name = 'Test User';

        $linked = $service->getLinkedProviders($user);

        $this->assertIsArray($linked);
        $this->assertEmpty($linked, 'Un utilisateur sans connexion sociale ne doit avoir aucun provider lie');
    }

    public function testPkceChallengeIncludedInAuthorizationUrl(): void
    {
        $service = $this->createServiceWithGoogleConfig();
        $codeChallenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

        $authData = $service->getAuthorizationUrlAndState(
            'google',
            'https://example.com/callback',
            null,
            $codeChallenge,
            'S256'
        );

        $this->assertStringContainsString('code_challenge=' . $codeChallenge, $authData['url']);
        $this->assertStringContainsString('code_challenge_method=S256', $authData['url']);
    }

    public function testPkceRejectsInvalidChallenge(): void
    {
        $service = $this->createServiceWithGoogleConfig();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid PKCE code_challenge format.');

        $service->getAuthorizationUrlAndState(
            'google',
            'https://example.com/callback',
            null,
            'too-short'
        );
    }

    public function testGetUserFromProviderRejectsInvalidCodeFormat(): void
    {
        $service = $this->createServiceWithGoogleConfig();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid authorization code format.');

        $service->getUserFromProvider('google', "code\0with-null-byte", 'https://example.com/callback');
    }

    public function testGetUserFromProviderRejectsInvalidCodeVerifier(): void
    {
        $service = $this->createServiceWithGoogleConfig();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid PKCE code_verifier format.');

        $service->getUserFromProvider(
            'google',
            'valid-code-12345',
            'https://example.com/callback',
            null,
            'invalid/verifier/chars'
        );
    }

    public function testMapToSocialUserRejectsUnverifiedEmail(): void
    {
        $service = $this->createServiceWithGoogleConfig();
        $owner = $this->createMock(\League\OAuth2\Client\Provider\ResourceOwnerInterface::class);
        $owner->method('toArray')->willReturn([
            'sub' => '12345',
            'email' => 'unverified@example.com',
            'email_verified' => false,
            'name' => 'Unverified User',
        ]);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('mapToSocialUser');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The email address from google is not verified.');

        $method->invoke($service, 'google', $owner);
    }

    public function testTranslatorIsInvokedWhenProvided(): void
    {
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $userRepo = $this->createMock(\App\Repository\UserRepository::class);
        $endUserRepo = $this->createMock(\App\Repository\EndUserRepository::class);
        $appSettingsRepo = $this->createMock(\App\Repository\AppSettingsRepository::class);
        $secretService = $this->createMock(\App\Service\WebhookSecretService::class);
        $translator = $this->createMock(\Symfony\Contracts\Translation\TranslatorInterface::class);

        $translator->expects($this->once())
            ->method('trans')
            ->with('social.error.unknown_provider', ['%provider%' => 'unknown_service'])
            ->willReturn('Fournisseur OAuth inconnu : unknown_service.');

        $service = new SocialLoginService($em, $userRepo, $endUserRepo, $appSettingsRepo, $secretService, $translator);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Fournisseur OAuth inconnu : unknown_service.');

        $service->getAuthorizationUrlAndState('unknown_service', 'https://example.com/callback');
    }

    public function testI18nParityForSocialLoginErrors(): void
    {
        $locales = ['en', 'fr', 'es', 'ar'];
        $keys = [
            'social.error.unknown_provider',
            'social.error.invalid_pkce_challenge',
            'social.error.invalid_pkce_verifier',
            'social.error.invalid_auth_code',
            'social.error.provider_not_configured',
            'social.error.email_not_verified',
            'social.error.invalid_email',
            'social.error.cannot_unlink_last',
        ];

        foreach ($locales as $locale) {
            $file = __DIR__ . '/../../translations/messages.' . $locale . '.php';
            $this->assertFileExists($file, "Translation file for locale {$locale} must exist.");
            $dict = require $file;
            $this->assertIsArray($dict);

            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $dict, "Locale {$locale} must define translation key {$key}.");
                $this->assertNotEmpty($dict[$key], "Translation for {$key} in {$locale} must not be empty.");
            }
        }
    }
}
