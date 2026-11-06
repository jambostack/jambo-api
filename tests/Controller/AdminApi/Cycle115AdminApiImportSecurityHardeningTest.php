<?php

declare(strict_types=1);

namespace App\Tests\Controller\AdminApi;

use App\Entity\AuditLog;
use App\Entity\Collection;
use App\Entity\PersonalAccessToken;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Cycle #115 — AdminApi Import, Ping & Public Controllers Hardening Test Suite:
 * - Strict Security Headers (nosniff, DENY, no-store) on Import, Ping, and OpenAPI
 * - Content-Type validation (415 INVALID_CONTENT_TYPE) on CSV import
 * - Strict regex validation on collection slug (422 INVALID_SLUG_FORMAT)
 * - Immediate Audit logging flush on mutations and rate limits
 * - Complete 4-locale i18n parity (ar, en, es, fr)
 */
class Cycle115AdminApiImportSecurityHardeningTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $patPlain;
    private string $projectUuid;
    private User $user;
    private Project $project;
    private Collection $collection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get(EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(4));

        $this->user = new User();
        $this->user->email = "adminapi_c115_{$suffix}@example.com";
        $this->user->password = 'hashed_pass_123';
        $this->user->roles = ['ROLE_SUPER_ADMIN'];
        $this->em->persist($this->user);

        $this->project = new Project();
        $this->project->name = "Hardened Import Project C115 {$suffix}";
        $this->project->publicApi = true;
        $this->project->defaultLocale = 'en';
        $this->project->locales = ['en', 'fr'];
        $this->em->persist($this->project);

        $member = new ProjectMember();
        $member->project = $this->project;
        $member->user = $this->user;
        $member->email = $this->user->email;
        $member->status = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $this->em->persist($member);

        $this->collection = new Collection();
        $this->collection->project = $this->project;
        $this->collection->name = 'Products';
        $this->collection->slug = 'products';
        $this->em->persist($this->collection);

        $this->patPlain = 'jbo_pat_' . bin2hex(random_bytes(24));
        $pat = new PersonalAccessToken();
        $pat->user = $this->user;
        $pat->name = 'Cycle 115 Full Token';
        $pat->tokenHash = hash('sha256', $this->patPlain);
        $pat->scopes = ['*'];
        $this->em->persist($pat);

        $this->em->flush();
        $this->projectUuid = (string) $this->project->uuid;
    }

    private function authHeaders(array $extra = []): array
    {
        return array_merge([
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->patPlain,
            'HTTP_ACCEPT'        => 'application/json',
        ], $extra);
    }

    public function testImportCsvEnforcesSecurityHeaders(): void
    {
        $this->client->request(
            'POST',
            "/admin-api/projects/{$this->projectUuid}/collections/products/import-csv",
            [],
            [],
            $this->authHeaders(['CONTENT_TYPE' => 'application/json']),
            json_encode(['data' => [['title' => 'Item 1']], 'field_mapping' => ['title' => 'title']])
        );

        $response = $this->client->getResponse();
        $this->assertTrue($response->headers->has('X-Content-Type-Options'), 'Header X-Content-Type-Options missing');
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertTrue($response->headers->has('X-Frame-Options'), 'Header X-Frame-Options missing');
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testImportCsvRejectsInvalidContentTypeWith415(): void
    {
        $this->client->request(
            'POST',
            "/admin-api/projects/{$this->projectUuid}/collections/products/import-csv",
            [],
            [],
            $this->authHeaders(['CONTENT_TYPE' => 'text/plain']),
            'invalid-text-payload'
        );

        $response = $this->client->getResponse();
        $this->assertSame(415, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('INVALID_CONTENT_TYPE', $data['code'] ?? null);
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }

    public function testImportCsvRejectsInvalidSlugFormatWith422(): void
    {
        $invalidSlug = 'invalid$slug!format';
        $this->client->request(
            'POST',
            "/admin-api/projects/{$this->projectUuid}/collections/{$invalidSlug}/import-csv",
            [],
            [],
            $this->authHeaders(['CONTENT_TYPE' => 'application/json']),
            json_encode(['data' => [['test' => 'val']], 'field_mapping' => ['test' => 'test']])
        );

        $response = $this->client->getResponse();
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('INVALID_SLUG_FORMAT', $data['code'] ?? null);
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }

    public function testPingControllerSecurityHeaders(): void
    {
        $this->client->request(
            'GET',
            '/admin-api/_ping',
            [],
            [],
            $this->authHeaders()
        );

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testOpenApiSpecSecurityHeaders(): void
    {
        $this->client->request('GET', '/admin-api/openapi.json', [], [], $this->authHeaders());

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertStringContainsString('max-age=60', (string) $response->headers->get('Cache-Control'));
    }

    public function testPublicApiProjectSecurityHeaders(): void
    {
        $this->client->request('GET', '/public-api');

        $response = $this->client->getResponse();
        // Since no token provided, it returns 401, but MUST have security headers
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testI18nCompletenessAcrossAllFourLocales(): void
    {
        $requiredKeys = [
            'admin_api.collection.invalid_slug_format',
            'admin_api.invalid_content_type',
            'admin_api.import.too_many_requests',
            'admin_api.ping.rate_limit_exceeded',
            'admin_api.openapi.rate_limit_exceeded',
        ];

        $locales = ['ar', 'en', 'es', 'fr'];
        $baseDir = self::getContainer()->getParameter('kernel.project_dir');

        foreach ($locales as $locale) {
            $path = $baseDir . "/translations/messages.{$locale}.php";
            $this->assertFileExists($path, "Translation file missing: {$path}");
            $messages = require $path;
            $this->assertIsArray($messages, "Translation messages must be an array for locale: {$locale}");

            foreach ($requiredKeys as $key) {
                $this->assertArrayHasKey($key, $messages, "Missing key '{$key}' in locale {$locale}");
                $this->assertNotEmpty($messages[$key], "Empty translation value for key '{$key}' in locale {$locale}");
            }
        }
    }
}
