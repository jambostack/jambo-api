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
}
