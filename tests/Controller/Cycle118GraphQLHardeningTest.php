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

class Cycle118GraphQLHardeningTest extends WebTestCase
{
    private const LOCALES = ['en', 'fr', 'es', 'ar'];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Project $project;
    private User $admin;
    private string $writeTokenSecret;
    private string $readOnlyTokenSecret;
    private string $noAbilityTokenSecret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $this->admin = new User();
        $this->admin->name = 'Cycle118 Admin';
        $this->admin->email = 'admin_c118_' . bin2hex(random_bytes(4)) . '@test.com';
        $this->admin->password = $hasher->hashPassword($this->admin, 'secure123');
        $this->admin->roles = ['ROLE_SUPER_ADMIN'];
        $this->em->persist($this->admin);

        $this->project = new Project();
        $this->project->name = 'Cycle118 Project ' . bin2hex(random_bytes(4));
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
        $col->fields->add($field);
        $this->em->persist($field);

        // Token with full abilities
        $writeToken = new ApiToken();
        $writeToken->name = 'Write Token';
        $writeToken->project = $this->project;
        $this->writeTokenSecret = bin2hex(random_bytes(24));
        $writeToken->tokenHash = hash('sha256', $this->writeTokenSecret);
        $writeToken->abilities = ['read', 'write', 'create', '*'];
        $this->em->persist($writeToken);

        // Token with read-only ability
        $readToken = new ApiToken();
        $readToken->name = 'Read Only Token';
        $readToken->project = $this->project;
        $this->readOnlyTokenSecret = bin2hex(random_bytes(24));
        $readToken->tokenHash = hash('sha256', $this->readOnlyTokenSecret);
        $readToken->abilities = ['read'];
        $this->em->persist($readToken);

        // Token with no read or write abilities
        $noAbilityToken = new ApiToken();
        $noAbilityToken->name = 'No Ability Token';
        $noAbilityToken->project = $this->project;
        $this->noAbilityTokenSecret = bin2hex(random_bytes(24));
        $noAbilityToken->tokenHash = hash('sha256', $this->noAbilityTokenSecret);
        $noAbilityToken->abilities = ['custom_non_read'];
        $this->em->persist($noAbilityToken);

