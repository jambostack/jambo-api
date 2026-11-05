<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PluginControllerSecurityTest extends WebTestCase
{
    public function testAnonymousDenied(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/admin/plugins');
        self::assertSame(401, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/admin/plugins/validate', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertSame(401, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/admin/plugins', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testRegularUserForbidden(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->email = 'reg-' . bin2hex(random_bytes(4)) . '@test.com';
        $user->password = 'hash';
        $user->roles = ['ROLE_USER'];
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        $client->request('GET', '/api/admin/plugins');
        self::assertSame(403, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/admin/plugins/validate', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testSuperAdminAllowedAndManifestValidation(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = new User();
        $admin->email = 'admin-' . bin2hex(random_bytes(4)) . '@test.com';
        $admin->password = 'hash';
        $admin->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($admin);
        $em->flush();

        $client->loginUser($admin);

        // List
        $client->request('GET', '/api/admin/plugins');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('data', $body);

        // Validate bad manifest
        $client->request('POST', '/api/admin/plugins/validate', server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['invalid' => true]));
        self::assertSame(422, $client->getResponse()->getStatusCode());

        // Oversized manifest (> 64KB)
        $oversized = json_encode(['id' => 'big', 'blob' => str_repeat('A', 70000)]);
        $client->request('POST', '/api/admin/plugins/validate', server: ['CONTENT_TYPE' => 'application/json'],
            content: $oversized);
        self::assertSame(413, $client->getResponse()->getStatusCode());
    }
}
