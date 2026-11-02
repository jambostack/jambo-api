<?php

namespace App\Tests\Controller;

use App\Entity\Collection;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Enum\ProjectMemberStatus;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Régression de la faille : un utilisateur membre du projet A ne doit pas
 * pouvoir créer une collection dans le projet B via le tool MCP create_collection
 * (le handler ne vérifiait pas l'appartenance au projet ciblé).
 */
class McpSchemaSecurityTest extends WebTestCase
{
    public function testCrossProjectCreateCollectionDenied(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->email = 'mcp-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'x';
        $em->persist($user);

        $projectA = new Project();
        $projectA->name = 'A ' . bin2hex(random_bytes(4));
        $em->persist($projectA);

        $projectB = new Project();
        $projectB->name = 'B ' . bin2hex(random_bytes(4));
        $em->persist($projectB);

        // L'utilisateur est membre de A uniquement.
        $member = new ProjectMember();
        $member->project = $projectA;
        $member->user = $user;
        $member->email = $user->email;
        $member->status = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $em->persist($member);
        $em->flush();
        $projectBId = $projectB->id;

        $client->loginUser($user);

        $rpc = [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'create_collection', 'arguments' => [
                'project_uuid' => $projectB->uuid->toString(), 'name' => 'Hacked',
            ]],
        ];
        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($rpc));

        // La collection NE DOIT PAS exister dans le projet B.
        $em->clear();
        $exists = $em->getRepository(Collection::class)->findOneBy(['project' => $projectBId, 'slug' => 'hacked']);
        self::assertNull($exists, 'cross-project write must be denied');
    }

    public function testMemberCanCreateInOwnProject(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->email = 'mcp2-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'x';
        $em->persist($user);

        $project = new Project();
        $project->name = 'Own ' . bin2hex(random_bytes(4));
        $em->persist($project);

        $member = new ProjectMember();
        $member->project = $project;
        $member->user = $user;
        $member->email = $user->email;
        $member->status = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $em->persist($member);
        $em->flush();
        $projectId = $project->id;

        $client->loginUser($user);

        $rpc = [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'create_collection', 'arguments' => [
                'project_uuid' => $project->uuid->toString(), 'name' => 'Articles',
            ]],
        ];
        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($rpc));

        $em->clear();
        $exists = $em->getRepository(Collection::class)->findOneBy(['project' => $projectId, 'slug' => 'articles']);
        self::assertNotNull($exists, 'member must be able to create in own project');
    }

    public function testCreateProjectViaMcp(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->email = 'mcp3-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'x';
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        $name = 'MCPProj' . bin2hex(random_bytes(4));
        $rpc = [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'create_project', 'arguments' => ['name' => $name]],
        ];
        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($rpc));

        $em->clear();
        $project = $em->getRepository(Project::class)->findOneBy(['name' => $name]);
        self::assertNotNull($project, 'create_project must create the project');
        $member = $em->getRepository(\App\Entity\ProjectMember::class)->findOneBy(['project' => $project->id, 'email' => $user->email]);
        self::assertNotNull($member, 'creator must be a member');
    }

    public function testAnonymousHandleProjectEndpointIsDenied(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $project = new Project();
        $project->name = 'MCP Test ' . bin2hex(random_bytes(4));
        $em->persist($project);
        $em->flush();

        $client->request('POST', '/api/projects/' . $project->uuid->toString() . '/mcp');
        self::assertContains($client->getResponse()->getStatusCode(), [401, 302]);
    }

    public function testNonMemberHandleProjectEndpointIsDenied(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->email = 'mcp4-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'x';
        $em->persist($user);

        $project = new Project();
        $project->name = 'MCP Other ' . bin2hex(random_bytes(4));
        $em->persist($project);
        $em->flush();

        $client->loginUser($user);
        $client->request('POST', '/api/projects/' . $project->uuid->toString() . '/mcp');
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testCrossProjectListEndUsersDenied(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->email = 'mcp5-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'x';
        $em->persist($user);

        $projectA = new Project();
        $projectA->name = 'Proj A ' . bin2hex(random_bytes(4));
        $em->persist($projectA);

        $projectB = new Project();
        $projectB->name = 'Proj B ' . bin2hex(random_bytes(4));
        $em->persist($projectB);

        $member = new ProjectMember();
        $member->project = $projectA;
        $member->user = $user;
        $member->email = $user->email;
        $member->status = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $em->persist($member);
        $em->flush();

        $client->loginUser($user);

        $rpc = [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'list_end_users', 'arguments' => [
                'project_uuid' => $projectB->uuid->toString(),
            ]],
        ];
        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($rpc));

        $res = json_decode($client->getResponse()->getContent(), true);
        self::assertTrue(isset($res['error']) || (isset($res['result']['isError']) && $res['result']['isError'] === true));
    }

    public function testMcpEndpointPublicAccessWithoutRedirect(): void
    {
        $client = static::createClient();
        $rpc = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'];
        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($rpc));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $res = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(['pong' => true], $res['result'] ?? null);
    }

    public function testAnonymousCreateEntryDenied(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $project = new Project();
        $project->name = 'Sec ' . bin2hex(random_bytes(4));
        $em->persist($project);

        $collection = new Collection();
        $collection->name = 'Posts';
        $collection->slug = 'posts';
        $collection->project = $project;
        $em->persist($collection);
        $em->flush();

        $rpc = [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'create_entry', 'arguments' => [
                'project_uuid' => $project->uuid->toString(),
                'collection_slug' => 'posts',
                'data' => ['title' => 'Hacked'],
            ]],
        ];
        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($rpc));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $res = json_decode($client->getResponse()->getContent(), true);
        self::assertTrue(isset($res['error']) || (isset($res['result']['isError']) && $res['result']['isError'] === true));
    }

    public function testCrossProjectCreateEntryDenied(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->email = 'mcp6-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'x';
        $em->persist($user);

        $projectA = new Project();
        $projectA->name = 'Proj A ' . bin2hex(random_bytes(4));
        $em->persist($projectA);

        $projectB = new Project();
        $projectB->name = 'Proj B ' . bin2hex(random_bytes(4));
        $em->persist($projectB);

        $collectionB = new Collection();
        $collectionB->name = 'Posts B';
        $collectionB->slug = 'posts_b';
        $collectionB->project = $projectB;
        $em->persist($collectionB);

        $member = new ProjectMember();
        $member->project = $projectA;
        $member->user = $user;
        $member->email = $user->email;
        $member->status = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $em->persist($member);
        $em->flush();

        $client->loginUser($user);

        $rpc = [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'create_entry', 'arguments' => [
                'project_uuid' => $projectB->uuid->toString(),
                'collection_slug' => 'posts_b',
                'data' => ['title' => 'Injected'],
            ]],
        ];
        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($rpc));

        $res = json_decode($client->getResponse()->getContent(), true);
        self::assertTrue(isset($res['error']) || (isset($res['result']['isError']) && $res['result']['isError'] === true));
    }

    public function testResourceReadServerInfo(): void
    {
        $client = static::createClient();
        $rpc = [
            'jsonrpc' => '2.0',
            'id' => 99,
            'method' => 'resources/read',
            'params' => ['uri' => 'jamboapi://server/info'],
        ];
        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($rpc));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $res = json_decode($client->getResponse()->getContent(), true);
        self::assertNotNull($res['result'] ?? null);
        self::assertArrayHasKey('contents', $res['result']);
        $text = json_decode($res['result']['contents'][0]['text'], true);
        self::assertSame('JamboApi CMS', $text['name'] ?? null);
        self::assertGreaterThan(0, $text['tools'] ?? 0);
    }

    public function testMemberCanCreateAndReadEntryInOwnProject(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->email = 'mcp7-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'x';
        $em->persist($user);

        $project = new Project();
        $project->name = 'MCP Entry Test ' . bin2hex(random_bytes(4));
        $em->persist($project);

        $member = new ProjectMember();
        $member->project = $project;
        $member->user = $user;
        $member->email = $user->email;
        $member->status = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $em->persist($member);

        $collection = new Collection();
        $collection->name = 'Notes';
        $collection->slug = 'notes';
        $collection->project = $project;
        $em->persist($collection);

        $field = new \App\Entity\Field();
        $field->name = 'Title';
        $field->slug = 'title';
        $field->type = 'text';
        $field->collection = $collection;
        $collection->fields->add($field);
        $em->persist($field);

        $em->flush();
        $em->clear();

        $client->loginUser($user);

        $rpc = [
            'jsonrpc' => '2.0', 'id' => 10, 'method' => 'tools/call',
            'params' => ['name' => 'create_entry', 'arguments' => [
                'project_uuid' => $project->uuid->toString(),
                'collection_slug' => 'notes',
                'data' => ['title' => 'My Note via MCP'],
            ]],
        ];
        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($rpc));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $res = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('result', $res);
        self::assertArrayHasKey('content', $res['result']);
        $entryData = json_decode($res['result']['content'][0]['text'], true);
        self::assertSame('My Note via MCP', $entryData['title'] ?? null);
        self::assertSame('draft', $entryData['status'] ?? null);
        self::assertNotNull($entryData['uuid'] ?? null);

        // Verify update_entry
        $updateRpc = [
            'jsonrpc' => '2.0', 'id' => 11, 'method' => 'tools/call',
            'params' => ['name' => 'update_entry', 'arguments' => [
                'project_uuid' => $project->uuid->toString(),
                'collection_slug' => 'notes',
                'entry_uuid' => $entryData['uuid'],
                'data' => ['title' => 'Updated Note Title'],
            ]],
        ];
        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($updateRpc));
        $updateRes = json_decode($client->getResponse()->getContent(), true);
        $updatedData = json_decode($updateRes['result']['content'][0]['text'], true);
        self::assertSame('Updated Note Title', $updatedData['title'] ?? null);

        // Verify publish_entry
        $publishRpc = [
            'jsonrpc' => '2.0', 'id' => 12, 'method' => 'tools/call',
            'params' => ['name' => 'publish_entry', 'arguments' => [
                'project_uuid' => $project->uuid->toString(),
                'collection_slug' => 'notes',
                'entry_uuid' => $entryData['uuid'],
            ]],
        ];
        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($publishRpc));
        $publishRes = json_decode($client->getResponse()->getContent(), true);
        $publishedData = json_decode($publishRes['result']['content'][0]['text'], true);
        self::assertTrue($publishedData['published'] ?? false);

        // Verify delete_entry
        $deleteRpc = [
            'jsonrpc' => '2.0', 'id' => 13, 'method' => 'tools/call',
            'params' => ['name' => 'delete_entry', 'arguments' => [
                'project_uuid' => $project->uuid->toString(),
                'collection_slug' => 'notes',
                'entry_uuid' => $entryData['uuid'],
            ]],
        ];
        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($deleteRpc));
        $deleteRes = json_decode($client->getResponse()->getContent(), true);
        $deletedData = json_decode($deleteRes['result']['content'][0]['text'], true);
        self::assertTrue($deletedData['deleted'] ?? false);
    }

    public function testEntrySlugCollisionAndPaginationSafety(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->email = 'slug-test-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'x';
        $em->persist($user);

        $project = new Project();
        $project->name = 'Slug Project ' . bin2hex(random_bytes(4));
        $em->persist($project);

        $member = new ProjectMember();
        $member->project = $project;
        $member->user = $user;
        $member->email = $user->email;
        $member->status = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $em->persist($member);

        $collection = new Collection();
        $collection->name = 'Articles';
        $collection->slug = 'articles';
        $collection->project = $project;
        $em->persist($collection);

        $field = new \App\Entity\Field();
        $field->name = 'Title';
        $field->slug = 'title';
        $field->type = 'text';
        $field->collection = $collection;
        $em->persist($field);

        $em->flush();
        $em->clear();

        $client->loginUser($user);

        // 1. Create first entry with same title
        $rpc1 = [
            'jsonrpc' => '2.0', 'id' => 101, 'method' => 'tools/call',
            'params' => ['name' => 'create_entry', 'arguments' => [
                'project_uuid' => $project->uuid->toString(),
                'collection_slug' => 'articles',
                'data' => ['title' => 'Hello World'],
            ]],
        ];
        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($rpc1));
        $res1 = json_decode($client->getResponse()->getContent(), true);
        $entry1 = json_decode($res1['result']['content'][0]['text'], true);
        self::assertNotNull($entry1['uuid']);

        // 2. Create second entry with IDENTICAL title - must NOT fail with duplicate entry constraint
        $rpc2 = [
            'jsonrpc' => '2.0', 'id' => 102, 'method' => 'tools/call',
            'params' => ['name' => 'create_entry', 'arguments' => [
                'project_uuid' => $project->uuid->toString(),
                'collection_slug' => 'articles',
                'data' => ['title' => 'Hello World'],
            ]],
        ];
        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($rpc2));
        $res2 = json_decode($client->getResponse()->getContent(), true);
        self::assertFalse($res2['result']['isError'] ?? false);
        $entry2 = json_decode($res2['result']['content'][0]['text'], true);
        self::assertNotNull($entry2['uuid']);
        self::assertNotSame($entry1['uuid'], $entry2['uuid']);

        // 3. Test list_entries with limit = 0 and negative offset
        $rpc3 = [
            'jsonrpc' => '2.0', 'id' => 103, 'method' => 'tools/call',
            'params' => ['name' => 'list_entries', 'arguments' => [
                'project_uuid' => $project->uuid->toString(),
                'collection_slug' => 'articles',
                'limit' => 0,
                'offset' => -5,
            ]],
        ];
        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($rpc3));
        $res3 = json_decode($client->getResponse()->getContent(), true);
        self::assertFalse($res3['result']['isError'] ?? false);
        $listData = json_decode($res3['result']['content'][0]['text'], true);
        self::assertSame(2, $listData['total']);

        // 4. Test resource read for jamboapi://server/info
        $rpc4 = [
            'jsonrpc' => '2.0', 'id' => 104, 'method' => 'resources/read',
            'params' => ['uri' => 'jamboapi://server/info'],
        ];
        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($rpc4));
        $res4 = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('result', $res4);
        $infoText = $res4['result']['contents'][0]['text'] ?? '';
        $info = json_decode($infoText, true);
        self::assertSame('JamboApi CMS', $info['name'] ?? null);
        self::assertGreaterThan(0, $info['tools'] ?? 0);
    }
}
