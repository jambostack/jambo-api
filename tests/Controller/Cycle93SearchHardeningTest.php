<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use App\Service\SearchService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

class Cycle93SearchHardeningTest extends WebTestCase
{
    private const LOCALES = ['en', 'fr', 'es', 'ar'];

    private const NEW_TRANSLATION_KEYS = [
        'search.invalid_query_format',
        'search.query_too_complex',
        'search.query_nesting_too_deep',
        'search.query_no_searchable_character',
        'search.invalid_status_format',
        'search.invalid_sort_format',
        'search.invalid_limit_format',
        'search.invalid_offset_format',
        'search.invalid_page_format',
        'search.offset_too_high',
        'search.quota.document_limit_exceeded',
    ];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private SearchService $searchService;
    private Project $project;
    private User $admin;
    private Collection $collection;
    private ContentEntry $entry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->searchService = self::getContainer()->get(SearchService::class);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        $this->admin = new User();
        $this->admin->name = 'Cycle93 Admin';
        $this->admin->email = 'admin_c93_' . bin2hex(random_bytes(4)) . '@test.com';
        $this->admin->password = $hasher->hashPassword($this->admin, 'secure123');
        $this->admin->roles = ['ROLE_SUPER_ADMIN'];
        $this->em->persist($this->admin);

        $this->project = new Project();
        $this->project->name = 'Cycle93 Search Project ' . bin2hex(random_bytes(4));
        $this->project->publicApi = false;
        $this->project->settings = ['tier' => 'starter'];
        $this->em->persist($this->project);

        $member = new ProjectMember();
        $member->user = $this->admin;
        $member->project = $this->project;
        $member->status = ProjectMemberStatus::Active;
        $this->em->persist($member);

        $this->collection = new Collection();
        $this->collection->name = 'Articles';
        $this->collection->slug = 'articles_' . bin2hex(random_bytes(3));
        $this->collection->project = $this->project;
        $this->em->persist($this->collection);

        $this->entry = new ContentEntry();
        $this->entry->collection = $this->collection;
        $this->entry->project = $this->project;
        $this->entry->status = 'published';
        $this->entry->locale = 'en';
        $this->entry->slug = 'cycle93-search-test-article';
        $this->entry->metaTitle = 'Cycle 93 Search Hardening';
        $this->em->persist($this->entry);

