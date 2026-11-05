<?php

namespace App\Tests\Controller\AdminApi;

use App\Entity\ApiToken;
use App\Entity\PersonalAccessToken;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Enum\ProjectMemberStatus;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * v1.26.90 — AdminApi Hardening Test Suite:
 * - Strict UUID format validation
 * - Input bounds and length validation
 * - Strict I18N in EN, FR, ES, AR
 * - Translation parity across all languages
 */
class AdminApiHardeningTest extends WebTestCase
{
    private function setupMemberPat(array $scopes, string $locale = 'en'): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->email = 'adminapi-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'hashed_pass_123';
        $user->roles = ['ROLE_SUPER_ADMIN'];
        $user->locale = $locale;
        $em->persist($user);

        $project = new Project();
        $project->name = 'Hardened AdminApi ' . bin2hex(random_bytes(4));
        $em->persist($project);

        $member = new ProjectMember();
        $member->project = $project;
        $member->user = $user;
        $member->email = $user->email;
        $member->status = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $em->persist($member);

        $plain = 'jbo_pat_' . ApiToken::generatePlainToken();
        $pat = new PersonalAccessToken();
        $pat->name = 'test-token';
        $pat->user = $user;
        $pat->scopes = $scopes;
        $pat->tokenHash = ApiToken::hashToken($plain, self::getContainer()->getParameter('kernel.secret'));
        $pat->tokenVersion = 2;
        $em->persist($pat);
        $em->flush();

