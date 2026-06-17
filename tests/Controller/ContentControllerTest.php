<?php

namespace App\Tests\Controller;

use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ContentControllerTest extends WebTestCase
{
    private string $projectUuid;
    private string $collectionSlug = 'articles';
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

        // Project with public API disabled (admin only)
        $project = new Project();
        $project->name      = 'Test Content ' . bin2hex(random_bytes(4));
        $project->publicApi = false;
        $em->persist($project);

        // Add admin as a member of the project
        $member = new ProjectMember();
        $member->user    = $this->admin;
        $member->project = $project;
        $member->status  = ProjectMemberStatus::Active;
        $em->persist($member);

        // Collection
        $collection = new Collection();
        $collection->name    = 'Articles';
        $collection->slug    = $this->collectionSlug;
        $collection->project = $project;
        $em->persist($collection);

        // Fields
        foreach ([['title', 'text'], ['body', 'longtext'], ['published', 'boolean']] as [$slug, $type]) {
            $field = new Field();
            $field->name       = ucfirst($slug);
            $field->slug       = $slug;
            $field->type       = $type;
            $field->isRequired = ($slug === 'title');
            $field->collection = $collection;
            $em->persist($field);
        }

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

    // ----------------------------------------------------------------
    // GET list
    // ----------------------------------------------------------------

    public function testListEntriesReturnsEmptyCollection(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->request('GET', $this->url());

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('total', $body);
        $this->assertIsArray($body['data']);
        $this->assertSame(0, $body['total']);
    }

    public function testCreateEntryReturns201(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->jsonRequest('POST', $this->url(), [
            'title'  => 'New entry',
            'status' => 'draft',
            'fields' => ['body' => 'Hello world', 'published' => false],
        ]);

        $this->assertSame(201, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertSame('New entry', $body['data']['title']);
        $this->assertSame('draft', $body['data']['status']);
        $this->assertFalse($body['data']['published']);
    }

    public function testCreateWithInvalidStatusReturns422(): void
    {
        $client = $this->createAuthenticatedClient();
        $client->jsonRequest('POST', $this->url(), [
            'title'  => 'Bad status',
            'status' => 'invalid_status',
        ]);

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $body);
        $this->assertArrayHasKey('status', $body['errors']);
    }

    public function testAssignNonMemberReturns422(): void
    {
        self::bootKernel();
        $em     = self::getContainer()->get(EntityManagerInterface::class);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $nonMember = new User();
        $nonMember->name     = 'Non Member';
        $nonMember->email    = 'nonmember_' . uniqid() . '@test.com';
        $nonMember->password = $hasher->hashPassword($nonMember, 'password123');
        $em->persist($nonMember);
        $em->flush();
        self::ensureKernelShutdown();

        $client = $this->createAuthenticatedClient();
        $client->jsonRequest('POST', $this->url(), [
            'title'          => 'Assign non-member',
            'status'         => 'draft',
            'assigned_to_id' => $nonMember->id,
        ]);

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $body);
        $this->assertArrayHasKey('assigned_to_id', $body['errors']);
    }

    public function testAssignedToAppearsInResponse(): void
    {
        self::bootKernel();
        $em     = self::getContainer()->get(EntityManagerInterface::class);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $member   = new User();
        $member->name     = 'Assigned User';
        $member->email    = 'assigned_' . uniqid() . '@test.com';
        $member->password = $hasher->hashPassword($member, 'password123');
        $em->persist($member);

        $project = $em->getRepository(Project::class)->findOneBy(['uuid' => $this->projectUuid]);
        $projectMember = new ProjectMember();
        $projectMember->user    = $member;
        $projectMember->project = $project;
        $projectMember->status  = ProjectMemberStatus::Active;
        $em->persist($projectMember);
        $em->flush();
        self::ensureKernelShutdown();

        $client = $this->createAuthenticatedClient();
        $client->jsonRequest('POST', $this->url(), [
            'title'          => 'Assigned entry',
            'status'         => 'draft',
            'assigned_to_id' => $member->id,
        ]);

        $this->assertSame(201, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $body);

        $entry = $body['data'];
        $this->assertArrayHasKey('assigned_to', $entry);
        $this->assertNotNull($entry['assigned_to']);
        $this->assertSame($member->id, $entry['assigned_to']['id']);
        $this->assertSame($member->name, $entry['assigned_to']['name']);
    }

    // ----------------------------------------------------------------
    // Helper
    // ----------------------------------------------------------------

    private function url(): string
    {
        return '/api/projects/' . $this->projectUuid . '/collections/' . $this->collectionSlug . '/entries';
    }
}
