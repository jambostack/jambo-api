<?php

namespace App\Tests\Controller;

use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class McpHardeningAndSecurityTest extends WebTestCase
{
    public function testMcpInfoEndpointReturnsSuccessAndSecurityHeaders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/mcp');

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->headers->has('X-Content-Type-Options'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('JamboApi MCP Server', $data['name']);
        self::assertSame('2.0.0', $data['version']);
    }

    public function testMcpEmptyBodyReturns400(): void
    {
        $client = static::createClient();
        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: '');

        $response = $client->getResponse();
        self::assertSame(400, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame(-32600, $data['error']['code']);
        self::assertStringContainsString('body cannot be empty', $data['error']['message']);
    }

    public function testMcpParseErrorReturnsJsonRpcError(): void
    {
        $client = static::createClient();
        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: '{malformed_json');

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame(-32700, $data['error']['code']);
        self::assertStringContainsString('Parse error', $data['error']['message']);
    }

    public function testMcpRootMustBeObject(): void
    {
        $client = static::createClient();
        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([1, 2, 3]));

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame(-32600, $data['error']['code']);
        self::assertStringContainsString('root must be a JSON object', $data['error']['message']);
    }

    public function testMcpUnknownMethodReturnsMethodNotFound(): void
    {
        $client = static::createClient();
        $rpc = [
            'jsonrpc' => '2.0',
            'id' => 'req-1',
            'method' => 'non_existent_method',
            'params' => [],
        ];
        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($rpc));

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame(-32601, $data['error']['code']);
        self::assertStringContainsString('non_existent_method', $data['error']['message']);
    }

    public function testMcpUnknownToolCallReturnsToolNotFound(): void
    {
        $client = static::createClient();
        $rpc = [
            'jsonrpc' => '2.0',
            'id' => 42,
            'method' => 'tools/call',
            'params' => [
                'name' => 'fake_unknown_tool',
                'arguments' => [],
            ],
        ];
        $client->request('POST', '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($rpc));

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame(-32602, $data['error']['code']);
        self::assertStringContainsString('fake_unknown_tool', $data['error']['message']);
    }

    public function testMcpProjectEndpointInvalidUuidReturns404(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->email = 'mcp-inv-' . bin2hex(random_bytes(4)) . '@test.com';
        $user->password = 'hashed';
        $user->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($user);
        $em->flush();

        $client->loginUser($user);

        $client->request('POST', '/api/projects/not-a-valid-uuid/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

        $response = $client->getResponse();
        self::assertSame(404, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('Project not found.', $data['error']);
    }

    public function testMcpProjectEndpointUnauthenticatedReturns401(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $project = new Project();
        $project->name = 'Mcp Sec Project ' . bin2hex(random_bytes(3));
        $em->persist($project);
        $em->flush();

        $client->request('POST', '/api/projects/' . $project->uuid->toRfc4122() . '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'ping',
        ]));

        $response = $client->getResponse();
        self::assertSame(401, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('Authentication required.', $data['error']);
    }

    public function testMcpProjectEndpointNonMemberReturns403(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->email = 'mcp-nonmember-' . bin2hex(random_bytes(4)) . '@test.com';
        $user->password = 'hashed';
        $em->persist($user);

        $project = new Project();
        $project->name = 'Mcp Isolated ' . bin2hex(random_bytes(3));
        $em->persist($project);
        $em->flush();

        $client->loginUser($user);

        $client->request('POST', '/api/projects/' . $project->uuid->toRfc4122() . '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'ping',
        ]));

        $response = $client->getResponse();
        self::assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('Access denied to this project.', $data['error']);
    }

    public function testMcpProjectEndpointActiveMemberAllowed(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->email = 'mcp-member-' . bin2hex(random_bytes(4)) . '@test.com';
        $user->password = 'hashed';
        $em->persist($user);

        $project = new Project();
        $project->name = 'Mcp Member Proj ' . bin2hex(random_bytes(3));
        $em->persist($project);

        $member = new ProjectMember();
        $member->project = $project;
        $member->user = $user;
        $member->email = $user->email;
        $member->status = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $em->persist($member);
        $em->flush();

        $client->loginUser($user);

        $client->request('POST', '/api/projects/' . $project->uuid->toRfc4122() . '/mcp', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'jsonrpc' => '2.0',
            'id' => 99,
            'method' => 'ping',
        ]));

        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame(['pong' => true], $data['result']);
    }
}