        return [$plain, $project->uuid->toString(), $user];
    }

    public function testProjectInvalidUuidReturns422(): void
    {
        $client = static::createClient();
        [$plain, $uuid] = $this->setupMemberPat(['projects:write']);
        $h = ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain, 'CONTENT_TYPE' => 'application/json'];

        $client->request('GET', '/admin-api/projects/not-a-valid-uuid', server: $h);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('Invalid project UUID format.', $data['error']);

        $client->request('PATCH', '/admin-api/projects/not-a-valid-uuid', server: $h, content: json_encode(['name' => 'New']));
        self::assertSame(422, $client->getResponse()->getStatusCode());

        $client->request('DELETE', '/admin-api/projects/not-a-valid-uuid', server: $h);
        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testProjectCreateNameRequiredAndLengthLimits(): void
    {
        $client = static::createClient();
        [$plain] = $this->setupMemberPat(['projects:write']);
        $h = ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain, 'CONTENT_TYPE' => 'application/json'];

        // Missing/empty name
        $client->request('POST', '/admin-api/projects', server: $h, content: json_encode(['name' => '   ']));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('Project name is required.', $data['error']);

        // Name too long (> 255)
        $client->request('POST', '/admin-api/projects', server: $h, content: json_encode(['name' => str_repeat('p', 256)]));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('Project name cannot exceed 255 characters.', $data['error']);
    }

    public function testCollectionInvalidUuidReturns404(): void
    {
        $client = static::createClient();
        [$plain] = $this->setupMemberPat(['schema:write']);
        $h = ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain, 'CONTENT_TYPE' => 'application/json'];

        $client->request('GET', '/admin-api/projects/invalid-uuid/collections', server: $h);
        self::assertSame(404, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('Project not found.', $data['error']);
    }

    public function testCollectionCreateNameRequiredAndLengthLimits(): void
    {
        $client = static::createClient();
        [$plain, $uuid] = $this->setupMemberPat(['schema:write']);
        $h = ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain, 'CONTENT_TYPE' => 'application/json'];

        // Empty name
        $client->request('POST', "/admin-api/projects/$uuid/collections", server: $h, content: json_encode(['name' => '']));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('Collection name is required.', $data['error']);

        // Name too long
        $client->request('POST', "/admin-api/projects/$uuid/collections", server: $h, content: json_encode(['name' => str_repeat('c', 256)]));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('Collection name cannot exceed 255 characters.', $data['error']);

        // Slug too long
        $client->request('POST', "/admin-api/projects/$uuid/collections", server: $h, content: json_encode(['name' => 'Valid', 'slug' => str_repeat('s', 256)]));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('Collection slug cannot exceed 255 characters.', $data['error']);

        // Invalid slug format
        $client->request('POST', "/admin-api/projects/$uuid/collections", server: $h, content: json_encode(['name' => 'Valid', 'slug' => 'invalid slug with spaces!']));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('Collection slug can only contain letters, numbers, underscores and hyphens.', $data['error']);
    }

    public function testFieldCreateNameRequiredAndLengthLimits(): void
    {
        $client = static::createClient();
        [$plain, $uuid] = $this->setupMemberPat(['schema:write']);
        $h = ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain, 'CONTENT_TYPE' => 'application/json'];

        // Create collection first
        $colSlug = 'posts_' . bin2hex(random_bytes(3));
        $client->request('POST', "/admin-api/projects/$uuid/collections", server: $h, content: json_encode(['name' => $colSlug]));
        self::assertSame(201, $client->getResponse()->getStatusCode());

        // Field empty name
        $client->request('POST', "/admin-api/projects/$uuid/collections/$colSlug/fields", server: $h, content: json_encode(['name' => '']));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('Field name is required.', $data['error']);

        // Field name too long
        $client->request('POST', "/admin-api/projects/$uuid/collections/$colSlug/fields", server: $h, content: json_encode(['name' => str_repeat('f', 256), 'type' => 'text']));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('Field name cannot exceed 255 characters.', $data['error']);
    }

    public function testImportCsvInvalidUuidReturns422(): void
    {
        $client = static::createClient();
        [$plain] = $this->setupMemberPat(['content:write']);
        $h = ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain, 'CONTENT_TYPE' => 'application/json'];

        $client->request('POST', '/admin-api/projects/invalid-uuid/collections/posts/import-csv', server: $h, content: json_encode([]));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('Invalid project UUID format.', $data['error']);
    }

    public function testImportCsvMissingRequiredFieldsReturns422(): void
    {
        $client = static::createClient();
        [$plain, $uuid] = $this->setupMemberPat(['schema:write', 'content:write']);
        $h = ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain, 'CONTENT_TYPE' => 'application/json'];

        $colSlug = 'import_' . bin2hex(random_bytes(3));
        $client->request('POST', "/admin-api/projects/$uuid/collections", server: $h, content: json_encode(['name' => $colSlug]));
        self::assertSame(201, $client->getResponse()->getStatusCode());

        $client->request('POST', "/admin-api/projects/$uuid/collections/$colSlug/import-csv", server: $h, content: json_encode([]));
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('Missing required fields: data[], field_mapping{}.', $data['error']);
    }

    public function testAdminApiLocalizationFrSupport(): void
    {
        $client = static::createClient();
        [$plain, $uuid, $user] = $this->setupMemberPat(['projects:write'], 'fr');
        $h = ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain, 'CONTENT_TYPE' => 'application/json'];

        $client->request('GET', '/admin-api/projects/not-a-valid-uuid', server: $h);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('Format UUID de projet invalide.', $data['error']);
    }

    public function testAdminApiLocalizationEsSupport(): void
    {
        $client = static::createClient();
        [$plain, $uuid, $user] = $this->setupMemberPat(['projects:write'], 'es');
        $h = ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain, 'CONTENT_TYPE' => 'application/json'];

        $client->request('GET', '/admin-api/projects/not-a-valid-uuid', server: $h);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('Formato UUID de proyecto inválido.', $data['error']);
    }

    public function testAdminApiLocalizationArSupport(): void
    {
        $client = static::createClient();
        [$plain, $uuid, $user] = $this->setupMemberPat(['projects:write'], 'ar');
        $h = ['HTTP_AUTHORIZATION' => 'Bearer ' . $plain, 'CONTENT_TYPE' => 'application/json'];

        $client->request('GET', '/admin-api/projects/not-a-valid-uuid', server: $h);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('تنسيق المعرف الفريد للمشروع غير صالح.', $data['error']);
    }

    public function testAdminApiTranslationParity(): void
    {
        $en = require __DIR__ . '/../../../translations/messages.en.php';
        $fr = require __DIR__ . '/../../../translations/messages.fr.php';
        $es = require __DIR__ . '/../../../translations/messages.es.php';
        $ar = require __DIR__ . '/../../../translations/messages.ar.php';

        $keys = [
            'admin_api.project.invalid_uuid',
            'admin_api.project.invalid_json',
            'admin_api.project.not_found',
            'admin_api.project.name_required',
            'admin_api.project.name_too_long',
            'admin_api.collection.project_not_found',
            'admin_api.collection.not_found',
            'admin_api.collection.invalid_json',
            'admin_api.collection.name_required',
            'admin_api.collection.name_too_long',
            'admin_api.collection.slug_too_long',
            'admin_api.collection.invalid_slug_format',
            'admin_api.field.project_not_found',
            'admin_api.field.collection_not_found',
            'admin_api.field.not_found',
            'admin_api.field.invalid_json',
            'admin_api.field.name_required',
            'admin_api.field.name_too_long',
            'admin_api.import.invalid_uuid',
            'admin_api.import.too_many_requests',
            'admin_api.import.payload_too_large',
            'admin_api.import.project_not_found',
            'admin_api.import.collection_not_found',
            'admin_api.import.upload_error',
            'admin_api.import.file_too_large',
            'admin_api.import.invalid_json',
            'admin_api.import.missing_required_fields',
            'admin_api.import.rows_limit_exceeded',
            'admin_api.import.columns_limit_exceeded',
            'admin_api.import.invalid_mapping_structure',
            'admin_api.import.target_column_not_found',
            'admin_api.import.invalid_row_format',
            'admin_api.import.excessive_columns_in_row',
            'admin_api.import.cell_too_large',
            'admin_api.import.csv_read_error',
            'admin_api.import.csv_headers_invalid',
            'admin_api.import.csv_too_many_columns',
            'admin_api.import.invalid_column_mapping',
            'admin_api.import.no_matching_columns',
            'admin_api.import.max_rows_reached',
            'admin_api.import.cell_count_mismatch',
        ];

        foreach ($keys as $k) {
            self::assertArrayHasKey($k, $en, "Missing $k in EN");
            self::assertArrayHasKey($k, $fr, "Missing $k in FR");
            self::assertArrayHasKey($k, $es, "Missing $k in ES");
            self::assertArrayHasKey($k, $ar, "Missing $k in AR");
            self::assertNotEmpty($en[$k]);
            self::assertNotEmpty($fr[$k]);
            self::assertNotEmpty($es[$k]);
            self::assertNotEmpty($ar[$k]);
        }
    }
}
