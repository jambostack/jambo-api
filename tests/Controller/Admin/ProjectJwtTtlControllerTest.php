<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Project;
use App\Entity\User;
use App\Service\EndUserJwtService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ProjectJwtTtlControllerTest extends WebTestCase
{
    private function createAdmin(KernelBrowser $client): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->name = 'Test Admin';
        $user->email = 'admin_jwt_ttl_' . uniqid() . '@test.com';
        $user->password = $hasher->hashPassword($user, 'password123');
        $user->roles = ['ROLE_SUPER_ADMIN'];

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function createProject(EntityManagerInterface $em): Project
    {
        $project = new Project();
        $project->name = 'JWT TTL Test ' . bin2hex(random_bytes(4));
        $em->persist($project);
        $em->flush();

        return $project;
    }

    public function testGetJwtTtlUnauthenticatedReturnsUnauthorizedOrRedirect(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/admin/projects/00000000-0000-0000-0000-000000000000/jwt-ttl');

        $this->assertTrue(in_array($client->getResponse()->getStatusCode(), [302, 401], true));
    }

    public function testGetJwtTtlProjectNotFoundReturns404(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $client->request('GET', '/api/admin/projects/00000000-0000-0000-0000-000000000000/jwt-ttl');
        $this->assertSame(404, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testGetJwtTtlSuccess(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);

        $client->request('GET', '/api/admin/projects/' . $project->uuid . '/jwt-ttl');
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('jwt_access_ttl', $data);
        $this->assertArrayHasKey('jwt_refresh_ttl', $data);
        $this->assertArrayHasKey('defaults', $data);
        $this->assertSame(EndUserJwtService::DEFAULT_ACCESS_TTL, $data['defaults']['access_ttl']);
        $this->assertSame(EndUserJwtService::DEFAULT_REFRESH_TTL, $data['defaults']['refresh_ttl']);
        $this->assertSame(EndUserJwtService::MAX_TTL, $data['defaults']['max_ttl']);
    }

    public function testUpdateJwtTtlInvalidJsonReturns400(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);

        $client->request(
            'PATCH',
            '/api/admin/projects/' . $project->uuid . '/jwt-ttl',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            'invalid-json'
        );

        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testUpdateJwtTtlMinBoundViolation(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);

        $client->request(
            'PATCH',
            '/api/admin/projects/' . $project->uuid . '/jwt-ttl',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['jwt_access_ttl' => 30])
        );

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testUpdateJwtTtlMaxBoundViolation(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);

        $client->request(
            'PATCH',
            '/api/admin/projects/' . $project->uuid . '/jwt-ttl',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['jwt_access_ttl' => EndUserJwtService::MAX_TTL + 1000])
        );

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testUpdateJwtTtlRefreshLessThanAccessViolation(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);

        $client->request(
            'PATCH',
            '/api/admin/projects/' . $project->uuid . '/jwt-ttl',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'jwt_access_ttl' => 7200,
                'jwt_refresh_ttl' => 3600,
            ])
        );

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testUpdateJwtTtlInvalidFormat(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);

        $client->request(
            'PATCH',
            '/api/admin/projects/' . $project->uuid . '/jwt-ttl',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['jwt_access_ttl' => 'not-a-number'])
        );

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testUpdateJwtTtlSuccess(): void
    {
        $client = static::createClient();
        $admin = $this->createAdmin($client);
        $client->loginUser($admin);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = $this->createProject($em);

        $client->request(
            'PATCH',
            '/api/admin/projects/' . $project->uuid . '/jwt-ttl',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'jwt_access_ttl' => 1800,
                'jwt_refresh_ttl' => 86400,
            ])
        );

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame(1800, $data['jwt_access_ttl']);
        $this->assertSame(86400, $data['jwt_refresh_ttl']);
    }
}
