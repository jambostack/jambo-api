<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AppSettings;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\AppSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

class Cycle41HardeningTest extends WebTestCase
{
    private function createSuperAdmin(KernelBrowser $client): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->name = 'Super Admin Cycle 41';
        $user->email = 'superadmin_cycle41_' . bin2hex(random_bytes(4)) . '@test.com';
        $user->password = $hasher->hashPassword($user, 'password123');
        $user->roles = ['ROLE_SUPER_ADMIN'];

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function createProject(EntityManagerInterface $em): Project
    {
        $project = new Project();
        $project->name = 'Cycle 41 Project ' . bin2hex(random_bytes(4));
        $em->persist($project);
        $em->flush();

        return $project;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. AppSettingsController Hardening Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function testAppSettingsModelsUnknownProviderReturns400(): void
    {
        $client = static::createClient();
        $admin = $this->createSuperAdmin($client);
        $client->loginUser($admin);

        $client->request('GET', '/admin/api/app-settings/models/nonexistent_ai');
        $this->assertSame(400, $client->getResponse()->getStatusCode());

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('Unknown provider', $data['error']);
        $this->assertSame('UNKNOWN_PROVIDER', $data['code']);
    }

    public function testAppSettingsOllamaUrlNotConfiguredReturns422(): void
    {
        $client = static::createClient();
        $admin = $this->createSuperAdmin($client);
        $client->loginUser($admin);

        $settingsRepo = static::getContainer()->get(AppSettingsRepository::class);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $settings = $settingsRepo->getOrCreate();
        $settings->aiProviders = ['ollama' => ['enabled' => true, 'url' => '']];
        $em->flush();

        $client->request('GET', '/admin/api/app-settings/models/ollama');
        $this->assertSame(422, $client->getResponse()->getStatusCode());

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('Ollama URL not configured', $data['error']);
        $this->assertSame('OLLAMA_URL_NOT_CONFIGURED', $data['code']);
    }

    public function testAppSettingsOllamaSsrfCloudMetadataAndEvasiveNotationsBlocked(): void
    {
        $client = static::createClient();
        $admin = $this->createSuperAdmin($client);
        $client->loginUser($admin);

        $settingsRepo = static::getContainer()->get(AppSettingsRepository::class);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $settings = $settingsRepo->getOrCreate();

        $forbiddenUrls = [
            'http://169.254.169.254/latest/meta-data', // AWS/GCP IMDS IPv4
            'http://2852039166:11434',                 // Dword integer for 169.254.169.254
            'http://0xa9fea9fe:11434',                 // Hex for 169.254.169.254
            'http://0251.0372.0251.0372:11434',        // Octal for 169.254.169.254
            'http://metadata.google.internal',         // GCP metadata hostname
            'http://instance-data',                    // AWS metadata alias
            'http://100.100.100.200:11434',            // Alibaba metadata
            'http://[fd00:ec2::254]:11434',            // AWS IPv6 metadata
        ];

        foreach ($forbiddenUrls as $url) {
            $settings->aiProviders = ['ollama' => ['enabled' => true, 'url' => $url]];
            $em->flush();

            $client->request('GET', '/admin/api/app-settings/models/ollama');
            $this->assertSame(403, $client->getResponse()->getStatusCode(), "Failed on URL: $url");

            $data = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertSame('OLLAMA_METADATA_FORBIDDEN', $data['code']);
            $this->assertStringContainsString('cloud metadata', $data['error']);
        }
    }

    public function testAppSettingsOllamaCredentialsInUrlRejected(): void
    {
        $client = static::createClient();
        $admin = $this->createSuperAdmin($client);
        $client->loginUser($admin);

        $settingsRepo = static::getContainer()->get(AppSettingsRepository::class);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $settings = $settingsRepo->getOrCreate();
        $settings->aiProviders = ['ollama' => ['enabled' => true, 'url' => 'http://admin:pass@127.0.0.1:11434']];
        $em->flush();

        $client->request('GET', '/admin/api/app-settings/models/ollama');
        $this->assertSame(422, $client->getResponse()->getStatusCode());

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('USERINFO_FORBIDDEN', $data['code']);
    }

    public function testAppSettingsUpdateAppNameValidation(): void
    {
        $client = static::createClient();
        $admin = $this->createSuperAdmin($client);
        $client->loginUser($admin);

        // 1. App name > 100 characters
        $client->request('POST', '/admin/api/app-settings', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'appName' => str_repeat('A', 101),
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data1 = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('APP_NAME_TOO_LONG', $data1['code']);

        // 2. Control characters in app name
        $client->request('POST', '/admin/api/app-settings', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'appName' => "App\r\nName",
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data2 = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('APP_NAME_INVALID', $data2['code']);

        // 3. XSS in app name
        $client->request('POST', '/admin/api/app-settings', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'appName' => '<script>alert(1)</script> MyApp',
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data3 = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('APP_NAME_XSS_DETECTED', $data3['code']);
    }

    public function testAppSettingsUpdateAiProvidersUrlSsrfValidation(): void
    {
        $client = static::createClient();
        $admin = $this->createSuperAdmin($client);
        $client->loginUser($admin);

        // Updating Ollama URL with cloud metadata is rejected
        $client->request('POST', '/admin/api/app-settings', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'aiProviders' => [
                'ollama' => [
                    'enabled' => true,
                    'url' => 'http://169.254.169.254/latest',
                ],
            ],
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('OLLAMA_METADATA_FORBIDDEN', $data['code']);
    }

    public function testAppSettingsUpdateOidcProvidersValidation(): void
    {
        $client = static::createClient();
        $admin = $this->createSuperAdmin($client);
        $client->loginUser($admin);

        // 1. OIDC provider name with XSS
        $client->request('POST', '/admin/api/app-settings', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'oidcProviders' => [
                [
                    'name' => '<script>alert(1)</script>',
                    'issuer' => 'https://auth.example.com',
                ],
            ],
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data1 = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_OIDC_NAME', $data1['code']);

        // 2. OIDC issuer URL targeting cloud metadata
        $client->request('POST', '/admin/api/app-settings', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'oidcProviders' => [
                [
                    'name' => 'Evil OIDC',
                    'issuer' => 'http://169.254.169.254/oauth',
                ],
            ],
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data2 = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_OIDC_ISSUER', $data2['code']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. RedirectController Hardening Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function testRedirectControllerInvalidProjectUuidReturns404(): void
    {
        $client = static::createClient();
        $admin = $this->createSuperAdmin($client);
        $client->loginUser($admin);

        $client->request('GET', '/admin-api/invalid-not-a-uuid/redirects');
        $this->assertSame(404, $client->getResponse()->getStatusCode());

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('PROJECT_NOT_FOUND', $data['code']);
    }

    public function testRedirectControllerOpenRedirectBackslashAndEvasionsBlocked(): void
    {
        $client = static::createClient();
        $admin = $this->createSuperAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);
        $projectUuid = $project->uuid->toString();

        // 1. Leading backslash protocol-relative evasion
        $client->request('POST', "/admin-api/{$projectUuid}/redirects", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'fromPath' => '/backslash-evasion',
            'toPath' => '\\evil.com/phishing',
            'httpCode' => 301,
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data1 = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('PROTOCOL_RELATIVE_FORBIDDEN', $data1['code']);

        // 2. URL-encoded protocol-relative evasion (%2f%2f)
        $client->request('POST', "/admin-api/{$projectUuid}/redirects", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'fromPath' => '/encoded-proto-evasion',
            'toPath' => '%2f%2fevil.com/steal',
            'httpCode' => 301,
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data2 = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('PROTOCOL_RELATIVE_FORBIDDEN', $data2['code']);

        // 3. Single-slash HTTP scheme evasion (http:evil.com)
        $client->request('POST', "/admin-api/{$projectUuid}/redirects", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'fromPath' => '/single-slash-evasion',
            'toPath' => 'http:evil.com/landing',
            'httpCode' => 301,
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data3 = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_SCHEME', $data3['code']);

        // 4. Path traversal in relative target path
        $client->request('POST', "/admin-api/{$projectUuid}/redirects", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'fromPath' => '/traversal-evasion',
            'toPath' => '/../admin/secret',
            'httpCode' => 301,
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data4 = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('PATH_TRAVERSAL_FORBIDDEN', $data4['code']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. FormController Hardening Tests
    // ─────────────────────────────────────────────────────────────────────────

    public function testFormControllerInvalidProjectUuidReturns404(): void
    {
        $client = static::createClient();
        $admin = $this->createSuperAdmin($client);
        $client->loginUser($admin);

        $client->request('GET', '/admin-api/not-a-valid-uuid/forms');
        $this->assertSame(404, $client->getResponse()->getStatusCode());

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('PROJECT_NOT_FOUND', $data['code']);
    }

    public function testFormControllerPayloadTooLargeReturns413(): void
    {
        $client = static::createClient();
        $admin = $this->createSuperAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);
        $projectUuid = $project->uuid->toString();

        $oversizedName = str_repeat('X', 550000);
        $client->request('POST', "/admin-api/{$projectUuid}/forms", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => $oversizedName,
            'fields' => [['name' => 'email', 'type' => 'email']],
        ]));
        $this->assertSame(413, $client->getResponse()->getStatusCode());

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('PAYLOAD_TOO_LARGE', $data['code']);
        $this->assertStringContainsString('512 KB', $data['error']);
    }

    public function testFormControllerSettingsWebhookUrlSsrfBlocked(): void
    {
        $client = static::createClient();
        $admin = $this->createSuperAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);
        $projectUuid = $project->uuid->toString();

        $client->request('POST', "/admin-api/{$projectUuid}/forms", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Contact Form with SSRF Webhook',
            'fields' => [['name' => 'email', 'label' => 'Email Address', 'type' => 'email']],
            'settings' => [
                'webhookUrl' => 'http://169.254.169.254/webhook',
            ],
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());

        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame('SETTINGS_SSRF_DETECTED', $data['code']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Strict I18N Parity Check
    // ─────────────────────────────────────────────────────────────────────────

    public function testTranslationsStrictParityAcrossAllFourLanguages(): void
    {
        $baseDir = static::getContainer()->getParameter('kernel.project_dir');
        $en = require $baseDir . '/translations/messages.en.php';
        $fr = require $baseDir . '/translations/messages.fr.php';
        $es = require $baseDir . '/translations/messages.es.php';
        $ar = require $baseDir . '/translations/messages.ar.php';

        $this->assertSame(2476, count($en), 'Expected 2476 translation keys in English');
        $this->assertSame(2476, count($fr), 'Expected 2476 translation keys in French');
        $this->assertSame(2476, count($es), 'Expected 2476 translation keys in Spanish');
        $this->assertSame(2476, count($ar), 'Expected 2476 translation keys in Arabic');

        $this->assertEmpty(array_diff_key($en, $fr), 'Missing keys between EN and FR');
        $this->assertEmpty(array_diff_key($en, $es), 'Missing keys between EN and ES');
        $this->assertEmpty(array_diff_key($en, $ar), 'Missing keys between EN and AR');

        // Verify specific Cycle 41 keys exist
        $this->assertArrayHasKey('app_settings.admin.unknown_provider', $en);
        $this->assertArrayHasKey('app_settings.admin.ollama_metadata_forbidden', $en);
        $this->assertArrayHasKey('redirect.admin.path_traversal_forbidden', $en);
        $this->assertArrayHasKey('form.admin.payload_too_large', $en);
    }
}
