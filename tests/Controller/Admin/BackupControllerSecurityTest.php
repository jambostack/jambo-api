<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Service\Backup\BackupManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class BackupControllerSecurityTest extends WebTestCase
{
    private function createTestUser(string $role): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->email = 'user_' . bin2hex(random_bytes(6)) . '@example.com';
        $user->name = 'Test ' . $role;
        $user->password = $hasher->hashPassword($user, 'Password123!');
        $user->roles = [$role];
        $em->persist($user);
        $em->flush();

        return $user;
    }

    public function testAnonymousAccessIsRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/admin/backups');

        $this->assertSame(401, $client->getResponse()->getStatusCode());
        $this->assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $client->getResponse()->headers->get('X-Frame-Options'));
    }

    public function testRegularUserAccessIsForbidden(): void
    {
        $client = static::createClient();
        $user = $this->createTestUser('ROLE_USER');
        $client->loginUser($user);

        $client->request('GET', '/api/admin/backups');
        $this->assertSame(403, $client->getResponse()->getStatusCode());

        $client->request('POST', '/api/admin/backups', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label' => 'test-label',
        ]));
        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testSuperAdminCanListAndCreateBackupsWithSecurityHeaders(): void
    {
        $client = static::createClient();
        $admin = $this->createTestUser('ROLE_SUPER_ADMIN');
        $client->loginUser($admin);

        // List
        $client->request('GET', '/api/admin/backups');
        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        // Create
        $client->request('POST', '/api/admin/backups', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label' => 'cycle69-audit',
        ]));
        $createResponse = $client->getResponse();
        $this->assertSame(201, $createResponse->getStatusCode());
        $createdData = json_decode((string) $createResponse->getContent(), true);
        $this->assertArrayHasKey('data', $createdData);
        $this->assertArrayHasKey('filename', $createdData['data']);

        $filename = $createdData['data']['filename'];

        // Download
        $client->request('GET', "/api/admin/backups/{$filename}/download");
        $downloadResponse = $client->getResponse();
        $this->assertSame(200, $downloadResponse->getStatusCode());
        $this->assertSame('nosniff', $downloadResponse->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $downloadResponse->headers->get('X-Frame-Options'));

        // Restore requires explicit confirmation
        $client->request('POST', '/api/admin/backups/restore', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'filename' => $filename,
            'confirm'  => false,
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());

        // Restore confirmed
        $client->request('POST', '/api/admin/backups/restore', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'filename' => $filename,
            'confirm'  => true,
        ]));
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        // Delete
        $client->request('DELETE', "/api/admin/backups/{$filename}");
        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testCreateRejectsControlCharactersAndInvalidCharacters(): void
    {
        $client = static::createClient();
        $admin = $this->createTestUser('ROLE_SUPER_ADMIN');
        $client->loginUser($admin);

        // Control characters
        $client->request('POST', '/api/admin/backups', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label' => "bad\x00label",
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());

        // Invalid characters (e.g. command injection attempt / path traversal in label)
        $client->request('POST', '/api/admin/backups', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label' => 'bad;rm -rf /',
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());

        // Label too long (> 50 chars)
        $client->request('POST', '/api/admin/backups', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'label' => str_repeat('a', 51),
        ]));
        $this->assertSame(422, $client->getResponse()->getStatusCode());

        // Invalid JSON body
        $client->request('POST', '/api/admin/backups', [], [], ['CONTENT_TYPE' => 'application/json'], 'invalid json');
        $this->assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testInvalidFilenameRejectedByRouting(): void
    {
        $client = static::createClient();
        $admin = $this->createTestUser('ROLE_SUPER_ADMIN');
        $client->loginUser($admin);

        // Path traversal attempt in download
        $client->request('GET', '/api/admin/backups/../../etc/passwd/download');
        $this->assertSame(404, $client->getResponse()->getStatusCode());

        // Path traversal attempt in delete
        $client->request('DELETE', '/api/admin/backups/../../etc/passwd');
        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }
}
