<?php

declare(strict_types=1);

namespace App\Tests\Controller\UserManagement;

use App\Entity\AuditLog;
use App\Entity\Permission;
use App\Entity\Role;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class Cycle111UserSecurityHardeningTest extends WebTestCase
{
    private function createSuperAdmin(EntityManagerInterface $em, UserPasswordHasherInterface $hasher): User
    {
        $admin = new User();
        $admin->email = 'superadmin_' . bin2hex(random_bytes(6)) . '@example.com';
        $admin->name = 'Super Admin';
        $admin->password = $hasher->hashPassword($admin, 'SuperSecretPass123!');
        $admin->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($admin);
        $em->flush();

        return $admin;
    }

    private function createDelegatedUser(EntityManagerInterface $em, UserPasswordHasherInterface $hasher, array $permissions): User
    {
        $role = new Role();
        $role->name = 'delegated_' . bin2hex(random_bytes(5));
        $role->label = 'Delegated Role';

        foreach ($permissions as $pName) {
            $p = $em->getRepository(Permission::class)->findOneBy(['name' => $pName]);
            if ($p === null) {
                $p = new Permission();
                $p->name = $pName;
                $p->label = ucfirst(str_replace('.', ' ', $pName));
                $p->group = 'custom';
                $em->persist($p);
            }
            $role->permissions->add($p);
        }
        $em->persist($role);

        $user = new User();
        $user->email = 'delegated_' . bin2hex(random_bytes(6)) . '@example.com';
        $user->name = 'Delegated Admin';
        $user->password = $hasher->hashPassword($user, 'DelegatedPass123!');
        $user->roles = ['ROLE_USER'];
        $user->userRoles->add($role);

        $em->persist($user);
        $em->flush();

        return $user;
    }

    public function testContentTypeValidationOnUserEndpoints(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = $this->createSuperAdmin($em, $hasher);

        $client->loginUser($admin);

        // 1. POST /api/users with text/plain
        $client->request('POST', '/api/users', [], [], ['CONTENT_TYPE' => 'text/plain'], 'name=test&email=test@example.com');
        $this->assertSame(415, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_CONTENT_TYPE', $data['code'] ?? null);

        // Verify audit log
        $auditLog = $em->getRepository(AuditLog::class)->findOneBy(['toolName' => 'user_invalid_content_type'], ['id' => 'DESC']);
        $this->assertNotNull($auditLog);
        $this->assertSame('failure', $auditLog->status);

        // Create a regular user for PUT test
        $user = new User();
        $user->name = 'Target User';
        $user->email = 'target_' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = $hasher->hashPassword($user, 'TargetPass123!');
        $user->roles = ['ROLE_USER'];
        $em->persist($user);
        $em->flush();

        // 2. PUT /api/users/{id} with text/plain
        $client->request('PUT', '/api/users/' . $user->id, [], [], ['CONTENT_TYPE' => 'text/plain'], 'name=updated');
        $this->assertSame(415, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_CONTENT_TYPE', $data['code'] ?? null);

        // 3. POST /api/users/bulk-delete with text/plain
        $client->request('POST', '/api/users/bulk-delete', [], [], ['CONTENT_TYPE' => 'text/plain'], 'ids=1');
        $this->assertSame(415, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_CONTENT_TYPE', $data['code'] ?? null);
    }

    public function testDelegatedAdminCannotCreateSuperAdmin(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $delegated = $this->createDelegatedUser($em, $hasher, ['users.manage']);
        $client->loginUser($delegated);

        // Attempt to create user with is_super_admin: true
        $client->request('POST', '/api/users', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Escalated User',
            'email' => 'escalated_' . bin2hex(random_bytes(4)) . '@example.com',
            'password' => 'EscalatePass123!',
            'is_super_admin' => true,
        ]));
        $this->assertSame(403, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);

        // Verify audit log for privilege escalation attempt
        $auditLog = $em->getRepository(AuditLog::class)->findOneBy(['toolName' => 'user_create_privilege_escalation_attempt'], ['id' => 'DESC']);
        $this->assertNotNull($auditLog);
        $this->assertSame('failure', $auditLog->status);
    }

    public function testDelegatedAdminCannotAssignProtectedRoleOnCreate(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        // Ensure protected ROLE_ADMIN exists
        $adminRole = $em->getRepository(Role::class)->findOneBy(['name' => 'ROLE_ADMIN']);
        if ($adminRole === null) {
            $adminRole = new Role();
            $adminRole->name = 'ROLE_ADMIN';
            $adminRole->label = 'Admin Role';
            $em->persist($adminRole);
            $em->flush();
        }

        $delegated = $this->createDelegatedUser($em, $hasher, ['users.manage']);
        $client->loginUser($delegated);

        // Delegated admin attempts to assign ROLE_ADMIN
        $client->request('POST', '/api/users', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Admin User Attempt',
            'email' => 'attempt_' . bin2hex(random_bytes(4)) . '@example.com',
            'password' => 'AttemptPass123!',
            'roles' => [$adminRole->id],
        ]));
        $this->assertSame(403, $client->getResponse()->getStatusCode());

        // Verify audit log
        $auditLog = $em->getRepository(AuditLog::class)->findOneBy(['toolName' => 'user_create_privilege_escalation_attempt'], ['id' => 'DESC']);
        $this->assertNotNull($auditLog);
        $this->assertSame('failure', $auditLog->status);
    }

    public function testDelegatedAdminCannotAssignUnownedPermissionsOnCreate(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        // Create a custom permission not held by delegated admin
        $unownedPerm = new Permission();
        $unownedPerm->name = 'unowned.secret_' . bin2hex(random_bytes(3));
        $unownedPerm->label = 'Unowned Secret';
        $unownedPerm->group = 'custom';
        $em->persist($unownedPerm);

        $customRole = new Role();
        $customRole->name = 'custom_role_' . bin2hex(random_bytes(4));
        $customRole->label = 'Custom Role';
        $customRole->permissions->add($unownedPerm);
        $em->persist($customRole);
        $em->flush();

        $delegated = $this->createDelegatedUser($em, $hasher, ['users.manage']);
        $client->loginUser($delegated);

        // Attempt to assign this role
        $client->request('POST', '/api/users', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Unowned Perm User',
            'email' => 'unowned_' . bin2hex(random_bytes(4)) . '@example.com',
            'password' => 'UnownedPass123!',
            'roles' => [$customRole->id],
        ]));
        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testInvalidRoleIdReturns422(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = $this->createSuperAdmin($em, $hasher);

        $client->loginUser($admin);

        // Create user with non-existent role id 999999
        $client->request('POST', '/api/users', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Invalid Role User',
            'email' => 'invalidrole_' . bin2hex(random_bytes(4)) . '@example.com',
            'password' => 'ValidPass123!',
            'roles' => [999999],
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
    }

    public function testDelegatedAdminCannotModifySuperAdmin(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = $this->createSuperAdmin($em, $hasher);

        $delegated = $this->createDelegatedUser($em, $hasher, ['users.manage']);
        $client->loginUser($delegated);

        // Attempt to update superadmin
        $client->request('PUT', '/api/users/' . $admin->id, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Compromised Name',
        ]));
        $this->assertSame(403, $client->getResponse()->getStatusCode());

        // Verify audit log
        $auditLog = $em->getRepository(AuditLog::class)->findOneBy(['toolName' => 'user_update_privilege_escalation_attempt'], ['id' => 'DESC']);
        $this->assertNotNull($auditLog);
        $this->assertSame('failure', $auditLog->status);
    }

    public function testDelegatedAdminCannotModifyOwnRoles(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $delegated = $this->createDelegatedUser($em, $hasher, ['users.manage']);
        $client->loginUser($delegated);

        // Delegated admin attempts to modify their own roles
        $client->request('PUT', '/api/users/' . $delegated->id, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'roles' => [],
        ]));
        $this->assertSame(403, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);

        // Verify audit log
        $auditLog = $em->getRepository(AuditLog::class)->findOneBy(['toolName' => 'user_update_privilege_escalation_attempt'], ['id' => 'DESC']);
        $this->assertNotNull($auditLog);
        $this->assertSame('failure', $auditLog->status);
    }

    public function testDelegatedAdminCannotDeleteSuperAdmin(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = $this->createSuperAdmin($em, $hasher);
        // Create second superadmin so it's not the "last" superadmin
        $this->createSuperAdmin($em, $hasher);

        $delegated = $this->createDelegatedUser($em, $hasher, ['users.manage']);
        $client->loginUser($delegated);

        // Delegated admin attempts to delete superadmin
        $client->request('DELETE', '/api/users/' . $admin->id);
        $this->assertSame(403, $client->getResponse()->getStatusCode());

        // Verify audit log
        $auditLog = $em->getRepository(AuditLog::class)->findOneBy(['toolName' => 'user_delete_privilege_escalation_attempt'], ['id' => 'DESC']);
        $this->assertNotNull($auditLog);
        $this->assertSame('failure', $auditLog->status);
    }

    public function testUserCannotDeleteSelf(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = $this->createSuperAdmin($em, $hasher);

        $client->loginUser($admin);

        // Admin attempts to delete own account
        $client->request('DELETE', '/api/users/' . $admin->id);
        $this->assertSame(422, $client->getResponse()->getStatusCode());

        // Verify audit log
        $auditLog = $em->getRepository(AuditLog::class)->findOneBy(['toolName' => 'user_delete_failed_self'], ['id' => 'DESC']);
        $this->assertNotNull($auditLog);
        $this->assertSame('failure', $auditLog->status);
    }

    public function testPayloadTooLargeOnUsers(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = $this->createSuperAdmin($em, $hasher);

        $client->loginUser($admin);

        $hugeData = str_repeat('A', 70000);

        // POST /api/users with >64KB payload
        $client->request('POST', '/api/users', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => $hugeData,
            'email' => 'huge@example.com',
            'password' => 'ValidPass123!',
        ]));
        $this->assertSame(413, $client->getResponse()->getStatusCode());
    }

    public function testSuccessfulUserLifecycleWithAuditLogs(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = $this->createSuperAdmin($em, $hasher);

        $client->loginUser($admin);

        $testEmail = 'lifecycle_' . bin2hex(random_bytes(4)) . '@example.com';

        // 1. Create User
        $client->request('POST', '/api/users', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Lifecycle User',
            'email' => $testEmail,
            'password' => 'LifecyclePass123!',
        ]));
        $this->assertSame(201, $client->getResponse()->getStatusCode());
        $createdData = json_decode($client->getResponse()->getContent(), true);
        $userId = $createdData['id'];

        $createLog = $em->getRepository(AuditLog::class)->findOneBy(['toolName' => 'user_create'], ['id' => 'DESC']);
        $this->assertNotNull($createLog);
        $this->assertSame('success', $createLog->status);

        // 2. Update User
        $client->request('PATCH', '/api/users/' . $userId, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Lifecycle User Updated',
        ]));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $updateLog = $em->getRepository(AuditLog::class)->findOneBy(['toolName' => 'user_update'], ['id' => 'DESC']);
        $this->assertNotNull($updateLog);
        $this->assertSame('success', $updateLog->status);

        // 3. Delete User
        $client->request('DELETE', '/api/users/' . $userId);
        $this->assertSame(204, $client->getResponse()->getStatusCode());

        $deleteLog = $em->getRepository(AuditLog::class)->findOneBy(['toolName' => 'user_delete'], ['id' => 'DESC']);
        $this->assertNotNull($deleteLog);
        $this->assertSame('success', $deleteLog->status);
    }
}
