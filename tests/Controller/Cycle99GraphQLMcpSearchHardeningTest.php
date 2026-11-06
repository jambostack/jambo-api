<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ApiToken;
use App\Entity\AuditLog;
use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class Cycle99GraphQLMcpSearchHardeningTest extends WebTestCase
{
    private const LOCALES = ['en', 'fr', 'es', 'ar'];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Project $project;
    private User $admin;
    private string $tokenSecret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $this->admin = new User();
        $this->admin->name = 'Cycle99 Admin';
        $this->admin->email = 'admin_c99_' . bin2hex(random_bytes(4)) . '@test.com';
        $this->admin->password = $hasher->hashPassword($this->admin, 'secure123');
        $this->admin->roles = ['ROLE_SUPER_ADMIN'];
        $this->em->persist($this->admin);

        $this->project = new Project();
        $this->project->name = 'Cycle99 Project ' . bin2hex(random_bytes(4));
        $this->project->publicApi = true;
        $this->project->settings = ['tier' => 'starter'];
        $this->em->persist($this->project);

        $member = new ProjectMember();
        $member->user = $this->admin;
        $member->project = $this->project;
        $member->status = ProjectMemberStatus::Active;
        $this->em->persist($member);

        $col = new Collection();
        $col->name = 'Articles';
        $col->slug = 'articles';
        $col->project = $this->project;
        $this->em->persist($col);

        $field = new Field();
        $field->name = 'Title';
        $field->slug = 'title';
        $field->type = 'text';
        $field->collection = $col;
        $this->em->persist($field);

        $apiToken = new ApiToken();
        $apiToken->name = 'Test Token';
        $apiToken->project = $this->project;
        $this->tokenSecret = bin2hex(random_bytes(24));
        $apiToken->tokenHash = hash('sha256', $this->tokenSecret);
        $apiToken->abilities = ['read', 'write', 'create', '*'];
        $this->em->persist($apiToken);

        $this->em->flush();
    }

    public function testTranslationParityInAllLocalesForCycle99(): void
    {
        $keys = [
            'graphql.ast_depth_exceeded',
            'graphql.too_many_requests',
            'graphql.too_many_aliases',
            'mcp.rate_limit_exceeded',
            'mcp.payload_too_large',
            'search.rate_limit_exceeded',
            'search.reindex_rate_limit_exceeded',
            'search.reindex_success',
        ];

        foreach (self::LOCALES as $locale) {
            $path = __DIR__ . "/../../translations/messages.{$locale}.php";
            $this->assertFileExists($path, "Translation file for '{$locale}' must exist.");
            $dict = require $path;

            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $dict, "Locale '{$locale}' missing '{$key}'");
                $this->assertNotEmpty($dict[$key], "Locale '{$locale}' key '{$key}' must not be empty");
            }
        }
    }

    public function testGraphQLInvalidUuidReturns404(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request(
            'POST',
            '/api/projects/not-a-valid-uuid/graphql',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['query' => '{ _ping }'])
        );

        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
    }

    public function testGraphQLAstDepthExceededIsRejected(): void
    {
        $uuid = $this->project->uuid->toRfc4122();

        // 17 levels of nesting using inline fragments
        $deepQuery = '{ _ping';
        for ($i = 0; $i < 17; $i++) {
            $deepQuery .= ' ... on Query {';
        }
        $deepQuery .= ' _ping';
        for ($i = 0; $i < 17; $i++) {
            $deepQuery .= ' }';
        }
        $deepQuery .= ' }';

        $this->client->request(
            'POST',
            "/api/projects/{$uuid}/graphql",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret,
            ],
            content: json_encode(['query' => $deepQuery])
        );

        $this->assertSame(400, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);

        $audit = $this->em->getRepository(AuditLog::class)->findOneBy([
            'toolName' => 'graphql_security_blocked',
            'status' => 'failed',
        ], ['id' => 'DESC']);

        $this->assertNotNull($audit);
        $this->assertSame('ast_depth_exceeded', $audit->input['reason'] ?? null);
    }

    public function testGraphQLBatchArrayRejectionAuditLogged(): void
    {
        $uuid = $this->project->uuid->toRfc4122();
        $this->client->request(
            'POST',
            "/api/projects/{$uuid}/graphql",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret,
            ],
            content: json_encode([
                ['query' => '{ _ping }'],
                ['query' => '{ _ping }'],
            ])
        );

        $this->assertSame(400, $this->client->getResponse()->getStatusCode());

        $audit = $this->em->getRepository(AuditLog::class)->findOneBy([
            'toolName' => 'graphql_security_blocked',
            'status' => 'failed',
        ], ['id' => 'DESC']);

        $this->assertNotNull($audit, 'Expected AuditLog entry for graphql_security_blocked');
        $this->assertSame('graphql', $audit->source);
    }

    public function testGraphQLMutationAuditLogged(): void
    {
        $uuid = $this->project->uuid->toRfc4122();
        $this->client->request(
            'POST',
            "/api/projects/{$uuid}/graphql",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenSecret,
            ],
            content: json_encode([
                'query' => 'mutation { createArticles(input: { title: "Cycle 99 Audit Test" }) { uuid } }',
            ])
        );

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $audit = $this->em->getRepository(AuditLog::class)->findOneBy([
            'toolName' => 'graphql_mutation',
            'status' => 'success',
        ], ['id' => 'DESC']);

        $this->assertNotNull($audit, 'Expected AuditLog entry for graphql_mutation');
        $this->assertSame('graphql', $audit->source);
        $this->assertNotNull($audit->durationMs);
    }

    public function testMcpPayloadTooLargeAuditLogged(): void
    {
        $oversized = str_repeat('X', 2 * 1024 * 1024 + 10);
        $this->client->request(
            'POST',
            '/mcp',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $oversized
        );

        $this->assertSame(413, $this->client->getResponse()->getStatusCode());

        $audit = $this->em->getRepository(AuditLog::class)->findOneBy([
            'toolName' => 'mcp_payload_too_large',
            'status' => 'failed',
        ], ['id' => 'DESC']);

        $this->assertNotNull($audit, 'Expected AuditLog entry for mcp_payload_too_large');
        $this->assertSame('mcp_controller', $audit->source);
    }

    public function testSearchReindexAuditLogged(): void
    {
        $this->client->loginUser($this->admin);
        $uuid = $this->project->uuid->toRfc4122();

        $this->client->request('POST', "/api/projects/{$uuid}/search/reindex");
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $audit = $this->em->getRepository(AuditLog::class)->findOneBy([
            'toolName' => 'search_reindex',
            'status' => 'success',
        ], ['id' => 'DESC']);

        $this->assertNotNull($audit, 'Expected AuditLog entry for search_reindex');
        $this->assertSame('search_controller', $audit->source);
        $this->assertNotNull($audit->durationMs);
    }
}
