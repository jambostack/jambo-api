<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Notification;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

class NotificationAndAuditHardeningTest extends WebTestCase
{
    private function createUser(EntityManagerInterface $em, UserPasswordHasherInterface $hasher, string $role): User
    {
        $user = new User();
        $user->email = 'test_' . bin2hex(random_bytes(6)) . '@example.com';
        $user->name = 'User ' . $role;
        $user->password = $hasher->hashPassword($user, 'Password123!');
        $user->roles = [$role];
        $em->persist($user);
        $em->flush();

        return $user;
    }

    public function testNotificationEndpointsEnforceSecurityHeadersAndAuth(): void
    {
        $client = static::createClient();

        // 1. Unauthenticated index
        $client->request('GET', '/api/notifications');
        $response = $client->getResponse();
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        // 2. Unauthenticated unread count
        $client->request('GET', '/api/notifications/unread-count');
        $this->assertSame(401, $client->getResponse()->getStatusCode());

        // 3. Authenticated user access
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = $this->createUser($em, $hasher, 'ROLE_USER');
        $client->loginUser($user);

        $client->request('GET', '/api/notifications');
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertSame('DENY', $client->getResponse()->headers->get('X-Frame-Options'));

        $client->request('GET', '/api/notifications/unread-count');
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('count', $data);

        // 4. Invalid project UUID filter
        $client->request('GET', '/api/notifications?project=invalid-not-uuid');
        $this->assertSame(404, $client->getResponse()->getStatusCode());

        // 5. Mark non-existent notification as read
        $client->request('POST', '/api/notifications/999999/read');
        $this->assertSame(404, $client->getResponse()->getStatusCode());

        // 6. Mark all as read
        $client->request('POST', '/api/notifications/read-all');
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $readAllData = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertSame(0, $readAllData['unread_count']);
    }

    public function testAuditEndpointsSecurityHeadersAndAccessControl(): void
    {
        $client = static::createClient();

        // 1. Errors endpoint anonymous
        $client->request('GET', '/api/admin/audit-logs/errors');
        $response = $client->getResponse();
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));

        // 2. Errors endpoint with regular user -> 403
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = $this->createUser($em, $hasher, 'ROLE_USER');
        $client->loginUser($user);

        $client->request('GET', '/api/admin/audit-logs/errors');
        $this->assertSame(403, $client->getResponse()->getStatusCode());
        $this->assertSame('DENY', $client->getResponse()->headers->get('X-Frame-Options'));

        // 3. Errors endpoint with super admin -> 200
        $superAdmin = $this->createUser($em, $hasher, 'ROLE_SUPER_ADMIN');
        $client->loginUser($superAdmin);

        $client->request('GET', '/api/admin/audit-logs/errors');
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertSame('DENY', $client->getResponse()->headers->get('X-Frame-Options'));

        // 4. Project audit logs with invalid UUID format -> 404
        $client->request('GET', '/api/projects/not-a-valid-uuid/audit-logs');
        $this->assertSame(404, $client->getResponse()->getStatusCode());
        $this->assertSame('DENY', $client->getResponse()->headers->get('X-Frame-Options'));
    }

    public function testSearchEndpointSecurityHeadersAndInvalidUuid(): void
    {
        $client = static::createClient();

        // Unauthenticated access
        $client->request('GET', '/api/projects/invalid-uuid-format/search?q=test');
        $this->assertSame(401, $client->getResponse()->getStatusCode());

        // Authenticated super admin access with invalid project UUID format
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $superAdmin = $this->createUser($em, $hasher, 'ROLE_SUPER_ADMIN');
        $client->loginUser($superAdmin);

        $client->request('GET', '/api/projects/invalid-uuid-format/search?q=test');
        $response = $client->getResponse();
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