        $this->em->flush();
    }

    public function testTranslationParityInAllLocales(): void
    {
        foreach (self::LOCALES as $locale) {
            $path = __DIR__ . "/../../translations/messages.{$locale}.php";
            $this->assertFileExists($path, "Translation file for '{$locale}' must exist.");
            $dict = require $path;

            foreach (self::NEW_TRANSLATION_KEYS as $key) {
                $this->assertArrayHasKey($key, $dict, "Locale '{$locale}' is missing key '{$key}'");
                $this->assertNotEmpty($dict[$key], "Locale '{$locale}' key '{$key}' must not be empty");
            }
        }
    }

    public function testSearchDocLimitsByTierAndCustomSettings(): void
    {
        $proj = new Project();

        // 1. Free tier default
        $proj->settings = ['tier' => 'free'];
        $this->assertSame(1000, $this->searchService->getProjectSearchDocLimit($proj));

        // 2. Starter tier
        $proj->settings = ['tier' => 'starter'];
        $this->assertSame(5000, $this->searchService->getProjectSearchDocLimit($proj));

        // 3. Pro tier
        $proj->settings = ['plan' => 'pro'];
        $this->assertSame(25000, $this->searchService->getProjectSearchDocLimit($proj));

        // 4. Enterprise tier
        $proj->settings = ['tier' => 'enterprise'];
        $this->assertSame(100000, $this->searchService->getProjectSearchDocLimit($proj));

        // 5. Custom project setting override
        $proj->settings = ['tier' => 'free', 'max_search_documents' => 7500];
        $this->assertSame(7500, $this->searchService->getProjectSearchDocLimit($proj));

        // 6. Fallback default
        $proj->settings = [];
        $this->assertSame(SearchService::DEFAULT_PROJECT_SEARCH_DOC_LIMIT, $this->searchService->getProjectSearchDocLimit($proj));
    }

    public function testSearchEndpointRejectsInvalidUuid(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/api/projects/not-a-valid-uuid/search?q=test');

        $this->assertResponseStatusCodeSame(404);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testSearchEndpointRejectsUnknownProject(): void
    {
        $this->client->loginUser($this->admin);
        $randomUuid = Uuid::v4()->toRfc4122();
        $this->client->request('GET', "/api/projects/{$randomUuid}/search?q=test");

        $this->assertResponseStatusCodeSame(404);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $data);
    }

    public function testSearchEndpointRequiresQuery(): void
    {
        $this->client->loginUser($this->admin);
        $uuid = $this->project->uuid->toRfc4122();

        // 1. Missing q parameter
        $this->client->request('GET', "/api/projects/{$uuid}/search");
        $this->assertResponseStatusCodeSame(400);

        // 2. Empty string q
        $this->client->request('GET', "/api/projects/{$uuid}/search?q=");
        $this->assertResponseStatusCodeSame(400);

        // 3. Whitespace only q
        $this->client->request('GET', "/api/projects/{$uuid}/search?q=%20%20%20");
        $this->assertResponseStatusCodeSame(400);
    }

    public function testSearchEndpointRejectsNonStringQuery(): void
    {
        $this->client->loginUser($this->admin);
        $uuid = $this->project->uuid->toRfc4122();

        $this->client->request('GET', "/api/projects/{$uuid}/search?q[]=malicious_array");
        $this->assertResponseStatusCodeSame(400);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertStringContainsString('Invalid search query format', $data['error']);
    }

    public function testSearchEndpointRejectsOversizedQuery(): void
    {
        $this->client->loginUser($this->admin);
        $uuid = $this->project->uuid->toRfc4122();

        $oversizedQuery = str_repeat('a', 501);
        $this->client->request('GET', "/api/projects/{$uuid}/search?q=" . urlencode($oversizedQuery));
        $this->assertResponseStatusCodeSame(400);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertStringContainsString('Search query exceeds maximum allowed length', $data['error']);
    }

    public function testSearchEndpointRejectsQueryWithoutSearchableChar(): void
    {
        $this->client->loginUser($this->admin);
        $uuid = $this->project->uuid->toRfc4122();

        $queries = ['???', '***', '%%%', '---', '((()))', ';;;'];
        foreach ($queries as $q) {
            $this->client->request('GET', "/api/projects/{$uuid}/search?q=" . urlencode($q));
            $this->assertResponseStatusCodeSame(400, "Query '{$q}' should be rejected as non-searchable");
            $data = json_decode((string) $this->client->getResponse()->getContent(), true);
            $this->assertStringContainsString('must contain at least one searchable letter or number', $data['error']);
        }
    }

    public function testSearchEndpointRejectsOverlyComplexQueryTerms(): void
    {
        $this->client->loginUser($this->admin);
        $uuid = $this->project->uuid->toRfc4122();

        // 26 terms exceeds MAX_QUERY_TERMS (25)
        $words = array_map(fn($i) => "word{$i}", range(1, 26));
        $complexQuery = implode(' ', $words);

        $this->client->request('GET', "/api/projects/{$uuid}/search?q=" . urlencode($complexQuery));
        $this->assertResponseStatusCodeSame(400);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertStringContainsString('maximum allowed term count', $data['error']);
    }

    public function testSearchEndpointRejectsDeeplyNestedParentheses(): void
    {
        $this->client->loginUser($this->admin);
        $uuid = $this->project->uuid->toRfc4122();

        // 4 levels of nesting exceeds MAX_NESTING_DEPTH (3)
        $nestedQuery = '((((search terms))))';
        $this->client->request('GET', "/api/projects/{$uuid}/search?q=" . urlencode($nestedQuery));
        $this->assertResponseStatusCodeSame(400);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertStringContainsString('parenthesis nesting exceeds allowed depth', $data['error']);
    }

    public function testSearchEndpointValidatesPaginationLimits(): void
    {
        $this->client->loginUser($this->admin);
        $uuid = $this->project->uuid->toRfc4122();

        // 1. Invalid limit format
        $this->client->request('GET', "/api/projects/{$uuid}/search?q=test&limit=abc");
        $this->assertResponseStatusCodeSame(400);

        // 2. Negative offset
        $this->client->request('GET', "/api/projects/{$uuid}/search?q=test&offset=-1");
        $this->assertResponseStatusCodeSame(400);

        // 3. Offset exceeds MAX_OFFSET (10000)
        $this->client->request('GET', "/api/projects/{$uuid}/search?q=test&offset=10001");
        $this->assertResponseStatusCodeSame(400);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertStringContainsString('Search offset exceeds maximum allowed limit', $data['error']);

        // 4. Invalid page format
        $this->client->request('GET', "/api/projects/{$uuid}/search?q=test&page=0");
        $this->assertResponseStatusCodeSame(400);

        // 5. Page that results in offset > MAX_OFFSET
        $this->client->request('GET', "/api/projects/{$uuid}/search?q=test&page=600&limit=20");
        $this->assertResponseStatusCodeSame(400);
    }

    public function testSearchEndpointValidatesFilters(): void
    {
        $this->client->loginUser($this->admin);
        $uuid = $this->project->uuid->toRfc4122();

        // 1. Malicious collection injection attempt
        $this->client->request('GET', "/api/projects/{$uuid}/search?q=test&collection=" . urlencode('evil" OR "1"="1'));
        $this->assertResponseStatusCodeSame(400);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertStringContainsString('Invalid collection format', $data['error']);

        // 2. Malicious locale format
        $this->client->request('GET', "/api/projects/{$uuid}/search?q=test&locale=" . urlencode('en-US; DROP TABLE users;'));
        $this->assertResponseStatusCodeSame(400);

        // 3. Invalid status
        $this->client->request('GET', "/api/projects/{$uuid}/search?q=test&status=corrupted_status");
        $this->assertResponseStatusCodeSame(400);

        // 4. Disallowed sort field
        $this->client->request('GET', "/api/projects/{$uuid}/search?q=test&sort=password:asc");
        $this->assertResponseStatusCodeSame(400);

        // 5. Disallowed sort direction
        $this->client->request('GET', "/api/projects/{$uuid}/search?q=test&sort=created_at:side");
        $this->assertResponseStatusCodeSame(400);
    }

    public function testSuccessfulSearchWithValidParameters(): void
    {
        $this->client->loginUser($this->admin);
        $uuid = $this->project->uuid->toRfc4122();

        $this->client->request('GET', "/api/projects/{$uuid}/search?q=Cycle93&locale=en&limit=10&sort=updated_at:desc");
        $this->assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('hits', $data);
        $this->assertArrayHasKey('query', $data);
        $this->assertSame('Cycle93', $data['query']);
    }

    public function testReindexEndpointRejectsUnauthorizedUser(): void
    {
        $uuid = $this->project->uuid->toRfc4122();

        // Anonymous user must be rejected
        $this->client->request('POST', "/api/projects/{$uuid}/search/reindex");
        $this->assertTrue(in_array($this->client->getResponse()->getStatusCode(), [401, 403], true));
    }

    public function testReindexEndpointEnforcesDocumentQuota(): void
    {
        $this->client->loginUser($this->admin);

        // Ajouter une deuxième entrée
        $entry2 = new ContentEntry();
        $entry2->collection = $this->collection;
        $entry2->project = $this->project;
        $entry2->status = 'published';
        $entry2->locale = 'en';
        $entry2->slug = 'cycle93-search-second-entry';
        $this->em->persist($entry2);

        // Forcer le quota max_search_documents à 1 document
        $this->project->settings = ['max_search_documents' => 1];
        $this->em->flush();

        $uuid = $this->project->uuid->toRfc4122();
        $this->client->request('POST', "/api/projects/{$uuid}/search/reindex");

        $this->assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('SEARCH_DOCUMENT_LIMIT_EXCEEDED', $data['code']);
        $this->assertSame(1, $data['limit']);
        $this->assertGreaterThanOrEqual(2, $data['count']);
    }

    public function testReindexEndpointSuccessForAuthorizedAdmin(): void
    {
        $this->client->loginUser($this->admin);

        // Restaurer quota normal
        $this->project->settings = ['tier' => 'starter', 'max_search_documents' => 5000];
        $this->em->flush();

        $uuid = $this->project->uuid->toRfc4122();
        $this->client->request('POST', "/api/projects/{$uuid}/search/reindex");

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertArrayHasKey('indexed', $data);
        $this->assertArrayHasKey('limit', $data);
        $this->assertArrayHasKey('message', $data);
    }
}
