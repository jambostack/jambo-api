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
}
