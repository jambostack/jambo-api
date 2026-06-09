<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Project;
use App\Entity\ProjectMailerSettings;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

class ProjectEmailControllerTest extends WebTestCase
{
    private function createSuperAdmin(KernelBrowser $client): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->name = 'Test Super Admin';
        $user->email = 'superadmin_email_test_' . uniqid() . '@test.com';
        $user->password = $hasher->hashPassword($user, 'password123');
        $user->roles = ['ROLE_SUPER_ADMIN'];

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function createProjectWithMailer(EntityManagerInterface $em): Project
    {
        $project = new Project();
        $project->name = 'Email Test ' . bin2hex(random_bytes(4));
        $project->publicApi = true;
        $em->persist($project);
        $em->flush();

        $settings = new ProjectMailerSettings();
        $settings->project = $project;
        $settings->host = 'smtp.example.com';
        $settings->port = 587;
        $settings->username = 'test@example.com';
        $settings->encryptedPassword = sodium_bin2base64(
            random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 32),
            SODIUM_BASE64_VARIANT_ORIGINAL
        );
        $settings->encryption = 'tls';
        $settings->fromEmail = 'noreply@test.com';
        $settings->fromName = 'Test';
        $settings->enabled = true;
        $em->persist($settings);
        $em->flush();

        return $project;
    }

    public function testSendAsAdminReturns403WithoutAuth(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProjectWithMailer($em);

        $client->jsonRequest('POST', '/api/admin/projects/' . $project->uuid->toString() . '/email', [
            'to'      => 'client@example.com',
            'subject' => 'Test',
            'body'    => 'Hello',
        ]);

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testSendAsAdminReturns404ForUnknownProject(): void
    {
        $client = static::createClient();
        $user = $this->createSuperAdmin($client);
        $client->loginUser($user);

        $client->jsonRequest('POST', '/api/admin/projects/' . Uuid::v4()->toString() . '/email', [
            'to'      => 'client@example.com',
            'subject' => 'Test',
            'body'    => 'Hello',
        ]);

        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testSendAsAdminReturns422WhenFieldsMissing(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createSuperAdmin($client);
        $project = $this->createProjectWithMailer($em);
        $client->loginUser($user);

        $client->jsonRequest('POST', '/api/admin/projects/' . $project->uuid->toString() . '/email', [
            'subject' => 'Test',
        ]);

        $this->assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testSendAsAdminReturns422ForInvalidEmail(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createSuperAdmin($client);
        $project = $this->createProjectWithMailer($em);
        $client->loginUser($user);

        $client->jsonRequest('POST', '/api/admin/projects/' . $project->uuid->toString() . '/email', [
            'to'      => 'not-an-email',
            'subject' => 'Test',
            'body'    => 'Hello',
        ]);

        $this->assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testSendAsAdminReturns422ForInvalidReplyTo(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createSuperAdmin($client);
        $project = $this->createProjectWithMailer($em);
        $client->loginUser($user);

        $client->jsonRequest('POST', '/api/admin/projects/' . $project->uuid->toString() . '/email', [
            'to'      => 'client@example.com',
            'subject' => 'Test',
            'body'    => 'Hello',
            'replyTo' => 'invalid',
        ]);

        $this->assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testSendAsAdminReturns200WithValidRequest(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->createSuperAdmin($client);
        $project = $this->createProjectWithMailer($em);
        $client->loginUser($user);

        $client->jsonRequest('POST', '/api/admin/projects/' . $project->uuid->toString() . '/email', [
            'to'      => 'client@example.com',
            'subject' => 'Test Subject',
            'body'    => 'Hello from test',
            'cc'      => ['cc@example.com'],
        ]);

        $response = $client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('sent', $data);
        $this->assertTrue($data['sent']);
    }
}
