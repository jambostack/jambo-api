<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class FormControllerDuplicateFieldTest extends WebTestCase
{
    private function createAdmin(KernelBrowser $client): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->name = 'Form Admin Duplicate';
        $user->email = 'admin_dup_' . bin2hex(random_bytes(4)) . '@test.com';
        $user->password = $hasher->hashPassword($user, 'password123');
        $user->roles = ['ROLE_SUPER_ADMIN'];

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function createProject(EntityManagerInterface $em): Project
    {
        $project = new Project();
        $project->name = 'Form Project Dup ' . bin2hex(random_bytes(4));
        $em->persist($project);
        $em->flush();

        return $project;
    }

    public function testDuplicateFieldNameRejected(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);
        $projectUuid = $project->uuid->toString();

        $payload = [
            'name' => 'Form With Duplicates',
            'fields' => [
                ['name' => 'email', 'label' => 'Email', 'type' => 'email'],
                ['name' => 'email', 'label' => 'Confirm Email', 'type' => 'email'],
            ],
        ];

        $client->request('POST', "/admin-api/{$projectUuid}/forms", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $this->assertSame(422, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('DUPLICATE_FIELD_NAME', $data['code']);
        $this->assertStringContainsString('Duplicate field name', $data['error']);
    }

    public function testInvalidNotificationEmailRejected(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);
        $projectUuid = $project->uuid->toString();

        $payload = [
            'name' => 'Form With Bad Email',
            'fields' => [
                ['name' => 'name', 'label' => 'Name', 'type' => 'text'],
            ],
            'settings' => [
                'notifications' => [
                    'email' => [
                        'to' => "admin@example.com\r\nBcc: evil@attacker.com",
                    ],
                ],
            ],
        ];

        $client->request('POST', "/admin-api/{$projectUuid}/forms", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
        $this->assertSame(422, $client->getResponse()->getStatusCode());

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_NOTIFICATION_EMAIL', $data['code']);
    }
}
