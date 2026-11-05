<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class InstallControllerTest extends WebTestCase
{
    private function assertSecurityHeaders(Response $response): void
    {
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testInstallAccessWhenNoAdminAndBlockedWhenAdminExists(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        // Ensure database is clean of users for install test
        $em->createQuery('DELETE FROM App\Entity\ProjectMember')->execute();
        $em->createQuery('DELETE FROM App\Entity\User')->execute();

        // 1. Initial access when zero users: should be 200 OK with security headers
        $client->request('GET', '/install');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertSecurityHeaders($client->getResponse());
        self::assertStringContainsString('Diagnostic', (string) $client->getResponse()->getContent());

        $client->request('GET', '/install/check');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertSecurityHeaders($client->getResponse());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('ready', $data);

        // 2. Setup admin validation: payload cap (> 16 KB)
        $client->request('POST', '/install/setup-admin', server: ['CONTENT_TYPE' => 'application/json'], content: str_repeat('b', 16385));
        self::assertSame(413, $client->getResponse()->getStatusCode());
        $this->assertSecurityHeaders($client->getResponse());

        // Setup admin validation: invalid json
        $client->request('POST', '/install/setup-admin', server: ['CONTENT_TYPE' => 'application/json'], content: '{not-json}');
        self::assertSame(400, $client->getResponse()->getStatusCode());
        $this->assertSecurityHeaders($client->getResponse());

        // Setup admin validation: invalid email
        $client->request('POST', '/install/setup-admin', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => 'invalid-email',
            'password' => 'ValidPass123!@#',
        ]));
        self::assertSame(400, $client->getResponse()->getStatusCode());

        // 3. Setup admin success
        $client->request('POST', '/install/setup-admin', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => 'admin@example.com',
            'password' => 'supersecretpass123',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $this->assertSecurityHeaders($client->getResponse());

        // 4. Subsequent access: must be 403 Forbidden
        $client->request('GET', '/install');
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $this->assertSecurityHeaders($client->getResponse());
        self::assertStringContainsString('Already Installed', (string) $client->getResponse()->getContent());

        $client->request('GET', '/install/check');
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $this->assertSecurityHeaders($client->getResponse());

        $client->request('POST', '/install/setup-admin', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => 'admin2@example.com',
            'password' => 'supersecretpass123',
        ]));
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $this->assertSecurityHeaders($client->getResponse());
    }
}
