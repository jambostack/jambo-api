<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class InstallControllerTest extends WebTestCase
{
    public function testInstallAccessWhenNoAdminAndBlockedWhenAdminExists(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        // Ensure database is clean of users for install test
        $em->createQuery('DELETE FROM App\Entity\ProjectMember')->execute();
        $em->createQuery('DELETE FROM App\Entity\User')->execute();

        // 1. Initial access when zero users: should be 200 OK
        $client->request('GET', '/install');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Diagnostic', (string) $client->getResponse()->getContent());

        $client->request('GET', '/install/check');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('ready', $data);

        // 2. Setup admin
        $client->request('POST', '/install/setup-admin', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => 'admin@example.com',
            'password' => 'supersecretpass123',
        ]));
        self::assertSame(201, $client->getResponse()->getStatusCode());

        // 3. Subsequent access: must be 403 Forbidden
        $client->request('GET', '/install');
        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('déjà installée', (string) $client->getResponse()->getContent());

        $client->request('GET', '/install/check');
        self::assertSame(403, $client->getResponse()->getStatusCode());

        $client->request('POST', '/install/setup-admin', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => 'admin2@example.com',
            'password' => 'supersecretpass123',
        ]));
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }
}
