<?php

namespace App\Tests\Controller;

use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class RealtimeControllerTest extends WebTestCase
{
    private string $projectUuid;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $em     = self::getContainer()->get(EntityManagerInterface::class);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        // Admin user
        $this->admin = new User();
        $this->admin->name     = 'Test Admin';
        $this->admin->email    = 'admin_' . bin2hex(random_bytes(4)) . '@test.com';
        $this->admin->password = $hasher->hashPassword($this->admin, 'password123');
        $this->admin->roles    = ['ROLE_SUPER_ADMIN'];
        $em->persist($this->admin);

        // Project
        $project = new Project();
        $project->name      = 'Test Realtime ' . bin2hex(random_bytes(4));
        $project->publicApi = false;
        $em->persist($project);

        // Add admin as a member
        $member = new ProjectMember();
        $member->user    = $this->admin;
        $member->project = $project;
        $member->status  = ProjectMemberStatus::Active;
        $em->persist($member);

        $em->flush();

        $this->projectUuid = $project->uuid->toString();

        self::ensureKernelShutdown();
    }

    private function createAuthenticatedClient(): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        $client = static::createClient();
        $client->loginUser($this->admin);

        return $client;
    }

    public function testPollReturnsEventsSince(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/api/projects/' . $this->projectUuid . '/realtime?since=0');

        $this->assertResponseStatusCodeSame(200);
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('events', $body);
        $this->assertArrayHasKey('since', $body);
        $this->assertArrayHasKey('time', $body);
        $this->assertIsArray($body['events']);
        $this->assertIsInt($body['since']);
        $this->assertIsInt($body['time']);
    }

    public function testTokenReturnsJwtToken(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', '/api/projects/' . $this->projectUuid . '/realtime/token');

        $this->assertResponseStatusCodeSame(200);
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('token', $body);
        $this->assertArrayHasKey('hub_url', $body);
        $this->assertArrayHasKey('topics', $body);
        $this->assertIsString($body['token']);
        $this->assertNotEmpty($body['token']);
        $this->assertIsString($body['hub_url']);
        $this->assertIsArray($body['topics']);
    }

    public function testNonExistentProjectReturns404(): void
    {
        $client = $this->createAuthenticatedClient();
        // Use a valid UUID format that does not exist in the database
        $client->request('GET', '/api/projects/00000000-0000-0000-0000-000000000000/realtime?since=0');

        $this->assertResponseStatusCodeSame(404);
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame('Project not found', $body['error']);
    }
}
