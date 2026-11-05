<?php

namespace App\Tests\GraphQL;

use App\Entity\ApiToken;
use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class GraphQLSecurityTest extends WebTestCase
{
    private string $projectUuid;
    private string $tokenSecret;
    private string $readOnlyTokenSecret;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);

        $project = new Project();
        $project->name = 'GQL Security Test ' . bin2hex(random_bytes(4));
        $em->persist($project);

        $collection = new Collection();
        $collection->project = $project;
        $collection->name = 'Posts';
        $collection->slug = 'posts';
        $collection->order = 0;
        $em->persist($collection);

        $field = new Field();
        $field->collection = $collection;
        $field->name = 'Title';
        $field->slug = 'title';
        $field->type = 'text';
        $field->order = 0;
        $em->persist($field);

        $token = new ApiToken();
        $token->project = $project;
        $token->name = 'GqlToken';
        $this->tokenSecret = bin2hex(random_bytes(24));
        $token->tokenHash = hash('sha256', $this->tokenSecret);
        $token->abilities = ['read', 'write'];
        $em->persist($token);

        $readToken = new ApiToken();
        $readToken->project = $project;
        $readToken->name = 'ReadOnlyToken';
        $this->readOnlyTokenSecret = bin2hex(random_bytes(24));
        $readToken->tokenHash = hash('sha256', $this->readOnlyTokenSecret);
        $readToken->abilities = ['read'];
        $em->persist($readToken);

        $em->flush();

        $this->projectUuid = $project->uuid->toString();
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        unset($_ENV['GRAPHQL_INTROSPECTION_ENABLED']);
        unset($_ENV['GRAPHQL_MAX_COMPLEXITY']);
        unset($_ENV['GRAPHQL_MAX_DEPTH']);
        parent::tearDown();
    }

    public function testIntrospectionBlockedWhenDisabled(): void
    {
        $_ENV['GRAPHQL_INTROSPECTION_ENABLED'] = 'false';

        $client = static::createClient();
        $client->request(
            'POST',
            '/api/projects/' . $this->projectUuid . '/graphql',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode(['query' => '{ __schema { queryType { name } } }'])
        );

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertStringContainsString('introspection', strtolower($data['errors'][0]['message']));
    }

    public function testIntrospectionAllowedWhenExplicitlyEnabled(): void
    {
        $_ENV['GRAPHQL_INTROSPECTION_ENABLED'] = 'true';

        $client = static::createClient();
        $client->request(
            'POST',
            '/api/projects/' . $this->projectUuid . '/graphql',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode(['query' => '{ __schema { queryType { name } } }'])
        );

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayNotHasKey('errors', $data);
        $this->assertSame('Query', $data['data']['__schema']['queryType']['name']);
    }

    public function testQueryComplexityBlocksFanoutAttack(): void
    {
        // Enforce low max complexity to test threshold
        $_ENV['GRAPHQL_MAX_COMPLEXITY'] = '20';

        $client = static::createClient();
        // A query with multiple fields and list limit exceeding complexity 20
        $client->request(
            'POST',
            '/api/projects/' . $this->projectUuid . '/graphql',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'query' => '{
                    p1: postsList(limit: 50) { uuid title status createdAt }
                }',
            ])
        );

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertStringContainsString('complexity', strtolower($data['errors'][0]['message']));
    }

    public function testQueryComplexityAllowsNormalQuery(): void
    {
        $_ENV['GRAPHQL_MAX_COMPLEXITY'] = '1000';

        $client = static::createClient();
        $client->request(
            'POST',
            '/api/projects/' . $this->projectUuid . '/graphql',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'query' => '{ _ping }',
            ])
        );

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayNotHasKey('errors', $data);
        $this->assertSame('pong', $data['data']['_ping']);
    }

    public function testListWithZeroOrNegativeLimitDoesNotCrash(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/projects/' . $this->projectUuid . '/graphql',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'query' => '{ postsList(limit: 0, offset: -5) { uuid } }',
            ])
        );

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayNotHasKey('errors', $data);
        $this->assertIsArray($data['data']['postsList']);
    }

    public function testBatchQueryArrayIsRejected(): void
    {
        $client = static::createClient();
        // Send a JSON array (batch) instead of a single operation object
        $client->request(
            'POST',
            '/api/projects/' . $this->projectUuid . '/graphql',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                ['query' => '{ _ping }'],
                ['query' => '{ _ping }'],
            ])
        );

        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertStringContainsString('batch', strtolower($data['errors'][0]['message']));
    }

    public function testAliasAmplificationIsRejected(): void
    {
        $client = static::createClient();
        // Build a query with 35 aliases (exceeding MAX_ALIASES = 30)
        $aliases = '';
        for ($i = 1; $i <= 35; $i++) {
            $aliases .= "  alias{$i}: _ping\n";
        }
        $client->request(
            'POST',
            '/api/projects/' . $this->projectUuid . '/graphql',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode(['query' => "{\n{$aliases}}"])
        );

        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertStringContainsString('alias', strtolower($data['errors'][0]['message']));
    }

    public function testAliasesWithinLimitAreAllowed(): void
    {
        $client = static::createClient();
        // 5 aliases — should be accepted (< MAX_ALIASES = 30)
        $aliases = '';
        for ($i = 1; $i <= 5; $i++) {
            $aliases .= "  a{$i}: _ping\n";
        }
        $client->request(
            'POST',
            '/api/projects/' . $this->projectUuid . '/graphql',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode(['query' => "{\n{$aliases}}"])
        );

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayNotHasKey('errors', $data);
    }

    public function testMaxPayloadSizeIsEnforced(): void
    {
        $client = static::createClient();
        // 65 KB body — exceeds MAX_QUERY_LENGTH = 64 KB
        $largeQuery = str_repeat('x', 66_000);
        $client->request(
            'POST',
            '/api/projects/' . $this->projectUuid . '/graphql',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode(['query' => $largeQuery])
        );

        $this->assertSame(413, $client->getResponse()->getStatusCode());
    }

    public function testMutationBlockedViaGet(): void
    {
        $client = static::createClient();
        $client->request(
            'GET',
            '/api/projects/' . $this->projectUuid . '/graphql?query=' . urlencode('mutation { createPosts(input: { title: "Bad" }) { uuid } }'),
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret,
            ]
        );

        $this->assertSame(405, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertStringContainsString('mutation', strtolower($data['errors'][0]['message']));
    }

    public function testMutationBlockedForReadOnlyToken(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/projects/' . $this->projectUuid . '/graphql',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->readOnlyTokenSecret,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode(['query' => 'mutation { createPosts(input: { title: "Bad" }) { uuid } }'])
        );

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testMultipleOperationsRejected(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/projects/' . $this->projectUuid . '/graphql',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode(['query' => 'query Op1 { _ping } query Op2 { _ping }'])
        );

        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertTrue(
            str_contains(strtolower($data['errors'][0]['message']), 'opér') ||
            str_contains(strtolower($data['errors'][0]['message']), 'operation')
        );
    }

    public function testTooManyFragmentsRejected(): void
    {
        $client = static::createClient();
        $fragments = '';
        for ($i = 1; $i <= 11; $i++) {
            $fragments .= "fragment F{$i} on Query { _ping }\n";
        }
        $query = "{$fragments} query { ...F1 }";

        $client->request(
            'POST',
            '/api/projects/' . $this->projectUuid . '/graphql',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode(['query' => $query])
        );

        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertStringContainsString('fragment', strtolower($data['errors'][0]['message']));
    }

    public function testTooManyDirectivesRejected(): void
    {
        $client = static::createClient();
        $directives = str_repeat('@skip(if: false) ', 16);
        $query = "{ _ping {$directives} }";

        $client->request(
            'POST',
            '/api/projects/' . $this->projectUuid . '/graphql',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode(['query' => $query])
        );

        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertStringContainsString('directive', strtolower($data['errors'][0]['message']));
    }

    public function testFragmentAliasAmplificationDetected(): void
    {
        $client = static::createClient();
        $aliases = '';
        for ($i = 1; $i <= 35; $i++) {
            $aliases .= "  alias{$i}: _ping\n";
        }
        $query = "fragment Exploit on Query {\n{$aliases}}\nquery { ...Exploit }";

        $client->request(
            'POST',
            '/api/projects/' . $this->projectUuid . '/graphql',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode(['query' => $query])
        );

        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertStringContainsString('alias', strtolower($data['errors'][0]['message']));
    }

    public function testUnknownProjectUuidReturns404(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $admin = new \App\Entity\User();
        $admin->email = 'admin-gql-' . bin2hex(random_bytes(4)) . '@example.com';
        $admin->password = 'hashed';
        $admin->roles = ['ROLE_SUPER_ADMIN'];
        $em->persist($admin);
        $em->flush();

        $client->loginUser($admin);
        $randomUuid = (string) \Symfony\Component\Uid\Uuid::v4();
        $client->request(
            'POST',
            '/api/projects/' . $randomUuid . '/graphql',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['query' => '{ _ping }'])
        );

        $this->assertSame(404, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertTrue(
            str_contains(strtolower($data['errors'][0]['message']), 'projet') ||
            str_contains(strtolower($data['errors'][0]['message']), 'project')
        );
    }

    public function testSyntaxErrorReturnsClean400(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/projects/' . $this->projectUuid . '/graphql',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode(['query' => '{ _ping { '])
        );

        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
    }

    public function testMutationAllowedForWriteToken(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/projects/' . $this->projectUuid . '/graphql',
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode(['query' => 'mutation { createPosts(input: { title: "Hello GQL" }) { uuid title status } }'])
        );

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayNotHasKey('errors', $data);
        $this->assertArrayHasKey('createPosts', $data['data']);
        $this->assertSame('Hello GQL', $data['data']['createPosts']['title']);
        $this->assertSame('draft', $data['data']['createPosts']['status']);
    }
}
