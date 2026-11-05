<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Project;
use App\Entity\ProjectStorageProfile;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ProjectStorageControllerTest extends WebTestCase
{
    private function createAdmin(KernelBrowser $client): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->name = 'Storage Admin';
        $user->email = 'admin_storage_' . uniqid() . '@test.com';
        $user->password = $hasher->hashPassword($user, 'password123');
        $user->roles = ['ROLE_SUPER_ADMIN'];

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function createProject(EntityManagerInterface $em): Project
    {
        $project = new Project();
        $project->name = 'Storage Test ' . bin2hex(random_bytes(4));
        $em->persist($project);

        $defaultProfile = new ProjectStorageProfile();
        $defaultProfile->project = $project;
        $defaultProfile->name = 'Local Default';
        $defaultProfile->driver = 'local';
        $defaultProfile->isDefault = true;
        $em->persist($defaultProfile);

        $em->flush();

        return $project;
    }

    public function testGetConfigSuccess(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);

        $client->request('GET', '/api/admin/projects/' . $project->uuid . '/storage');
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('strategy', $data);
        $this->assertArrayHasKey('profiles', $data);
        $this->assertArrayHasKey('rules', $data);
        $this->assertCount(1, $data['profiles']);
    }

    public function testUpdateConfigInvalidStrategyReturns400(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);

        $client->request(
            'PUT',
            '/api/admin/projects/' . $project->uuid . '/storage',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['strategy' => 'invalid_strategy'])
        );

        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testUpdateConfigSuccess(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);

        $client->request(
            'PUT',
            '/api/admin/projects/' . $project->uuid . '/storage',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['strategy' => 'mirror_all'])
        );

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('mirror_all', $data['strategy']);
    }

    public function testCreateProfileInvalidDriverReturns422(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);

        $client->request(
            'POST',
            '/api/admin/projects/' . $project->uuid . '/storage/profiles',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'Bad Driver',
                'driver' => 'ftp',
            ])
        );

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateProfileInvalidNameReturns422(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);

        $client->request(
            'POST',
            '/api/admin/projects/' . $project->uuid . '/storage/profiles',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => str_repeat('A', 150),
                'driver' => 'local',
            ])
        );

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateProfileS3EndpointSsrfReturns422(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);

        $client->request(
            'POST',
            '/api/admin/projects/' . $project->uuid . '/storage/profiles',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'SSRF S3',
                'driver' => 's3',
                's3_endpoint' => 'http://169.254.169.254/latest/meta-data',
            ])
        );

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateProfileInvalidBaseUrlReturns422(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);

        $client->request(
            'POST',
            '/api/admin/projects/' . $project->uuid . '/storage/profiles',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'Bad URL',
                'driver' => 'local',
                'base_url' => 'javascript:alert(1)',
            ])
        );

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateProfileInvalidRootPathTraversalReturns422(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);

        $client->request(
            'POST',
            '/api/admin/projects/' . $project->uuid . '/storage/profiles',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'Path Traversal',
                'driver' => 'local',
                'root_path' => '/var/www/uploads/../../etc',
            ])
        );

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateProfileSuccess(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);

        $client->request(
            'POST',
            '/api/admin/projects/' . $project->uuid . '/storage/profiles',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'Local Secondary',
                'driver' => 'local',
                'enabled' => true,
                'priority' => 10,
            ])
        );

        $this->assertSame(201, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Local Secondary', $data['data']['name']);
        $this->assertSame('local', $data['data']['driver']);
    }

    public function testDeleteDefaultProfileReturns422(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);

        // Add a second profile so it is not the only profile
        $second = new ProjectStorageProfile();
        $second->project = $project;
        $second->name = 'Second';
        $second->driver = 'local';
        $second->isDefault = false;
        $em->persist($second);
        $em->flush();

        $defaultProfile = $em->getRepository(ProjectStorageProfile::class)->findOneBy([
            'project' => $project,
            'isDefault' => true,
        ]);

        $client->request('DELETE', '/api/admin/projects/' . $project->uuid . '/storage/profiles/' . $defaultProfile->id);
        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateRuleInvalidMimePatternReturns422(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);

        $client->request(
            'POST',
            '/api/admin/projects/' . $project->uuid . '/storage/rules',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'mime_type_pattern' => str_repeat('image/*', 50),
            ])
        );

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateRuleInvalidExtensionReturns422(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);

        $client->request(
            'POST',
            '/api/admin/projects/' . $project->uuid . '/storage/rules',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'extension' => 'verylongextensionnamethatexceedsbound',
            ])
        );

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateRuleInvalidMaxSizeReturns422(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);

        $client->request(
            'POST',
            '/api/admin/projects/' . $project->uuid . '/storage/rules',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'max_size' => -50,
            ])
        );

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }
}
