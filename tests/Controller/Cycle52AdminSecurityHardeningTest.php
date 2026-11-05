<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class Cycle52AdminSecurityHardeningTest extends WebTestCase
{
    private function createAdmin(): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->name = 'Cycle52 Admin';
        $user->email = 'cycle52-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'hashed_pass_52';
        $user->roles = ['ROLE_SUPER_ADMIN'];
        $user->locale = 'en';
        $em->persist($user);

        $project = new Project();
        $project->name = 'Cycle52 Project ' . bin2hex(random_bytes(4));
        $em->persist($project);

        $em->flush();

        return [$user, $project];
    }

    private function assertSecurityHeaders($response): void
    {
        self::assertTrue(
            $response->headers->contains('X-Content-Type-Options', 'nosniff'),
            'Missing X-Content-Type-Options: nosniff'
        );
        self::assertTrue(
            $response->headers->contains('X-Frame-Options', 'DENY'),
            'Missing X-Frame-Options: DENY'
        );
        self::assertTrue(
            str_contains((string) $response->headers->get('Cache-Control'), 'no-store'),
            'Missing Cache-Control: no-store'
        );
    }

    public function testTranslationParityAcrossAllLocales(): void
    {
        $en = require __DIR__ . '/../../translations/messages.en.php';
        $fr = require __DIR__ . '/../../translations/messages.fr.php';
        $es = require __DIR__ . '/../../translations/messages.es.php';
        $ar = require __DIR__ . '/../../translations/messages.ar.php';

        self::assertIsArray($en);
        self::assertIsArray($fr);
        self::assertIsArray($es);
        self::assertIsArray($ar);

        $keysToCheck = [
            'redirect.admin.payload_too_large',
            'app_settings.admin.payload_too_large',
            'project.storage.payload_too_large',
            'project.jwt_ttl.payload_too_large',
            'security.2fa.payload_too_large',
        ];

        foreach ($keysToCheck as $k) {
            self::assertArrayHasKey($k, $en, "Missing $k in messages.en.php");
            self::assertArrayHasKey($k, $fr, "Missing $k in messages.fr.php");
            self::assertArrayHasKey($k, $es, "Missing $k in messages.es.php");
            self::assertArrayHasKey($k, $ar, "Missing $k in messages.ar.php");
        }
    }

    public function testFormSecurityHeadersAndPayloadLimit(): void
    {
        $client = static::createClient();
        [$admin, $project] = $this->createAdmin();
        $client->loginUser($admin);

        // 1. Invalid UUID
        $client->request('GET', '/admin-api/invalid-uuid/forms');
        self::assertSame(404, $client->getResponse()->getStatusCode());
        $this->assertSecurityHeaders($client->getResponse());

        // 2. Oversized payload (> 512KB)
        $uuid = $project->uuid->toRfc4122();
        $hugeData = str_repeat('A', 524290);
        $client->request(
            'POST',
            "/admin-api/{$uuid}/forms",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Form', 'fields' => [], 'extra' => $hugeData])
        );
        self::assertSame(413, $client->getResponse()->getStatusCode());
        $this->assertSecurityHeaders($client->getResponse());
    }

    public function testRedirectSecurityHeadersAndPayloadLimit(): void
    {
        $client = static::createClient();
        [$admin, $project] = $this->createAdmin();
        $client->loginUser($admin);

        // 1. Invalid UUID
        $client->request('GET', '/admin-api/invalid-uuid/redirects');
        self::assertSame(404, $client->getResponse()->getStatusCode());
        $this->assertSecurityHeaders($client->getResponse());

        // 2. Oversized payload (> 64KB)
        $uuid = $project->uuid->toRfc4122();
        $hugeData = str_repeat('B', 66000);
        $client->request(
            'POST',
            "/admin-api/{$uuid}/redirects",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['fromPath' => '/old', 'toPath' => '/new', 'extra' => $hugeData])
        );
        self::assertSame(413, $client->getResponse()->getStatusCode());
        $this->assertSecurityHeaders($client->getResponse());
    }

    public function testAppSettingsSecurityHeaders(): void
    {
        $client = static::createClient();
        [$admin] = $this->createAdmin();
        $client->loginUser($admin);

        $client->request('GET', '/admin/api/app-settings');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertSecurityHeaders($client->getResponse());

        // Oversized JSON payload (> 512KB)
        $hugeData = str_repeat('C', 525000);
        $client->request(
            'POST',
            '/admin/api/app-settings',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['appName' => 'Test', 'extra' => $hugeData])
        );
        self::assertSame(413, $client->getResponse()->getStatusCode());
        $this->assertSecurityHeaders($client->getResponse());
    }

    public function testProjectStorageSecurityHeadersAndPayloadLimit(): void
    {
        $client = static::createClient();
        [$admin, $project] = $this->createAdmin();
        $client->loginUser($admin);

        // 1. Invalid UUID
        $client->request('GET', '/api/admin/projects/not-a-uuid/storage');
        self::assertSame(404, $client->getResponse()->getStatusCode());
        $this->assertSecurityHeaders($client->getResponse());

        // 2. Oversized payload (> 64KB)
        $uuid = $project->uuid->toRfc4122();
        $hugeData = str_repeat('D', 66000);
        $client->request(
            'PUT',
            "/api/admin/projects/{$uuid}/storage",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['strategy' => 'rules', 'extra' => $hugeData])
        );
        self::assertSame(413, $client->getResponse()->getStatusCode());
        $this->assertSecurityHeaders($client->getResponse());
    }

    public function testProjectJwtTtlSecurityHeadersAndPayloadLimit(): void
    {
        $client = static::createClient();
        [$admin, $project] = $this->createAdmin();
        $client->loginUser($admin);

        // 1. Invalid UUID
        $client->request('GET', '/api/admin/projects/not-a-uuid/jwt-ttl');
        self::assertSame(404, $client->getResponse()->getStatusCode());
        $this->assertSecurityHeaders($client->getResponse());

        // 2. Oversized payload (> 16KB)
        $uuid = $project->uuid->toRfc4122();
        $hugeData = str_repeat('E', 17000);
        $client->request(
            'PATCH',
            "/api/admin/projects/{$uuid}/jwt-ttl",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['jwt_access_ttl' => 3600, 'extra' => $hugeData])
        );
        self::assertSame(413, $client->getResponse()->getStatusCode());
        $this->assertSecurityHeaders($client->getResponse());
    }

    public function testProjectMailerSecurityHeadersAndPayloadLimit(): void
    {
        $client = static::createClient();
        [$admin, $project] = $this->createAdmin();
        $client->loginUser($admin);

        // 1. Invalid UUID
        $client->request('GET', '/api/admin/projects/not-a-uuid/mailer');
        self::assertSame(404, $client->getResponse()->getStatusCode());
        $this->assertSecurityHeaders($client->getResponse());

        // 2. Oversized payload (> 64KB)
        $uuid = $project->uuid->toRfc4122();
        $hugeData = str_repeat('F', 66000);
        $client->request(
            'PUT',
            "/api/admin/projects/{$uuid}/mailer",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['host' => 'smtp.example.com', 'extra' => $hugeData])
        );
        self::assertSame(413, $client->getResponse()->getStatusCode());
        $this->assertSecurityHeaders($client->getResponse());
    }

    public function testSecurityControllerSecurityHeadersAndPayloadLimit(): void
    {
        $client = static::createClient();
        [$admin] = $this->createAdmin();
        $client->loginUser($admin);

        // 1. Status response
        $client->request('GET', '/api/settings/security');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertSecurityHeaders($client->getResponse());

        // 2. Oversized payload (> 64KB) on TOTP setup
        $hugeData = str_repeat('G', 66000);
        $client->request(
            'POST',
            '/api/settings/security/totp/setup',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['password' => 'secret', 'extra' => $hugeData])
        );
        self::assertSame(413, $client->getResponse()->getStatusCode());
        $this->assertSecurityHeaders($client->getResponse());
    }
}
