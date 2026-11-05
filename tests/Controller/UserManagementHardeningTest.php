<?php

namespace App\Tests\Controller;

use App\Entity\Permission;
use App\Entity\Role;
use App\Entity\User;
use App\Repository\AuditLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class UserManagementHardeningTest extends WebTestCase
{
    private function createSuperAdmin(EntityManagerInterface $em): User
    {
        $admin = new User();
        $admin->email = 'superadmin_' . bin2hex(random_bytes(4)) . '@example.com';
        $admin->name = 'Super Admin';
        $admin->password = 'AdminPassword123!';
        $admin->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($admin);
        $em->flush();

        return $admin;
    }

    private function createDelegatedAdmin(EntityManagerInterface $em, array $permissionNames): User
    {
        $permRepo = $em->getRepository(Permission::class);
        $role = new Role();
        $role->name = 'delegated_' . bin2hex(random_bytes(4));
        $role->label = 'Delegated Role';

        foreach ($permissionNames as $name) {
            $perm = $permRepo->findOneBy(['name' => $name]);
            if ($perm === null) {
                $perm = new Permission();
                $perm->name = $name;
                $perm->label = ucfirst(str_replace('.', ' ', $name));
                $perm->group = 'custom';
                $em->persist($perm);
            }
            $role->permissions->add($perm);
        }

        $em->persist($role);

        $user = new User();
        $user->email = 'delegated_' . bin2hex(random_bytes(4)) . '@example.com';
        $user->name = 'Delegated Admin';
        $user->password = 'UserPassword123!';
        $user->roles = ['ROLE_USER'];
        $user->userRoles->add($role);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    public function testNonSuperAdminCannotCreateSuperAdmin(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $delegated = $this->createDelegatedAdmin($em, ['users.manage']);
        $client->loginUser($delegated);

        $client->request('POST', '/api/users', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Escalated User',
            'email' => 'escalate_' . bin2hex(random_bytes(4)) . '@example.com',
            'password' => 'SecurePass123!',
            'is_super_admin' => true,
        ]));

        $this->assertSame(403, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testNonSuperAdminCannotAssignRoleWithUnownedPermissions(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $delegated = $this->createDelegatedAdmin($em, ['users.manage']);
        $client->loginUser($delegated);

        // Create a powerful role with permissions the delegated admin does NOT have
        $permRepo = $em->getRepository(Permission::class);
        $secretPerm = $permRepo->findOneBy(['name' => 'project.manage']);
        if ($secretPerm === null) {
            $secretPerm = new Permission();
            $secretPerm->name = 'project.manage';
            $secretPerm->label = 'Manage Project';
            $secretPerm->group = 'project';
            $em->persist($secretPerm);
        }
        $powerfulRole = new Role();
        $powerfulRole->name = 'powerful_' . bin2hex(random_bytes(4));
        $powerfulRole->label = 'Powerful Role';
        $powerfulRole->permissions->add($secretPerm);
        $em->persist($powerfulRole);
        $em->flush();

        $client->request('POST', '/api/users', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Target User',
            'email' => 'target_' . bin2hex(random_bytes(4)) . '@example.com',
            'password' => 'SecurePass123!',
            'roles' => [$powerfulRole->id],
        ]));

        $this->assertSame(403, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testNonSuperAdminCannotModifyOrDeleteSuperAdmin(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $super1 = $this->createSuperAdmin($em);
        $super2 = $this->createSuperAdmin($em);

        $delegated = $this->createDelegatedAdmin($em, ['users.manage']);
        $client->loginUser($delegated);

        // Attempt update of superadmin
        $client->request('PUT', '/api/users/' . $super1->id, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Hacked Name',
        ]));
        $this->assertSame(403, $client->getResponse()->getStatusCode());

        // Attempt deletion of superadmin (multiple superadmins exist)
        $client->request('DELETE', '/api/users/' . $super1->id);
        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testUserCannotDeleteSelf(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = $this->createSuperAdmin($em);
        $client->loginUser($admin);

        $client->request('DELETE', '/api/users/' . $admin->id);
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testUserCreationAndAuditLogging(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $auditRepo = $em->getRepository(\App\Entity\AuditLog::class);

        $admin = $this->createSuperAdmin($em);
        $client->loginUser($admin);

        $uniqueEmail = 'newadmin_' . bin2hex(random_bytes(4)) . '@example.com';
        $client->request('POST', '/api/users', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Brand New Admin',
            'email' => $uniqueEmail,
            'password' => 'StrongP@ssw0rd2026!',
        ]));

        $this->assertSame(201, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame($uniqueEmail, $data['email']);
        $this->assertArrayHasKey('message', $data);

        // Verify audit log
        $auditLogs = $auditRepo->findBy(['toolName' => 'user_create'], ['id' => 'DESC'], 5);
        $this->assertNotEmpty($auditLogs);
        $latest = $auditLogs[0];
        $this->assertSame('user_management', $latest->source);
        $this->assertSame($admin->email, $latest->createdBy);
    }

    public function testRoleCreationAndProtection(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = $this->createSuperAdmin($em);
        $client->loginUser($admin);

        // Create new role
        $roleName = 'editor_' . bin2hex(random_bytes(4));
        $client->request('POST', '/api/roles', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => $roleName,
            'label' => 'Content Editor',
            'permissions' => ['content.create'],
        ]));

        $this->assertSame(201, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame($roleName, $data['name']);
        $this->assertArrayHasKey('message', $data);

        // Delegated admin without content.delete cannot assign content.delete to a new role
        $delegated = $this->createDelegatedAdmin($em, ['roles.manage', 'content.create']);
        $client->loginUser($delegated);

        $client->request('POST', '/api/roles', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'unowned_perm_role_' . bin2hex(random_bytes(4)),
            'label' => 'Unowned Perm Role',
            'permissions' => ['content.delete'],
        ]));

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testPermissionCrudAndSystemProtection(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $admin = $this->createSuperAdmin($em);
        $client->loginUser($admin);

        // 1. Create a custom permission
        $permName = 'custom.feature_' . bin2hex(random_bytes(4));
        $client->request('POST', '/api/permissions', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => $permName,
            'label' => 'Custom Feature Access',
            'group' => 'features',
        ]));

        $this->assertSame(201, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame($permName, $data['name']);
        $this->assertArrayHasKey('message', $data);
        $permId = $data['id'];

        // 2. Format validation failure on invalid syntax
        $client->request('POST', '/api/permissions', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'INVALID PERMISSION!',
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());

        // 3. Update the created permission
        $client->request('PUT', '/api/permissions/' . $permId, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label' => 'Updated Custom Feature Label',
        ]));
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $updatedData = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Updated Custom Feature Label', $updatedData['label']);

        // 4. Delete the custom permission
        $client->request('DELETE', '/api/permissions/' . $permId);
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        // 5. Try deleting protected system permission (e.g. users.manage) -> 422
        $sysPerm = $em->getRepository(Permission::class)->findOneBy(['name' => 'users.manage']);
        if ($sysPerm !== null) {
            $client->request('DELETE', '/api/permissions/' . $sysPerm->id);
            $this->assertSame(422, $client->getResponse()->getStatusCode());
            $errorData = json_decode($client->getResponse()->getContent(), true);
            $this->assertArrayHasKey('error', $errorData);
        }
    }

    public function testUserManagementTranslationsParity(): void
    {
        $en = require __DIR__ . '/../../translations/messages.en.php';
        $fr = require __DIR__ . '/../../translations/messages.fr.php';
        $es = require __DIR__ . '/../../translations/messages.es.php';
        $ar = require __DIR__ . '/../../translations/messages.ar.php';

        $prefixes = ['user.admin.', 'role.admin.', 'permission.admin.'];

        foreach ($prefixes as $prefix) {
            $prefixKeys = array_filter(array_keys($en), fn ($k) => str_starts_with($k, $prefix));
            $this->assertNotEmpty($prefixKeys, "Expected keys for prefix: $prefix");

            foreach ($prefixKeys as $key) {
                $this->assertArrayHasKey($key, $fr, "Missing key in FR: $key");
                $this->assertArrayHasKey($key, $es, "Missing key in ES: $key");
                $this->assertArrayHasKey($key, $ar, "Missing key in AR: $key");
                $this->assertNotEmpty($fr[$key], "Empty translation in FR: $key");
                $this->assertNotEmpty($es[$key], "Empty translation in ES: $key");
                $this->assertNotEmpty($ar[$key], "Empty translation in AR: $key");
            }
        }
    }
}
