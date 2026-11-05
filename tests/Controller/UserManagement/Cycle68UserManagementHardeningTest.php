<?php

declare(strict_types=1);

namespace App\Tests\Controller\UserManagement;

use App\Entity\Permission;
use App\Entity\Role;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class Cycle68UserManagementHardeningTest extends WebTestCase
{
    private function createSuperAdmin(EntityManagerInterface $em, UserPasswordHasherInterface $hasher): User
    {
        $admin = new User();
        $admin->email = 'superadmin_' . bin2hex(random_bytes(6)) . '@example.com';
        $admin->name = 'Super Admin';
        $admin->password = $hasher->hashPassword($admin, 'StrongAdminPass123!');
        $admin->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($admin);
        $em->flush();

        return $admin;
    }

    public function testUserEndpointsReturnSecurityHeaders(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = $this->createSuperAdmin($em, $hasher);

        $client->loginUser($admin);
        $client->request('GET', '/api/users');

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testUserNameAndEmailControlCharsRejectedOnCreate(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = $this->createSuperAdmin($em, $hasher);

        $client->loginUser($admin);

        // Name with null byte / control char
        $client->request('POST', '/api/users', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => "Malicious\x00User",
            'email' => 'valid_' . bin2hex(random_bytes(4)) . '@example.com',
            'password' => 'ValidPass123!',
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());

        // Email with control char
        $client->request('POST', '/api/users', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Valid User',
            'email' => "malicious\x0d@example.com",
            'password' => 'ValidPass123!',
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testUserNameEmptyOrControlCharsRejectedOnUpdate(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = $this->createSuperAdmin($em, $hasher);

        $targetUser = new User();
        $targetUser->email = 'target_' . bin2hex(random_bytes(4)) . '@example.com';
        $targetUser->name = 'Target User';
        $targetUser->password = $hasher->hashPassword($targetUser, 'ValidPass123!');
        $targetUser->roles = ['ROLE_USER'];
        $em->persist($targetUser);
        $em->flush();

        $client->loginUser($admin);

        // Empty name
        $client->request('PATCH', '/api/users/' . $targetUser->id, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => '   ',
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());

        // Control characters in name
        $client->request('PATCH', '/api/users/' . $targetUser->id, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => "Injected\x08Name",
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testRoleControlCharsAndPermissionsLimit(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = $this->createSuperAdmin($em, $hasher);

        $client->loginUser($admin);

        // Role with control characters in label
        $client->request('POST', '/api/roles', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'custom_role_' . bin2hex(random_bytes(4)),
            'label' => "Role\x00Label",
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());

        // Role exceeding permissions limit (> 200)
        $excessivePerms = array_map(fn ($i) => "custom.perm_{$i}", range(1, 205));
        $client->request('POST', '/api/roles', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'custom_role_' . bin2hex(random_bytes(4)),
            'label' => 'Large Role',
            'permissions' => $excessivePerms,
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testPermissionControlCharsRejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = $this->createSuperAdmin($em, $hasher);

        $client->loginUser($admin);

        // Permission with control char in label
        $client->request('POST', '/api/permissions', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'custom.action_' . bin2hex(random_bytes(3)),
            'label' => "Action\x1bLabel",
            'group' => 'custom',
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());

        // Permission with control char in group
        $client->request('POST', '/api/permissions', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'custom.action_' . bin2hex(random_bytes(3)),
            'label' => 'Action Label',
            'group' => "grp\x00bad",
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testSearchFilterQueryCapping(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = $this->createSuperAdmin($em, $hasher);

        $client->loginUser($admin);

        // Huge search strings (> 2000 chars) handled safely
        $hugeQuery = str_repeat('abcde', 500);
        $client->request('GET', '/api/users?search=' . urlencode($hugeQuery));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/roles?search=' . urlencode($hugeQuery));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/permissions?search=' . urlencode($hugeQuery));
        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }
}
