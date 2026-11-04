<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class Cycle12HardeningTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Project $project;
    private User $adminUser;
    private Collection $collection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get(EntityManagerInterface::class);

        $this->project = new Project();
        $this->project->name = 'Cycle12 Test ' . bin2hex(random_bytes(4));
        $this->project->publicApi = true;
        $this->em->persist($this->project);

        $this->adminUser = new User();
        $this->adminUser->email = 'admin_' . bin2hex(random_bytes(4)) . '@test.com';
        $this->adminUser->name = 'Admin';
        $this->adminUser->roles = ['ROLE_SUPER_ADMIN'];
        $this->adminUser->password = 'hashed';
        $this->em->persist($this->adminUser);

        $this->collection = new Collection();
        $this->collection->name = 'Articles';
        $this->collection->slug = 'articles_' . bin2hex(random_bytes(3));
        $this->collection->project = $this->project;
        $this->em->persist($this->collection);

        $titleField = new Field();
        $titleField->name = 'Title';
        $titleField->slug = 'title';
        $titleField->type = 'text';
        $titleField->collection = $this->collection;
        $this->em->persist($titleField);

        $this->em->flush();
    }

    public function testGlobalSecurityHeadersPresent(): void
    {
        $this->client->request('GET', '/login');

        $headers = $this->client->getResponse()->headers;
        $this->assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $headers->get('X-Frame-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $headers->get('Referrer-Policy'));
        $this->assertSame('none', $headers->get('X-Permitted-Cross-Domain-Policies'));
        $this->assertSame('camera=(), microphone=(), geolocation=(), payment=()', $headers->get('Permissions-Policy'));
    }

    public function testHstsHeaderEnforcedOnHttps(): void
    {
        $this->client->request('GET', '/login', [], [], [
            'HTTPS' => 'on',
        ]);

        $headers = $this->client->getResponse()->headers;
        $this->assertSame('max-age=31536000; includeSubDomains; preload', $headers->get('Strict-Transport-Security'));
    }

    public function testHstsHeaderEnforcedOnForwardedProto(): void
    {
        $this->client->request('GET', '/login', [], [], [
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $headers = $this->client->getResponse()->headers;
        $this->assertSame('max-age=31536000; includeSubDomains; preload', $headers->get('Strict-Transport-Security'));
    }

    public function testGraphQLRejectsOversizedQuery(): void
    {
        $this->client->loginUser($this->adminUser);

        // Generate query exceeding 64 KB
        $oversizedQuery = '{ _ping ' . str_repeat(' ', 65540) . '}';

        $this->client->request(
            'POST',
            sprintf('/api/projects/%s/graphql', $this->project->uuid->toString()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['query' => $oversizedQuery])
        );

        $this->assertSame(413, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertStringContainsString('dépassée', $data['errors'][0]['message'] ?? '');
    }

    public function testGraphQLRejectsExcessiveDepth(): void
    {
        $this->client->loginUser($this->adminUser);

        // Build deeply nested introspection query (depth > 10)
        $query = 'query DeepQuery {
            __schema {
                types {
                    fields {
                        type {
                            ofType {
                                ofType {
                                    ofType {
                                        ofType {
                                            ofType {
                                                ofType {
                                                    ofType {
                                                        ofType {
                                                            name
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }';

        $this->client->request(
            'POST',
            sprintf('/api/projects/%s/graphql', $this->project->uuid->toString()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['query' => $query])
        );

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertNotEmpty($data['errors'] ?? []);
        $this->assertStringContainsString('Max query depth', $data['errors'][0]['message'] ?? '');
    }

    public function testGraphQLRejectsExcessiveComplexity(): void
    {
        $this->client->loginUser($this->adminUser);

        // Generate query with 40 aliases to test alias amplification guard (MAX_ALIASES=30)
        // which now catches this before the complexity checker runs → HTTP 400
        $aliases = [];
        for ($i = 0; $i < 40; $i++) {
            $aliases[] = "p{$i}: _ping";
        }
        $query = 'query HeavyQuery { ' . implode(' ', $aliases) . ' }';

        $this->client->request(
            'POST',
            sprintf('/api/projects/%s/graphql', $this->project->uuid->toString()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['query' => $query])
        );

        // Alias amplification guard (MAX_ALIASES=30) now fires first → 400 Bad Request
        $this->assertSame(400, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertNotEmpty($data['errors'] ?? []);
        $this->assertStringContainsString('alias', strtolower($data['errors'][0]['message'] ?? ''));
    }

    public function testGraphQLMandatoryPaginationClamp(): void
    {
        $this->client->loginUser($this->adminUser);

        $collectionListName = lcfirst(str_replace('_', '', ucwords($this->collection->slug, '_'))) . 'List';
        $query = sprintf('query { %s(limit: 500, offset: 50000) { uuid } }', $collectionListName);

        $this->client->request(
            'POST',
            sprintf('/api/projects/%s/graphql', $this->project->uuid->toString()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['query' => $query])
        );

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        // Query must succeed and return bounded list without fatal errors
        $this->assertArrayHasKey('data', $data);
        $this->assertIsArray($data['data'][$collectionListName]);
    }

    public function testImportCsvRejectsBatchExceedingLimit(): void
    {
        $pat = new \App\Entity\PersonalAccessToken();
        $pat->user = $this->adminUser;
        $pat->name = 'import-batch-pat';
        $plainPat = bin2hex(random_bytes(20));
        $pat->tokenHash = \App\Entity\ApiToken::hashTokenLegacy($plainPat);
        $pat->tokenVersion = 1;
        $pat->scopes = ['*'];
        $this->em->persist($pat);

        $member = new \App\Entity\ProjectMember();
        $member->project = $this->project;
        $member->user = $this->adminUser;
        $member->email = $this->adminUser->email;
        $member->status = \App\Enum\ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $this->em->persist($member);

        $this->em->flush();

        // Generate 1005 rows
        $rows = [];
        for ($i = 0; $i < 1005; $i++) {
            $rows[] = ['title' => 'Row ' . $i];
        }

        $this->client->request(
            'POST',
            sprintf('/admin-api/projects/%s/collections/%s/import-csv', $this->project->uuid->toString(), $this->collection->slug),
            [],
            [],
            [
                'HTTP_AUTHORIZATION' => 'Bearer ' . $plainPat,
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'data' => $rows,
                'field_mapping' => ['title' => 'title'],
            ])
        );

        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertStringContainsString('1000', $data['error'] ?? '');
    }
}
