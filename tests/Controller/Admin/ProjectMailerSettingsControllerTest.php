<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Project;
use App\Entity\ProjectMailerSettings;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ProjectMailerSettingsControllerTest extends WebTestCase
{
    private function createAdmin(KernelBrowser $client): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->name = 'Mailer Admin';
        $user->email = 'admin_mailer_' . uniqid() . '@test.com';
        $user->password = $hasher->hashPassword($user, 'password123');
        $user->roles = ['ROLE_SUPER_ADMIN'];

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function createProject(EntityManagerInterface $em): Project
    {
        $project = new Project();
        $project->name = 'Mailer Test ' . bin2hex(random_bytes(4));
        $em->persist($project);
        $em->flush();

        return $project;
    }

    public function testShowUnauthenticatedReturnsUnauthorizedOrRedirect(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/admin/projects/00000000-0000-0000-0000-000000000000/mailer');

        $this->assertTrue(in_array($client->getResponse()->getStatusCode(), [302, 401], true));
    }

    public function testShowProjectNotFoundReturns404(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $client->request('GET', '/api/admin/projects/00000000-0000-0000-0000-000000000000/mailer');
        $this->assertSame(404, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testShowEmptySettingsReturnsNullData(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);

        $client->request('GET', '/api/admin/projects/' . $project->uuid . '/mailer');
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertNull($data['data']);
    }

    public function testUpdateInvalidJsonReturns400(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);

        $client->request(
            'PUT',
            '/api/admin/projects/' . $project->uuid . '/mailer',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            'malformed-json'
        );

        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testUpdateInvalidHostWithControlCharsReturns400(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);

        $client->request(
            'PUT',
            '/api/admin/projects/' . $project->uuid . '/mailer',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['host' => "smtp.example.com\r\ninjection"])
        );

        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testUpdateInvalidHostPrivateIpReturns400(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);

        $client->request(
            'PUT',
            '/api/admin/projects/' . $project->uuid . '/mailer',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['host' => '127.0.0.1'])
        );

        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testUpdateInvalidPortReturns400(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);

        $client->request(
            'PUT',
            '/api/admin/projects/' . $project->uuid . '/mailer',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['port' => 8080])
        );

        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testUpdateInvalidEncryptionReturns400(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);

        $client->request(
            'PUT',
            '/api/admin/projects/' . $project->uuid . '/mailer',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['encryption' => 'invalid-encryption'])
        );

        $this->assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testUpdateInvalidFromEmailReturns400(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);

        $client->request(
            'PUT',
            '/api/admin/projects/' . $project->uuid . '/mailer',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['from_email' => 'not-an-email'])
        );

        $this->assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testUpdateSuccess(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);

        $client->request(
            'PUT',
            '/api/admin/projects/' . $project->uuid . '/mailer',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'host' => 'smtp.mailgun.org',
                'port' => 587,
                'username' => 'postmaster@mg.example.com',
                'password' => 'secret123',
                'encryption' => 'tls',
                'from_email' => 'notifications@example.com',
                'from_name' => 'Notifications',
                'enabled' => true,
            ])
        );

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('smtp.mailgun.org', $data['data']['host']);
        $this->assertSame(587, $data['data']['port']);
        $this->assertSame('postmaster@mg.example.com', $data['data']['username']);
        $this->assertSame('tls', $data['data']['encryption']);
        $this->assertSame('notifications@example.com', $data['data']['from_email']);
        $this->assertSame('Notifications', $data['data']['from_name']);
        $this->assertTrue($data['data']['enabled']);
    }

    public function testTestMailerNotConfiguredReturns422(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);

        $client->request('POST', '/api/admin/projects/' . $project->uuid . '/mailer/test');
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }
}
