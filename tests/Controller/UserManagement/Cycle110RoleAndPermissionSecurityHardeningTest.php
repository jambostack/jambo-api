<?php

declare(strict_types=1);

namespace App\Tests\Controller\UserManagement;

use App\Entity\AuditLog;
use App\Entity\Permission;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\Role;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class Cycle110RoleAndPermissionSecurityHardeningTest extends WebTestCase
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

    public function testContentTypeValidationOnRoles(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = $this->createSuperAdmin($em, $hasher);

        $client->loginUser($admin);

        // 1. POST /api/roles with text/plain
        $client->request('POST', '/api/roles', [], [], ['CONTENT_TYPE' => 'text/plain'], 'name=test');
        $this->assertSame(415, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_CONTENT_TYPE', $data['code'] ?? null);

        // Create a role to test PUT
        $role = new Role();
        $role->name = 'role_ct_' . bin2hex(random_bytes(4));
        $role->label = 'Test Role';
        $em->persist($role);
        $em->flush();

        // 2. PUT /api/roles/{id} with text/plain
        $client->request('PUT', '/api/roles/' . $role->id, [], [], ['CONTENT_TYPE' => 'text/plain'], 'label=updated');
        $this->assertSame(415, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_CONTENT_TYPE', $data['code'] ?? null);

        // 3. POST /api/roles/bulk-delete with text/plain
        $client->request('POST', '/api/roles/bulk-delete', [], [], ['CONTENT_TYPE' => 'text/plain'], 'ids=1');
        $this->assertSame(415, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_CONTENT_TYPE', $data['code'] ?? null);
    }

    public function testContentTypeValidationOnPermissions(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = $this->createSuperAdmin($em, $hasher);

        $client->loginUser($admin);

        // 1. POST /api/permissions with text/plain
        $client->request('POST', '/api/permissions', [], [], ['CONTENT_TYPE' => 'text/plain'], 'name=perm.test');
        $this->assertSame(415, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_CONTENT_TYPE', $data['code'] ?? null);

        // Create a permission to test PUT
        $perm = new Permission();
        $perm->name = 'custom_ct_' . bin2hex(random_bytes(4));
        $perm->label = 'CT Perm';
        $perm->group = 'custom';
        $em->persist($perm);
        $em->flush();

        // 2. PUT /api/permissions/{id} with text/plain
        $client->request('PUT', '/api/permissions/' . $perm->id, [], [], ['CONTENT_TYPE' => 'text/plain'], 'label=updated');
        $this->assertSame(415, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_CONTENT_TYPE', $data['code'] ?? null);

        // 3. POST /api/permissions/bulk-delete with text/plain
        $client->request('POST', '/api/permissions/bulk-delete', [], [], ['CONTENT_TYPE' => 'text/plain'], 'ids=1');
        $this->assertSame(415, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_CONTENT_TYPE', $data['code'] ?? null);
    }

    public function testProtectedRolesCannotBeRenamed(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = $this->createSuperAdmin($em, $hasher);

        $client->loginUser($admin);

        // Ensure ROLE_ADMIN exists
        $adminRole = $em->getRepository(Role::class)->findOneBy(['name' => 'ROLE_ADMIN']);
        if ($adminRole === null) {
            $adminRole = new Role();
            $adminRole->name = 'ROLE_ADMIN';
            $adminRole->label = 'Admin Role';
            $em->persist($adminRole);
            $em->flush();
        }

        // Super-admin attempts to rename protected role ROLE_ADMIN -> 422
        $client->request('PUT', '/api/roles/' . $adminRole->id, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'ROLE_MODIFIED',
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);

        // Non-superadmin cannot update protected role -> 403
        $delegated = $this->createDelegatedUser($em, $hasher, ['roles.manage']);
        $client->loginUser($delegated);

        $client->request('PUT', '/api/roles/' . $adminRole->id, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label' => 'Hijacked Label',
        ]));
        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testNonSuperAdminCannotCreateProtectedRole(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $delegated = $this->createDelegatedUser($em, $hasher, ['roles.manage']);
        $client->loginUser($delegated);

        // Attempt to create a role named ROLE_SUPER_ADMIN or super_admin
        $client->request('POST', '/api/roles', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'super_admin',
            'label' => 'Super Admin Role',
        ]));
        $this->assertContains($client->getResponse()->getStatusCode(), [403, 422]);

        // Attempt to create role named admin
        $client->request('POST', '/api/roles', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'admin',
            'label' => 'Admin Role',
        ]));
        $this->assertContains($client->getResponse()->getStatusCode(), [403, 422]);
    }

    public function testCustomRoleCannotBeRenamedToProtectedRole(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $delegated = $this->createDelegatedUser($em, $hasher, ['roles.manage']);
        $client->loginUser($delegated);

        $customRole = new Role();
        $customRole->name = 'custom_role_' . bin2hex(random_bytes(4));
        $customRole->label = 'Custom Role';
        $em->persist($customRole);
        $em->flush();

        // Attempt to rename to ROLE_SUPER_ADMIN
        $client->request('PUT', '/api/roles/' . $customRole->id, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'ROLE_SUPER_ADMIN',
        ]));
        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testRoleCannotBeDeletedIfAssignedToProjectMember(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = $this->createSuperAdmin($em, $hasher);

        $client->loginUser($admin);

        $role = new Role();
        $role->name = 'project_member_role_' . bin2hex(random_bytes(4));
        $role->label = 'Member Role';
        $em->persist($role);

        $project = new Project();
        $project->name = 'Cycle110 Test Project ' . bin2hex(random_bytes(4));
        $em->persist($project);

        $member = new ProjectMember();
        $member->project = $project;
        $member->email = 'member_' . bin2hex(random_bytes(4)) . '@example.com';
        $member->role = $role;
        $member->status = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $em->persist($member);

        $em->flush();

        // Try deleting the role
        $client->request('DELETE', '/api/roles/' . $role->id);
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);

        // Verify audit log for failed deletion
        $auditLog = $em->getRepository(AuditLog::class)->findOneBy(['toolName' => 'role_delete_failed_assigned_member'], ['id' => 'DESC']);
        $this->assertNotNull($auditLog);
        $this->assertSame('failure', $auditLog->status);

        // Bulk delete should skip it and delete 0
        $client->request('POST', '/api/roles/bulk-delete', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'ids' => [$role->id],
            'password' => 'SuperSecretPass123!',
        ]));
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertNotNull($em->getRepository(Role::class)->find($role->id));
    }

    public function testProtectedPermissionsCannotBeRenamedOrDeleted(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = $this->createSuperAdmin($em, $hasher);

        $client->loginUser($admin);

        // Ensure permissions.manage exists
        $perm = $em->getRepository(Permission::class)->findOneBy(['name' => 'permissions.manage']);
        if ($perm === null) {
            $perm = new Permission();
            $perm->name = 'permissions.manage';
            $perm->label = 'Manage Permissions';
            $perm->group = 'users';
            $em->persist($perm);
            $em->flush();
        }

        // Superadmin attempts to rename protected permission -> 422
        $client->request('PUT', '/api/permissions/' . $perm->id, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'permissions.renamed',
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());

        // Superadmin attempts to delete protected permission -> 422
        $client->request('DELETE', '/api/permissions/' . $perm->id);
        $this->assertSame(422, $client->getResponse()->getStatusCode());

        // Verify audit log failure
        $auditLog = $em->getRepository(AuditLog::class)->findOneBy(['toolName' => 'permission_delete_failed_system_permission'], ['id' => 'DESC']);
        $this->assertNotNull($auditLog);
        $this->assertSame('failure', $auditLog->status);
    }

    public function testNonSuperAdminCannotCreateProtectedPermission(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $delegated = $this->createDelegatedUser($em, $hasher, ['permissions.manage']);
        $client->loginUser($delegated);

        // Attempt to create permission with system prefix users.* or roles.*
        $client->request('POST', '/api/permissions', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'users.backdoor_' . bin2hex(random_bytes(3)),
            'label' => 'Backdoor Perm',
        ]));
        $this->assertSame(403, $client->getResponse()->getStatusCode());

        // Verify audit log
        $auditLog = $em->getRepository(AuditLog::class)->findOneBy(['toolName' => 'permission_create_privilege_escalation_attempt'], ['id' => 'DESC']);
        $this->assertNotNull($auditLog);
        $this->assertSame('failure', $auditLog->status);
    }

    public function testSecurityHeadersAndAuditLoggingOnAllResponses(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = $this->createSuperAdmin($em, $hasher);

        $client->loginUser($admin);

        // GET /api/roles
        $client->request('GET', '/api/roles');
        $resp = $client->getResponse();
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('nosniff', $resp->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $resp->headers->get('X-Frame-Options'));
        $this->assertStringContainsString('no-store', (string) $resp->headers->get('Cache-Control'));

        // GET /api/permissions
        $client->request('GET', '/api/permissions');
        $resp = $client->getResponse();
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('nosniff', $resp->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $resp->headers->get('X-Frame-Options'));
        $this->assertStringContainsString('no-store', (string) $resp->headers->get('Cache-Control'));
    }

    public function testRoleValidationNonExistentPermissionRejected(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = $this->createSuperAdmin($em, $hasher);

        $client->loginUser($admin);

        // Super-admin tries to create role with non-existent permission
        $client->request('POST', '/api/roles', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'invalid_perm_role_' . bin2hex(random_bytes(4)),
            'label' => 'Invalid Perm Role',
            'permissions' => ['non_existent_perm_xyz_999'],
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('permissions', $data['errors']);
    }

    public function testPayloadTooLargeOnRoleAndPermission(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $admin = $this->createSuperAdmin($em, $hasher);

        $client->loginUser($admin);

        $hugeData = str_repeat('A', 70000);

        // POST /api/roles with >64KB payload
        $client->request('POST', '/api/roles', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'huge_role',
            'label' => $hugeData,
        ]));
        $this->assertSame(413, $client->getResponse()->getStatusCode());

        // POST /api/permissions with >64KB payload
        $client->request('POST', '/api/permissions', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'huge.perm',
            'label' => $hugeData,
        ]));
        $this->assertSame(413, $client->getResponse()->getStatusCode());
    }
}