        $this->em->flush();
    }

    public function testTranslationParityInAllLocalesForCycle118(): void
    {
        $keys = [
            'graphql.too_many_fragment_spreads',
            'graphql.too_many_variables',
            'graphql.variables_too_complex',
            'graphql.invalid_operation_name',
            'graphql.query_permission_denied',
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

    public function testGraphQLResponseHasStrictSecurityHeaders(): void
    {
        $uuid = $this->project->uuid->toRfc4122();

        $this->client->request(
            'POST',
            "/api/projects/{$uuid}/graphql",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->writeTokenSecret,
            ],
            content: json_encode(['query' => '{ _ping }'])
        );

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('max-age=31536000', (string) $response->headers->get('Strict-Transport-Security'));
        $this->assertSame('0', $response->headers->get('X-XSS-Protection'));
        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
    }

    public function testTooManyFragmentSpreadsRejected(): void
    {
        $uuid = $this->project->uuid->toRfc4122();

        // Build a query with 25 fragment spreads exceeding MAX_FRAGMENT_SPREADS = 20
        $spreads = '';
        for ($i = 0; $i < 25; $i++) {
            $spreads .= "...PingFrag\n";
        }

        $query = "fragment PingFrag on Query { _ping }\nquery TestQuery {\n{$spreads}}";

        $this->client->request(
            'POST',
            "/api/projects/{$uuid}/graphql",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->writeTokenSecret,
            ],
            content: json_encode(['query' => $query])
        );

        $this->assertSame(400, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);

        $audit = $this->em->getRepository(AuditLog::class)->findOneBy([
            'toolName' => 'graphql_security_blocked',
            'status' => 'failed',
        ], ['id' => 'DESC']);

        $this->assertNotNull($audit);
        $this->assertSame('too_many_fragment_spreads', $audit->input['reason'] ?? null);
    }

    public function testTooManyVariablesRejected(): void
    {
        $uuid = $this->project->uuid->toRfc4122();

        // 55 variables exceeding MAX_VARIABLES_COUNT = 50
        $variables = [];
        for ($i = 0; $i < 55; $i++) {
            $variables["var{$i}"] = "value{$i}";
        }

        $this->client->request(
            'POST',
            "/api/projects/{$uuid}/graphql",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->writeTokenSecret,
            ],
            content: json_encode([
                'query' => '{ _ping }',
                'variables' => $variables,
            ])
        );

        $this->assertSame(400, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertStringContainsString('variables', strtolower($data['errors'][0]['message']));
    }

    public function testDeeplyNestedVariablesRejected(): void
    {
        $uuid = $this->project->uuid->toRfc4122();

        // 7 levels of nesting exceeding MAX_VARIABLE_DEPTH = 5
        $nested = ['val' => 1];
        for ($i = 0; $i < 7; $i++) {
            $nested = ['level' => $nested];
        }

        $this->client->request(
            'POST',
            "/api/projects/{$uuid}/graphql",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->writeTokenSecret,
            ],
            content: json_encode([
                'query' => '{ _ping }',
                'variables' => ['data' => $nested],
            ])
        );

        $this->assertSame(400, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
    }

    public function testInvalidOperationNameRejected(): void
    {
        $uuid = $this->project->uuid->toRfc4122();

        $this->client->request(
            'POST',
            "/api/projects/{$uuid}/graphql",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->writeTokenSecret,
            ],
            content: json_encode([
                'query' => 'query MyOp { _ping }',
                'operationName' => 'bad-operation-name!',
            ])
        );

        $this->assertSame(400, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertStringContainsString('op', strtolower($data['errors'][0]['message']));
    }

    public function testValidOperationNameAccepted(): void
    {
        $uuid = $this->project->uuid->toRfc4122();

        $this->client->request(
            'POST',
            "/api/projects/{$uuid}/graphql",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->writeTokenSecret,
            ],
            content: json_encode([
                'query' => 'query ValidPingOp { _ping }',
                'operationName' => 'ValidPingOp',
            ])
        );

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayNotHasKey('errors', $data);
        $this->assertSame('pong', $data['data']['_ping']);
    }

    public function testReadOnlyTokenCanQueryViaPost(): void
    {
        $uuid = $this->project->uuid->toRfc4122();

        $this->client->request(
            'POST',
            "/api/projects/{$uuid}/graphql",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->readOnlyTokenSecret,
            ],
            content: json_encode(['query' => '{ _ping }'])
        );

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayNotHasKey('errors', $data);
        $this->assertSame('pong', $data['data']['_ping']);
    }

    public function testReadOnlyTokenCannotExecuteMutationViaPost(): void
    {
        $uuid = $this->project->uuid->toRfc4122();

        $this->client->request(
            'POST',
            "/api/projects/{$uuid}/graphql",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->readOnlyTokenSecret,
            ],
            content: json_encode(['query' => 'mutation { createArticles(input: { title: "Test" }) { uuid } }'])
        );

        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
    }

    public function testNoAbilityTokenCannotQuery(): void
    {
        $uuid = $this->project->uuid->toRfc4122();

        $this->client->request(
            'POST',
            "/api/projects/{$uuid}/graphql",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->noAbilityTokenSecret,
            ],
            content: json_encode(['query' => '{ _ping }'])
        );

        // Rejection can come from subscriber or controller with 403
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testGraphQLListQuerySupportsSearchAndSort(): void
    {
        $uuid = $this->project->uuid->toRfc4122();

        $this->client->request(
            'POST',
            "/api/projects/{$uuid}/graphql",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->writeTokenSecret,
            ],
            content: json_encode([
                'query' => '{ articlesList(search: "Test", sort: "createdAt_DESC", limit: 10) { uuid title } }',
            ])
        );

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayNotHasKey('errors', $data);
        $this->assertIsArray($data['data']['articlesList']);
    }
}
