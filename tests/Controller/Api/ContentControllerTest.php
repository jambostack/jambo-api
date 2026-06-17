<?php

namespace App\Tests\Controller\Api;

use App\Entity\ApiToken;
use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ContentControllerTest extends WebTestCase
{
    private string $projectUuid;
    private string $collectionSlug = 'articles';
    private string $plainToken;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $em        = self::getContainer()->get(EntityManagerInterface::class);
        $appSecret = self::getContainer()->getParameter('kernel.secret');

        // Project with public API enabled
        $project = new Project();
        $project->name      = 'Test Content ' . bin2hex(random_bytes(4));
        $project->publicApi = true;
        $em->persist($project);

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

        // API token with read+create+delete abilities
        $this->plainToken = ApiToken::generatePlainToken();
        $token = new ApiToken();
        $token->name         = 'test-token';
        $token->abilities    = ['read', 'create', 'delete'];
        $token->tokenHash    = ApiToken::hashToken($this->plainToken, $appSecret);
        $token->tokenVersion = 2; // HMAC hash — must match findByPlainToken lookup
        $token->project      = $project;
        $em->persist($token);

        $em->flush();

        $this->projectUuid = $project->uuid->toString();

        self::ensureKernelShutdown();
    }

    // ----------------------------------------------------------------
    // GET list
    // ----------------------------------------------------------------

    public function testListEntriesReturnsEmptyCollection(): void
    {
        $client = static::createClient();
        $client->request('GET', $this->url());

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('meta', $body);
        $this->assertIsArray($body['data']);
        $this->assertSame(0, $body['meta']['total']);
    }

    public function testListEntriesReturnsPaginationMeta(): void
    {
        $client = static::createClient();
        $client->request('GET', $this->url() . '?per_page=5&page=1');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('page', $body['meta']);
        $this->assertArrayHasKey('per_page', $body['meta']);
        $this->assertArrayHasKey('pages', $body['meta']);
        $this->assertArrayHasKey('total', $body['meta']);
        $this->assertSame(1, $body['meta']['page']);
        $this->assertSame(5, $body['meta']['per_page']);
    }

    public function testListEntriesReturnsForbiddenWhenPublicApiDisabled(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $project = new Project();
        $project->name      = 'Private ' . bin2hex(random_bytes(4));
        $project->publicApi = false;
        $em->persist($project);
        $col = new Collection();
        $col->name    = 'Posts';
        $col->slug    = 'posts';
        $col->project = $project;
        $em->persist($col);
        $em->flush();
        $uuid = $project->uuid->toString();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $client->request('GET', "/api/{$uuid}/posts");

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testListEntriesReturns404ForUnknownCollection(): void
    {
        $client = static::createClient();
        $client->request('GET', $this->url('nonexistent-xyz'));

        $this->assertSame(404, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $body);
    }

    // ----------------------------------------------------------------
    // POST create
    // ----------------------------------------------------------------

    public function testCreateEntryRequiresToken(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', $this->url(), ['title' => 'No token']);

        $this->assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testCreateEntryWithValidTokenReturns201(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', $this->url(), [
            'title'     => 'Hello Jambo',
            'body'      => 'First entry body.',
            'published' => true,
            'status'    => 'published',
        ], ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken]);

        $this->assertSame(201, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame($this->collectionSlug, $body['collection']);
        $this->assertSame('Hello Jambo', $body['title']);
        $this->assertSame('published', $body['status']);
        $this->assertTrue($body['published']);
        $this->assertArrayHasKey('uuid', $body);
    }

    public function testCreateEntryWithReadOnlyTokenReturns401(): void
    {
        self::bootKernel();
        $em        = self::getContainer()->get(EntityManagerInterface::class);
        $appSecret = self::getContainer()->getParameter('kernel.secret');
        $project   = $em->getRepository(Project::class)->findOneBy(['uuid' => $this->projectUuid]);

        $plainReadToken = ApiToken::generatePlainToken();
        $readToken = new ApiToken();
        $readToken->name         = 'read-only';
        $readToken->abilities    = ['read'];
        $readToken->tokenHash    = ApiToken::hashToken($plainReadToken, $appSecret);
        $readToken->tokenVersion = 2;
        $readToken->project      = $project;
        $em->persist($readToken);
        $em->flush();
        self::ensureKernelShutdown();

        $client = static::createClient();
        $client->jsonRequest('POST', $this->url(), ['title' => 'Denied'], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $plainReadToken,
        ]);

        $this->assertSame(401, $client->getResponse()->getStatusCode());
    }

    // ----------------------------------------------------------------
    // GET single entry
    // ----------------------------------------------------------------

    public function testGetSingleEntryReturnsEntry(): void
    {
        // Create an entry first
        $client = static::createClient();
        $client->jsonRequest('POST', $this->url(), [
            'title'  => 'Single Entry',
            'status' => 'published',
        ], ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken]);
        $uuid = json_decode($client->getResponse()->getContent(), true)['uuid'];

        // Fetch it
        $client->request('GET', $this->url() . '/' . $uuid);

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame($uuid, $body['uuid']);
        $this->assertSame('Single Entry', $body['title']);
    }

    public function testGetSingleEntryReturns404ForUnknownUuid(): void
    {
        $client = static::createClient();
        $client->request('GET', $this->url() . '/00000000-0000-0000-0000-000000000000');

        $this->assertSame(404, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $body);
    }

    // ----------------------------------------------------------------
    // PATCH update
    // ----------------------------------------------------------------

    public function testUpdateEntryWithValidToken(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', $this->url(), [
            'title'  => 'Original',
            'status' => 'draft',
        ], ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken]);
        $uuid = json_decode($client->getResponse()->getContent(), true)['uuid'];

        $client->jsonRequest('PATCH', $this->url() . '/' . $uuid, [
            'title'  => 'Updated',
            'status' => 'published',
        ], ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken]);

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Updated', $body['title']);
        $this->assertSame('published', $body['status']);
    }

    public function testUpdateEntryRequiresToken(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', $this->url(), ['title' => 'To Update'], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
        ]);
        $uuid = json_decode($client->getResponse()->getContent(), true)['uuid'];

        $client->jsonRequest('PATCH', $this->url() . '/' . $uuid, ['title' => 'Fail']);

        $this->assertSame(401, $client->getResponse()->getStatusCode());
    }

    // ----------------------------------------------------------------
    // DELETE
    // ----------------------------------------------------------------

    public function testDeleteEntryWithValidToken(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', $this->url(), ['title' => 'To Delete'], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
        ]);
        $uuid = json_decode($client->getResponse()->getContent(), true)['uuid'];

        $client->request('DELETE', $this->url() . '/' . $uuid, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
        ]);

        $this->assertSame(204, $client->getResponse()->getStatusCode());

        // Verify it no longer appears in the list (soft-deleted)
        $client->request('GET', $this->url() . '/' . $uuid);
        $this->assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testDeleteEntryRequiresToken(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', $this->url(), ['title' => 'No Delete'], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken,
        ]);
        $uuid = json_decode($client->getResponse()->getContent(), true)['uuid'];

        $client->request('DELETE', $this->url() . '/' . $uuid);

        $this->assertSame(401, $client->getResponse()->getStatusCode());
    }

    // ----------------------------------------------------------------
    // Workflow status validation
    // ----------------------------------------------------------------

    public function testCreateWithInvalidStatusReturns422(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', $this->url(), [
            'title'  => 'Invalid status',
            'status' => 'invalid_status',
        ], ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken]);

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $body);
        $this->assertArrayHasKey('status', $body['errors']);
    }

    public function testUpdateWithInvalidStatusReturns422(): void
    {
        $client = static::createClient();
        $auth   = ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken];

        // Create an entry first
        $client->jsonRequest('POST', $this->url(), [
            'title'  => 'Will update status',
            'status' => 'draft',
        ], $auth);
        $uuid = json_decode($client->getResponse()->getContent(), true)['uuid'];

        // Try updating with invalid status
        $client->jsonRequest('PATCH', $this->url() . '/' . $uuid, [
            'status' => 'nonexistent_status',
        ], $auth);

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $body);
        $this->assertArrayHasKey('status', $body['errors']);
    }

    // ----------------------------------------------------------------
    // Assigned to
    // ----------------------------------------------------------------

    public function testAssignNonMemberReturns422(): void
    {
        $client = static::createClient();

        // Create a user who is NOT a member of the project
        $em       = static::getContainer()->get(EntityManagerInterface::class);
        $hasher   = static::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);
        $nonMember = new \App\Entity\User();
        $nonMember->name     = 'Non Member';
        $nonMember->email    = 'nonmember_' . uniqid() . '@test.com';
        $nonMember->password = $hasher->hashPassword($nonMember, 'password123');
        $em->persist($nonMember);
        $em->flush();
        self::ensureKernelShutdown();

        $client->jsonRequest('POST', $this->url(), [
            'title'          => 'Assign to non-member',
            'status'         => 'draft',
            'assigned_to_id' => $nonMember->id,
        ], ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken]);

        $this->assertSame(422, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $body);
        $this->assertArrayHasKey('assigned_to_id', $body['errors']);
    }

    public function testAssignedToAppearsInResponse(): void
    {
        $client = static::createClient();

        // Create a member user and add them to the project
        $em       = static::getContainer()->get(EntityManagerInterface::class);
        $hasher   = static::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);
        $member   = new \App\Entity\User();
        $member->name     = 'Project Member';
        $member->email    = 'member_' . uniqid() . '@test.com';
        $member->password = $hasher->hashPassword($member, 'password123');
        $em->persist($member);

        $project = $em->getRepository(\App\Entity\Project::class)->findOneBy(['uuid' => $this->projectUuid]);
        $projectMember = new \App\Entity\ProjectMember();
        $projectMember->user    = $member;
        $projectMember->project = $project;
        $projectMember->status  = \App\Enum\ProjectMemberStatus::Active;
        $em->persist($projectMember);
        $em->flush();
        self::ensureKernelShutdown();

        $client->jsonRequest('POST', $this->url(), [
            'title'          => 'Assigned entry',
            'status'         => 'draft',
            'assigned_to_id' => $member->id,
        ], ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken]);

        $this->assertSame(201, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('assigned_to', $body);
        $this->assertNotNull($body['assigned_to']);
        $this->assertSame($member->id, $body['assigned_to']['id']);
        $this->assertSame($member->name, $body['assigned_to']['name']);
    }

    // ----------------------------------------------------------------
    // Status filter
    // ----------------------------------------------------------------

    public function testListReturnsOnlyPublishedByDefault(): void
    {
        $client = static::createClient();
        $auth   = ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->plainToken];

        $client->jsonRequest('POST', $this->url(), ['title' => 'Draft entry', 'status' => 'draft'], $auth);
        $client->jsonRequest('POST', $this->url(), ['title' => 'Published entry', 'status' => 'published'], $auth);

        $client->request('GET', $this->url());
        $body = json_decode($client->getResponse()->getContent(), true);

        $statuses = array_column($body['data'], 'status');
        $this->assertNotContains('draft', $statuses, 'Draft entries must not appear in the default public list');
        $this->assertContains('published', $statuses);
    }

    // ----------------------------------------------------------------
    // Helper
    // ----------------------------------------------------------------

    private function url(?string $collection = null): string
    {
        return '/api/' . $this->projectUuid . '/' . ($collection ?? $this->collectionSlug);
    }
}
