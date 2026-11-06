<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\AuditLog;
use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class Cycle113SeoAndCollectionSecurityHardeningTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $admin;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get(EntityManagerInterface::class);
        $hasher = $this->client->getContainer()->get(UserPasswordHasherInterface::class);

        $suffix = bin2hex(random_bytes(4));

        $this->admin = new User();
        $this->admin->name = 'Cycle113 SuperAdmin';
        $this->admin->email = "superadmin_c113_{$suffix}@test.com";
        $this->admin->password = $hasher->hashPassword($this->admin, 'SuperSecret123!');
        $this->admin->roles = ['ROLE_SUPER_ADMIN'];
        $this->em->persist($this->admin);

        $this->project = new Project();
        $this->project->name = "Project Cycle 113 {$suffix}";
        $this->project->publicApi = true;
        $this->em->persist($this->project);
        $this->em->flush();
    }

    private function seoUrl(string $path = ''): string
    {
        return '/admin-api/' . $this->project->uuid->toString() . '/seo' . $path;
    }

    public function testSeoBulkContentTypeValidation(): void
    {
        $this->client->loginUser($this->admin);
        $url = $this->seoUrl('/bulk');

        // PUT with text/plain instead of application/json
        $this->client->request('PUT', $url, [], [], ['CONTENT_TYPE' => 'text/plain'], 'entries=[]');
        $this->assertSame(415, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_CONTENT_TYPE', $data['code'] ?? null);
    }

    public function testSeoBulkPayloadSizeLimit(): void
    {
        $this->client->loginUser($this->admin);
        $url = $this->seoUrl('/bulk');

        // Payload > 64KB
        $giantPayload = json_encode([
            'entries' => [
                [
                    'uuid' => 'a1b2c3d4-e5f6-4a1b-8c2d-3e4f5a6b7c8d',
                    'metaTitle' => str_repeat('A', 70000),
                ]
            ],
        ]);

        $this->client->request('PUT', $url, [], [], ['CONTENT_TYPE' => 'application/json'], $giantPayload);
        $this->assertSame(413, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('PAYLOAD_TOO_LARGE', $data['code'] ?? null);
    }

    public function testSeoSecurityHeaders(): void
    {
        $this->client->loginUser($this->admin);
        $url = $this->seoUrl('/scores');

        $this->client->request('GET', $url);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $headers = $this->client->getResponse()->headers;
        $this->assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $headers->get('X-Frame-Options'));
        $this->assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
    }

    public function testSeoBulkAuditLogging(): void
    {
        $this->client->loginUser($this->admin);

        // Create a test collection and entry
        $collection = new Collection();
        $collection->project = $this->project;
        $collection->name = 'Articles 113';
        $collection->slug = 'articles-113';
        $this->em->persist($collection);

        $entry = new ContentEntry();
        $entry->project = $this->project;
        $entry->collection = $collection;
        $entry->status = 'published';
        $entry->metaTitle = 'Original Title';
        $this->em->persist($entry);
        $this->em->flush();

        $entryUuid = $entry->uuid->toRfc4122();
        $url = $this->seoUrl('/bulk');

        $this->client->request('PUT', $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'entries' => [
                [
                    'uuid' => $entryUuid,
                    'metaTitle' => 'Hardened SEO Title',
                    'metaDescription' => 'Hardened SEO Description',
                ],
            ],
        ]));

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame(1, $data['updated'] ?? 0);

        // Verify audit log
        $this->em->clear();
        $log = $this->em->getRepository(AuditLog::class)->findOneBy(['toolName' => 'admin_seo_bulk_update'], ['id' => 'DESC']);
        $this->assertNotNull($log);
        $this->assertSame('success', $log->status);
        $this->assertSame(1, $log->output['updated'] ?? null);

        // Verify SEO revision was created automatically by onFlush listener
        $revision = $this->em->getRepository(\App\Entity\SeoRevision::class)->findOneBy([], ['id' => 'DESC']);
        $this->assertNotNull($revision);
        $this->assertSame('Hardened SEO Title', $revision->metaTitle);
        $this->assertSame('Hardened SEO Description', $revision->metaDescription);
    }

    public function testSeoAuditInvalidUuidPattern(): void
    {
        $this->client->loginUser($this->admin);

        // Request with non-UUID string in route
        $url = $this->seoUrl('/audit/not-a-valid-uuid');
        $this->client->request('GET', $url);

        // Should return 404 due to regex requirement in route
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testPublicApiCollectionsSecurityHeaders(): void
    {
        $this->client->request('GET', '/public-api/collections');
        // Unauthenticated request gives 401
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());

        $headers = $this->client->getResponse()->headers;
        $this->assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $headers->get('X-Frame-Options'));
        $this->assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
    }

    public function testPublicApiCollectionsShowInvalidSlug(): void
    {
        // Slug with special malicious characters
        $badSlug = 'invalid_slug_with_<script>_alert(1)';
        $this->client->request('GET', '/public-api/collections/' . urlencode($badSlug));
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());

        // Slug exceeding 128 characters
        $tooLongSlug = str_repeat('a', 130);
        $this->client->request('GET', '/public-api/collections/' . $tooLongSlug);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testTranslationsCompleteAcrossAllFourLocales(): void
    {
        $en = require __DIR__ . '/../../../translations/messages.en.php';
        $fr = require __DIR__ . '/../../../translations/messages.fr.php';
        $es = require __DIR__ . '/../../../translations/messages.es.php';
        $ar = require __DIR__ . '/../../../translations/messages.ar.php';

        $keysToCheck = [
            'api_collection.unauthorized',
            'api_collection.insufficient_abilities',
            'api_collection.not_found',
            'api_collection.rate_limit_exceeded',
            'admin_seo.project_not_found',
            'admin_seo.entry_not_found',
            'admin_seo.invalid_json',
            'admin_seo.payload_too_large',
            'admin_seo.too_many_entries',
            'admin_seo.invalid_canonical_url',
            'admin_seo.invalid_content_type',
            'admin_seo.rate_limit_exceeded',
        ];

        foreach ($keysToCheck as $k) {
            $this->assertArrayHasKey($k, $en, "Missing key {$k} in EN");
            $this->assertArrayHasKey($k, $fr, "Missing key {$k} in FR");
            $this->assertArrayHasKey($k, $es, "Missing key {$k} in ES");
            $this->assertArrayHasKey($k, $ar, "Missing key {$k} in AR");

            $this->assertNotEmpty($en[$k], "Empty value for {$k} in EN");
            $this->assertNotEmpty($fr[$k], "Empty value for {$k} in FR");
            $this->assertNotEmpty($es[$k], "Empty value for {$k} in ES");
            $this->assertNotEmpty($ar[$k], "Empty value for {$k} in AR");
        }
    }
}
