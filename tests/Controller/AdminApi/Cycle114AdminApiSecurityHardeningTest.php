<?php

declare(strict_types=1);

namespace App\Tests\Controller\AdminApi;

use App\Entity\ApiToken;
use App\Entity\AuditLog;
use App\Entity\PersonalAccessToken;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Cycle #114 — AdminApi Security Hardening Test Suite:
 * - Strict Security Headers (nosniff, DENY, no-store) on all endpoints
 * - Content-Type validation (415 INVALID_CONTENT_TYPE)
 * - JSON payload size bounds (413 PAYLOAD_TOO_LARGE)
 * - Strict regex validation on collection slugs and field slugs (422 INVALID_SLUG_FORMAT, SLUG_TOO_LONG)
 * - Rate limiting & Audit logging on all administrative mutations
 * - Complete 4-locale i18n parity (ar, en, es, fr)
 */
class Cycle114AdminApiSecurityHardeningTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $patPlain;
    private string $projectUuid;
    private User $user;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get(EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(4));

        $this->user = new User();
        $this->user->email = "adminapi_c114_{$suffix}@example.com";
        $this->user->password = 'hashed_pass_123';
        $this->user->roles = ['ROLE_SUPER_ADMIN'];
        $this->em->persist($this->user);

        $this->project = new Project();
        $this->project->name = "Hardened Project C114 {$suffix}";
        $this->project->publicApi = true;
        $this->em->persist($this->project);

        $member = new ProjectMember();
        $member->project = $this->project;
        $member->user = $this->user;
        $member->email = $this->user->email;
        $member->status = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $this->em->persist($member);

        $this->patPlain = 'jbo_pat_' . ApiToken::generatePlainToken();
        $pat = new PersonalAccessToken();
        $pat->name = 'test-c114-token';
        $pat->user = $this->user;
        $pat->scopes = ['projects:write', 'schema:write', 'content:write'];
        $pat->tokenHash = ApiToken::hashToken($this->patPlain, $this->client->getContainer()->getParameter('kernel.secret'));
        $pat->tokenVersion = 2;
        $this->em->persist($pat);

        $this->em->flush();
        $this->projectUuid = $this->project->uuid->toString();
    }

    private function authHeaders(string $contentType = 'application/json'): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->patPlain,
            'CONTENT_TYPE' => $contentType,
        ];
    }

    public function testSecurityHeadersAcrossEndpoints(): void
    {
        // 1. Projects Index (200)
        $this->client->request('GET', '/admin-api/projects', server: $this->authHeaders());
        $resp = $this->client->getResponse();
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('nosniff', $resp->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $resp->headers->get('X-Frame-Options'));
        $this->assertStringContainsString('no-store', (string) $resp->headers->get('Cache-Control'));

        // 2. Project Show (200)
        $this->client->request('GET', "/admin-api/projects/{$this->projectUuid}", server: $this->authHeaders());
        $resp = $this->client->getResponse();
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('nosniff', $resp->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $resp->headers->get('X-Frame-Options'));
        $this->assertStringContainsString('no-store', (string) $resp->headers->get('Cache-Control'));

        // 3. Collections Index (200)
        $this->client->request('GET', "/admin-api/projects/{$this->projectUuid}/collections", server: $this->authHeaders());
        $resp = $this->client->getResponse();
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('nosniff', $resp->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $resp->headers->get('X-Frame-Options'));
        $this->assertStringContainsString('no-store', (string) $resp->headers->get('Cache-Control'));

        // 4. Create collection to test fields and CSV export
        $colSlug = 'articles_' . bin2hex(random_bytes(3));
        $this->client->request('POST', "/admin-api/projects/{$this->projectUuid}/collections", server: $this->authHeaders(), content: json_encode(['name' => $colSlug]));
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());

        // 5. Fields Index (200)
        $this->client->request('GET', "/admin-api/projects/{$this->projectUuid}/collections/{$colSlug}/fields", server: $this->authHeaders());
        $resp = $this->client->getResponse();
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('nosniff', $resp->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $resp->headers->get('X-Frame-Options'));
        $this->assertStringContainsString('no-store', (string) $resp->headers->get('Cache-Control'));

        // 6. CSV Export Stream (200)
        $this->client->request('GET', "/admin-api/projects/{$this->projectUuid}/collections/{$colSlug}/export-csv", server: $this->authHeaders());
        $resp = $this->client->getResponse();
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertSame('nosniff', $resp->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $resp->headers->get('X-Frame-Options'));
        $this->assertStringContainsString('no-store', (string) $resp->headers->get('Cache-Control'));
    }

    public function testContentTypeValidationReturns415(): void
    {
        $textHeaders = $this->authHeaders('text/plain');

        // 1. Project create with text/plain
        $this->client->request('POST', '/admin-api/projects', server: $textHeaders, content: '{"name": "BadContentType"}');
        $this->assertSame(415, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_CONTENT_TYPE', $data['code'] ?? null);

        // 2. Project update with text/plain
        $this->client->request('PATCH', "/admin-api/projects/{$this->projectUuid}", server: $textHeaders, content: '{"name": "UpdatedName"}');
        $this->assertSame(415, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_CONTENT_TYPE', $data['code'] ?? null);

        // 3. Collection create with text/plain
        $this->client->request('POST', "/admin-api/projects/{$this->projectUuid}/collections", server: $textHeaders, content: '{"name": "Products"}');
        $this->assertSame(415, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_CONTENT_TYPE', $data['code'] ?? null);

        // Create a valid collection first
        $colSlug = 'posts_' . bin2hex(random_bytes(3));
        $this->client->request('POST', "/admin-api/projects/{$this->projectUuid}/collections", server: $this->authHeaders(), content: json_encode(['name' => $colSlug]));
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());

        // 4. Collection update with text/plain
        $this->client->request('PATCH', "/admin-api/projects/{$this->projectUuid}/collections/{$colSlug}", server: $textHeaders, content: '{"name": "Renamed"}');
        $this->assertSame(415, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_CONTENT_TYPE', $data['code'] ?? null);

        // 5. Field create with text/plain
        $this->client->request('POST', "/admin-api/projects/{$this->projectUuid}/collections/{$colSlug}/fields", server: $textHeaders, content: '{"name": "Title", "type": "text"}');
        $this->assertSame(415, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_CONTENT_TYPE', $data['code'] ?? null);

        // Create a valid field first
        $this->client->request('POST', "/admin-api/projects/{$this->projectUuid}/collections/{$colSlug}/fields", server: $this->authHeaders(), content: json_encode(['name' => 'Headline', 'type' => 'text']));
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());

        // 6. Field update with text/plain
        $this->client->request('PATCH', "/admin-api/projects/{$this->projectUuid}/collections/{$colSlug}/fields/headline", server: $textHeaders, content: '{"name": "NewHeadline"}');
        $this->assertSame(415, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_CONTENT_TYPE', $data['code'] ?? null);
    }

    public function testPayloadSizeLimitReturns413(): void
    {
        $hugeName = str_repeat('a', 525000);
        $payload = json_encode(['name' => $hugeName]);

        // 1. Project create > 512KB
        $this->client->request('POST', '/admin-api/projects', server: $this->authHeaders(), content: $payload);
        $this->assertSame(413, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('PAYLOAD_TOO_LARGE', $data['code'] ?? null);

        // 2. Collection create > 512KB
        $this->client->request('POST', "/admin-api/projects/{$this->projectUuid}/collections", server: $this->authHeaders(), content: $payload);
        $this->assertSame(413, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('PAYLOAD_TOO_LARGE', $data['code'] ?? null);

        // 3. Field create > 512KB
        $colSlug = 'items_' . bin2hex(random_bytes(3));
        $this->client->request('POST', "/admin-api/projects/{$this->projectUuid}/collections", server: $this->authHeaders(), content: json_encode(['name' => $colSlug]));
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', "/admin-api/projects/{$this->projectUuid}/collections/{$colSlug}/fields", server: $this->authHeaders(), content: $payload);
        $this->assertSame(413, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('PAYLOAD_TOO_LARGE', $data['code'] ?? null);
    }

    public function testCollectionSlugRegexValidation(): void
    {
        // 1. Invalid collection slug in GET
        $this->client->request('GET', "/admin-api/projects/{$this->projectUuid}/collections/bad%20slug!", server: $this->authHeaders());
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_SLUG_FORMAT', $data['code'] ?? null);

        // 2. Invalid collection slug in PATCH
        $this->client->request('PATCH', "/admin-api/projects/{$this->projectUuid}/collections/bad+slug", server: $this->authHeaders(), content: json_encode(['name' => 'Valid']));
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_SLUG_FORMAT', $data['code'] ?? null);

        // 3. Invalid collection slug in DELETE
        $this->client->request('DELETE', "/admin-api/projects/{$this->projectUuid}/collections/bad..slug", server: $this->authHeaders());
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_SLUG_FORMAT', $data['code'] ?? null);

        // 4. Invalid collection slug in export-csv
        $this->client->request('GET', "/admin-api/projects/{$this->projectUuid}/collections/bad%20slug!/export-csv", server: $this->authHeaders());
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_SLUG_FORMAT', $data['code'] ?? null);

        // 5. Valid format but non-existent slug returns 404
        $this->client->request('GET', "/admin-api/projects/{$this->projectUuid}/collections/valid-non-existent-slug", server: $this->authHeaders());
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testFieldSlugRegexValidation(): void
    {
        $colSlug = 'catalog_' . bin2hex(random_bytes(3));
        $this->client->request('POST', "/admin-api/projects/{$this->projectUuid}/collections", server: $this->authHeaders(), content: json_encode(['name' => $colSlug]));
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());

        // 1. Invalid collection slug when listing fields
        $this->client->request('GET', "/admin-api/projects/{$this->projectUuid}/collections/invalid+slug!/fields", server: $this->authHeaders());
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_SLUG_FORMAT', $data['code'] ?? null);

        // 2. Invalid field slug in POST body
        $this->client->request('POST', "/admin-api/projects/{$this->projectUuid}/collections/{$colSlug}/fields", server: $this->authHeaders(), content: json_encode([
            'name' => 'Valid Field',
            'type' => 'text',
            'slug' => 'bad field slug with spaces!',
        ]));
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_SLUG_FORMAT', $data['code'] ?? null);

        // 3. Field slug too long (> 255 chars) in POST body
        $this->client->request('POST', "/admin-api/projects/{$this->projectUuid}/collections/{$colSlug}/fields", server: $this->authHeaders(), content: json_encode([
            'name' => 'Valid Field',
            'type' => 'text',
            'slug' => str_repeat('s', 256),
        ]));
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('SLUG_TOO_LONG', $data['code'] ?? null);

        // 4. Invalid field slug in PATCH URL
        $this->client->request('PATCH', "/admin-api/projects/{$this->projectUuid}/collections/{$colSlug}/fields/bad%20field!", server: $this->authHeaders(), content: json_encode(['name' => 'New']));
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_SLUG_FORMAT', $data['code'] ?? null);

        // 5. Invalid field slug in DELETE URL
        $this->client->request('DELETE', "/admin-api/projects/{$this->projectUuid}/collections/{$colSlug}/fields/bad..slug", server: $this->authHeaders());
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_SLUG_FORMAT', $data['code'] ?? null);
    }

    public function testAdminApiAuditLoggingOnMutations(): void
    {
        $auditRepo = $this->em->getRepository(AuditLog::class);
        $initialCount = count($auditRepo->findAll());

        // 1. Create Collection mutation
        $colSlug = 'audit_col_' . bin2hex(random_bytes(3));
        $this->client->request('POST', "/admin-api/projects/{$this->projectUuid}/collections", server: $this->authHeaders(), content: json_encode(['name' => $colSlug]));
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());

        $createColLog = $auditRepo->findOneBy(['toolName' => 'admin_api_collection_create'], ['id' => 'DESC']);
        $this->assertNotNull($createColLog);
        $this->assertSame('success', $createColLog->status);
        $this->assertSame('admin_api', $createColLog->source);

        // 2. Create Field mutation
        $this->client->request('POST', "/admin-api/projects/{$this->projectUuid}/collections/{$colSlug}/fields", server: $this->authHeaders(), content: json_encode([
            'name' => 'Audit Field',
            'type' => 'text',
        ]));
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());

        $createFieldLog = $auditRepo->findOneBy(['toolName' => 'admin_api_field_create'], ['id' => 'DESC']);
        $this->assertNotNull($createFieldLog);
        $this->assertSame('success', $createFieldLog->status);

        // 3. Update Field mutation
        $this->client->request('PATCH', "/admin-api/projects/{$this->projectUuid}/collections/{$colSlug}/fields/audit_field", server: $this->authHeaders(), content: json_encode([
            'name' => 'Renamed Field',
        ]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $updateFieldLog = $auditRepo->findOneBy(['toolName' => 'admin_api_field_update'], ['id' => 'DESC']);
        $this->assertNotNull($updateFieldLog);
        $this->assertSame('success', $updateFieldLog->status);

        // 4. Delete Field mutation
        $this->client->request('DELETE', "/admin-api/projects/{$this->projectUuid}/collections/{$colSlug}/fields/audit_field", server: $this->authHeaders());
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        $deleteFieldLog = $auditRepo->findOneBy(['toolName' => 'admin_api_field_delete'], ['id' => 'DESC']);
        $this->assertNotNull($deleteFieldLog);
        $this->assertSame('success', $deleteFieldLog->status);

        // 5. Delete Collection mutation
        $this->client->request('DELETE', "/admin-api/projects/{$this->projectUuid}/collections/{$colSlug}", server: $this->authHeaders());
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        $deleteColLog = $auditRepo->findOneBy(['toolName' => 'admin_api_collection_delete'], ['id' => 'DESC']);
        $this->assertNotNull($deleteColLog);
        $this->assertSame('success', $deleteColLog->status);
    }

    public function testI18nCompletenessAcrossFourLocales(): void
    {
        $locales = ['ar', 'en', 'es', 'fr'];
        $keys = [
            'admin_api.invalid_content_type',
            'admin_api.field.slug_too_long',
            'admin_api.field.invalid_slug_format',
            'admin_api.rate_limit_exceeded',
            'admin_api.collection.invalid_slug_format',
            'admin_api.collection.slug_too_long',
            'admin_api.project.invalid_uuid',
            'admin_api.project.payload_too_large',
        ];

        foreach ($locales as $locale) {
            $filePath = __DIR__ . '/../../../translations/messages.' . $locale . '.php';
            $this->assertFileExists($filePath, "Translation file for locale {$locale} must exist.");

            $catalogue = require $filePath;
            $this->assertIsArray($catalogue);

            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $catalogue, "Locale [{$locale}] is missing translation key [{$key}].");
                $this->assertNotEmpty($catalogue[$key], "Translation key [{$key}] in locale [{$locale}] must not be empty.");
            }
        }
    }
}
