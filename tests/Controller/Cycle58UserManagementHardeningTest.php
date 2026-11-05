<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Permission;
use App\Entity\Project;
use App\Entity\Role;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class Cycle58UserManagementHardeningTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $superAdmin;
    private string $password = 'SecurePass123!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get(EntityManagerInterface::class);
        $hasher = $this->client->getContainer()->get(UserPasswordHasherInterface::class);

        $suffix = bin2hex(random_bytes(4));

        $this->superAdmin = new User();
        $this->superAdmin->name = 'Cycle58 SuperAdmin';
        $this->superAdmin->email = "cycle58_admin_{$suffix}@test.com";
        $this->superAdmin->password = $hasher->hashPassword($this->superAdmin, $this->password);
        $this->superAdmin->roles = ['ROLE_SUPER_ADMIN'];
        $this->em->persist($this->superAdmin);
        $this->em->flush();
    }

    public function testUserCreatePayloadTooLarge(): void
    {
        $this->client->loginUser($this->superAdmin);
        $bigPayload = str_repeat('a', 65540);

        $this->client->request(
            'POST',
            '/api/users',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Big User', 'email' => 'big@example.com', 'password' => 'Pass123!45', 'extra' => $bigPayload])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('64 KB', $data['error']);
    }

    public function testUserUpdatePayloadTooLarge(): void
    {
        $this->client->loginUser($this->superAdmin);
        $bigPayload = str_repeat('b', 65540);

        $this->client->request(
            'PUT',
            '/api/users/' . $this->superAdmin->id,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Updated Name', 'extra' => $bigPayload])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
    }

    public function testUserBulkDeletePayloadTooLarge(): void
    {
        $this->client->loginUser($this->superAdmin);
        $bigPayload = str_repeat('c', 65540);

        $this->client->request(
            'POST',
            '/api/users/bulk-delete',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [1], 'password' => $this->password, 'extra' => $bigPayload])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
    }

    public function testUserBulkDeleteExceedsMaxLimit(): void
    {
        $this->client->loginUser($this->superAdmin);
        $ids = range(1, 105);

        $this->client->request(
            'POST',
            '/api/users/bulk-delete',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => $ids, 'password' => $this->password])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('100', $data['error']);
    }

    public function testUserBulkDeleteRejectsInvalidIds(): void
    {
        $this->client->loginUser($this->superAdmin);

        $this->client->request(
            'POST',
            '/api/users/bulk-delete',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => ['not-a-number', -5], 'password' => $this->password])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('ids', $data['errors']);
    }

    public function testRoleCreatePayloadTooLarge(): void
    {
        $this->client->loginUser($this->superAdmin);
        $bigPayload = str_repeat('d', 65540);

        $this->client->request(
            'POST',
            '/api/roles',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'big_role', 'extra' => $bigPayload])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
    }

    public function testRoleBulkDeleteExceedsMaxLimit(): void
    {
        $this->client->loginUser($this->superAdmin);
        $ids = range(1, 105);

        $this->client->request(
            'POST',
            '/api/roles/bulk-delete',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => $ids, 'password' => $this->password])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('100', $data['error']);
    }

    public function testRoleBulkDeleteRejectsInvalidIds(): void
    {
        $this->client->loginUser($this->superAdmin);

        $this->client->request(
            'POST',
            '/api/roles/bulk-delete',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => ['abc', 0], 'password' => $this->password])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('ids', $data['errors']);
    }

    public function testPermissionCreatePayloadTooLarge(): void
    {
        $this->client->loginUser($this->superAdmin);
        $bigPayload = str_repeat('e', 65540);

        $this->client->request(
            'POST',
            '/api/permissions',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'big.permission', 'extra' => $bigPayload])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
    }

    public function testPermissionBulkDeleteExceedsMaxLimit(): void
    {
        $this->client->loginUser($this->superAdmin);
        $ids = range(1, 105);

        $this->client->request(
            'POST',
            '/api/permissions/bulk-delete',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => $ids, 'password' => $this->password])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('100', $data['error']);
    }

    public function testPermissionBulkDeleteRejectsInvalidIds(): void
    {
        $this->client->loginUser($this->superAdmin);

        $this->client->request(
            'POST',
            '/api/permissions/bulk-delete',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => ['xyz'], 'password' => $this->password])
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('ids', $data['errors']);
    }

    public function testCycle58TranslationsParity(): void
    {
        $keys = [
            'user.admin.payload_too_large',
            'user.admin.bulk_limit_exceeded',
            'user.admin.invalid_id',
            'role.admin.payload_too_large',
            'role.admin.bulk_limit_exceeded',
            'role.admin.invalid_id',
            'permission.admin.payload_too_large',
            'permission.admin.bulk_limit_exceeded',
            'permission.admin.invalid_id',
        ];

        $locales = ['en', 'fr', 'es', 'ar'];
        foreach ($locales as $locale) {
            $dict = require sprintf('%s/translations/messages.%s.php', dirname(__DIR__, 2), $locale);
            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $dict, "Missing {$key} in locale {$locale}");
                $this->assertNotEmpty($dict[$key], "Empty {$key} in locale {$locale}");
            }
        }
    }
}
