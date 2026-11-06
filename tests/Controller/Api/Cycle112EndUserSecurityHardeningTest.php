<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\AuditLog;
use App\Entity\EndUser;
use App\Entity\EndUserField;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class Cycle112EndUserSecurityHardeningTest extends WebTestCase
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
        $this->admin->name = 'Cycle112 SuperAdmin';
        $this->admin->email = "superadmin_{$suffix}@test.com";
        $this->admin->password = $hasher->hashPassword($this->admin, 'SuperSecret123!');
        $this->admin->roles = ['ROLE_SUPER_ADMIN'];
        $this->em->persist($this->admin);

        $this->project = new Project();
        $this->project->name = "Project Cycle 112 {$suffix}";
        $this->em->persist($this->project);
        $this->em->flush();
    }

    private function projectUrl(string $path = ''): string
    {
        return '/api/projects/' . $this->project->uuid->toString() . $path;
    }

    public function testEndUserAdminContentTypeValidation(): void
    {
        $this->client->loginUser($this->admin);
        $baseUrl = $this->projectUrl('/end-users');

        // 1. POST /end-users with text/plain
        $this->client->request('POST', $baseUrl, [], [], ['CONTENT_TYPE' => 'text/plain'], 'email=bad@example.com');
        $this->assertSame(415, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_CONTENT_TYPE', $data['code'] ?? null);

        // Create a real end user for PATCH testing
        $em = $this->client->getContainer()->get(EntityManagerInterface::class);
        $project = $em->find(Project::class, $this->project->id);
        $endUser = new EndUser($project, 'patch_target_' . bin2hex(random_bytes(3)) . '@test.com');
        $endUser->password = 'hashed';
        $em->persist($endUser);
        $em->flush();

        // 2. PATCH /end-users/{uuid} with text/plain
        $this->client->request('PATCH', $baseUrl . '/' . $endUser->uuid->toString(), [], [], ['CONTENT_TYPE' => 'text/plain'], 'status=banned');
        $this->assertSame(415, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_CONTENT_TYPE', $data['code'] ?? null);

        // 3. PATCH /end-users/{uuid}/status with text/plain
        $this->client->request('PATCH', $baseUrl . '/' . $endUser->uuid->toString() . '/status', [], [], ['CONTENT_TYPE' => 'text/plain'], 'status=banned');
        $this->assertSame(415, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_CONTENT_TYPE', $data['code'] ?? null);
    }

    public function testEndUserAdminPayloadSizeLimit(): void
    {
        $this->client->loginUser($this->admin);
        $baseUrl = $this->projectUrl('/end-users');

        // Giant payload exceeding 64KB
        $giantPayload = json_encode([
            'email'    => 'oversized@test.com',
            'password' => 'ValidPass123!',
            'data'     => str_repeat('A', 70000),
        ]);

        $this->client->request('POST', $baseUrl, [], [], ['CONTENT_TYPE' => 'application/json'], $giantPayload);
        $this->assertSame(413, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('PAYLOAD_TOO_LARGE', $data['code'] ?? null);
    }

    public function testEndUserAdminSecurityHeaders(): void
    {
        $this->client->loginUser($this->admin);
        $baseUrl = $this->projectUrl('/end-users');

        $this->client->request('GET', $baseUrl);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $headers = $this->client->getResponse()->headers;
        $this->assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $headers->get('X-Frame-Options'));
        $this->assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
    }

    public function testEndUserAdminInputValidation(): void
    {
        $this->client->loginUser($this->admin);
        $baseUrl = $this->projectUrl('/end-users');

        // 1. Password too short (< 6)
        $this->client->request('POST', $baseUrl, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email'    => 'valid@test.com',
            'password' => '12345',
        ]));
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());

        // 2. Invalid status
        $this->client->request('POST', $baseUrl, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email'    => 'valid@test.com',
            'password' => 'Valid123!',
            'status'   => 'super_admin_status',
        ]));
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());

        // 3. Username with control characters
        $this->client->request('POST', $baseUrl, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email'    => 'valid@test.com',
            'password' => 'Valid123!',
            'username' => "hacker\x00user",
        ]));
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());

        // 4. Email with control characters
        $this->client->request('POST', $baseUrl, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email'    => "bad\x07email@test.com",
            'password' => 'Valid123!',
        ]));
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testEndUserAdminLifecycleAndAuditLogging(): void
    {
        $this->client->loginUser($this->admin);
        $baseUrl = $this->projectUrl('/end-users');
        $email = 'lifecycle_' . bin2hex(random_bytes(4)) . '@test.com';

        // 1. CREATE
        $this->client->request('POST', $baseUrl, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email'    => $email,
            'password' => 'SecurePass123!',
            'username' => 'LifecycleUser',
            'status'   => 'active',
        ]));
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $createData = json_decode((string) $this->client->getResponse()->getContent(), true);
        $userUuid = $createData['data']['uuid'] ?? null;
        $this->assertNotNull($userUuid);

        // Verify audit log for create
        $logCreate = $this->em->getRepository(AuditLog::class)->findOneBy(['toolName' => 'admin_enduser_create'], ['id' => 'DESC']);
        $this->assertNotNull($logCreate);
        $this->assertSame('success', $logCreate->status);
        $this->assertSame($email, $logCreate->input['email'] ?? null);

        // 2. UPDATE
        $this->client->request('PATCH', $baseUrl . '/' . $userUuid, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'username' => 'UpdatedUsername',
        ]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        // Verify audit log for update
        $logUpdate = $this->em->getRepository(AuditLog::class)->findOneBy(['toolName' => 'admin_enduser_update'], ['id' => 'DESC']);
        $this->assertNotNull($logUpdate);
        $this->assertSame('success', $logUpdate->status);
        $this->assertSame($userUuid, $logUpdate->input['end_user_uuid'] ?? null);

        // 3. STATUS CHANGE (BAN)
        $this->client->request('PATCH', $baseUrl . '/' . $userUuid . '/status', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'status' => 'banned',
        ]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        // Verify audit log for status change
        $logStatus = $this->em->getRepository(AuditLog::class)->findOneBy(['toolName' => 'admin_enduser_status_change'], ['id' => 'DESC']);
        $this->assertNotNull($logStatus);
        $this->assertSame('success', $logStatus->status);
        $this->assertSame('banned', $logStatus->input['new_status'] ?? null);

        // 4. DELETE
        $this->client->request('DELETE', $baseUrl . '/' . $userUuid);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        // Verify audit log for delete
        $logDelete = $this->em->getRepository(AuditLog::class)->findOneBy(['toolName' => 'admin_enduser_delete'], ['id' => 'DESC']);
        $this->assertNotNull($logDelete);
        $this->assertSame('success', $logDelete->status);
        $this->assertSame($userUuid, $logDelete->input['end_user_uuid'] ?? null);
    }

    public function testEndUserSchemaContentTypeValidation(): void
    {
        $this->client->loginUser($this->admin);
        $baseUrl = $this->projectUrl('/end-users/fields');

        // 1. POST /fields with text/plain
        $this->client->request('POST', $baseUrl, [], [], ['CONTENT_TYPE' => 'text/plain'], 'name=Bio&type=text');
        $this->assertSame(415, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_CONTENT_TYPE', $data['code'] ?? null);

        // 2. POST /fields/reorder with text/plain
        $this->client->request('POST', $baseUrl . '/reorder', [], [], ['CONTENT_TYPE' => 'text/plain'], 'fields=[{"id":1,"order":2}]');
        $this->assertSame(415, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_CONTENT_TYPE', $data['code'] ?? null);

        // Create a custom field for PATCH test
        $em = $this->client->getContainer()->get(EntityManagerInterface::class);
        $project = $em->find(Project::class, $this->project->id);
        $field = new EndUserField();
        $field->project = $project;
        $field->name = 'testField';
        $field->slug = 'test_field';
        $field->type = 'text';
        $field->order = 1;
        $field->isSystem = false;
        $em->persist($field);
        $em->flush();

        // 3. PATCH /fields/{slug} with text/plain
        $this->client->request('PATCH', $baseUrl . '/' . $field->slug, [], [], ['CONTENT_TYPE' => 'text/plain'], 'name=UpdatedField');
        $this->assertSame(415, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_CONTENT_TYPE', $data['code'] ?? null);
    }

    public function testEndUserSchemaPayloadSizeLimit(): void
    {
        $this->client->loginUser($this->admin);
        $baseUrl = $this->projectUrl('/end-users/fields');

        // Giant payload exceeding 64KB
        $giantPayload = json_encode([
            'name' => 'OversizedField',
            'type' => 'text',
            'junk' => str_repeat('B', 70000),
        ]);

        $this->client->request('POST', $baseUrl, [], [], ['CONTENT_TYPE' => 'application/json'], $giantPayload);
        $this->assertSame(413, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('PAYLOAD_TOO_LARGE', $data['code'] ?? null);
    }

    public function testEndUserSchemaSecurityHeaders(): void
    {
        $this->client->loginUser($this->admin);
        $baseUrl = $this->projectUrl('/end-users/fields');

        $this->client->request('GET', $baseUrl);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $headers = $this->client->getResponse()->headers;
        $this->assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $headers->get('X-Frame-Options'));
        $this->assertStringContainsString('no-store', (string) $headers->get('Cache-Control'));
    }

    public function testEndUserSchemaLifecycleAndAuditLogging(): void
    {
        $this->client->loginUser($this->admin);
        $baseUrl = $this->projectUrl('/end-users/fields');
        $fieldName = 'Website URL ' . bin2hex(random_bytes(3));

        // 1. CREATE FIELD
        $this->client->request('POST', $baseUrl, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name'        => $fieldName,
            'type'        => 'url',
            'is_required' => false,
        ]));
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $createData = json_decode((string) $this->client->getResponse()->getContent(), true);
        $fieldSlug = $createData['data']['slug'] ?? null;
        $fieldId = $createData['data']['id'] ?? null;
        $this->assertNotNull($fieldSlug);
        $this->assertNotNull($fieldId);

        // Verify audit log for field create
        $logCreate = $this->em->getRepository(AuditLog::class)->findOneBy(['toolName' => 'admin_enduser_field_create'], ['id' => 'DESC']);
        $this->assertNotNull($logCreate);
        $this->assertSame('success', $logCreate->status);
        $this->assertSame($fieldSlug, $logCreate->input['slug'] ?? null);

        // 2. UPDATE FIELD
        $this->client->request('PATCH', $baseUrl . '/' . $fieldSlug, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'is_required' => true,
        ]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        // Verify audit log for field update
        $logUpdate = $this->em->getRepository(AuditLog::class)->findOneBy(['toolName' => 'admin_enduser_field_update'], ['id' => 'DESC']);
        $this->assertNotNull($logUpdate);
        $this->assertSame('success', $logUpdate->status);
        $this->assertSame($fieldSlug, $logUpdate->input['slug'] ?? null);

        // 3. REORDER FIELDS
        $this->client->request('POST', $baseUrl . '/reorder', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'fields' => [
                ['id' => $fieldId, 'order' => 10],
            ],
        ]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        // Verify audit log for reorder
        $logReorder = $this->em->getRepository(AuditLog::class)->findOneBy(['toolName' => 'admin_enduser_fields_reorder'], ['id' => 'DESC']);
        $this->assertNotNull($logReorder);
        $this->assertSame('success', $logReorder->status);

        // 4. DELETE FIELD
        $this->client->request('DELETE', $baseUrl . '/' . $fieldSlug);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // Verify audit log for delete
        $logDelete = $this->em->getRepository(AuditLog::class)->findOneBy(['toolName' => 'admin_enduser_field_delete'], ['id' => 'DESC']);
        $this->assertNotNull($logDelete);
        $this->assertSame('success', $logDelete->status);
        $this->assertSame($fieldSlug, $logDelete->input['slug'] ?? null);
    }

    public function testTranslationsCompleteAcrossAllFourLocales(): void
    {
        $en = require __DIR__ . '/../../../translations/messages.en.php';
        $fr = require __DIR__ . '/../../../translations/messages.fr.php';
        $es = require __DIR__ . '/../../../translations/messages.es.php';
        $ar = require __DIR__ . '/../../../translations/messages.ar.php';

        $keysToCheck = [
            'enduser.admin.not_found',
            'enduser.admin.invalid_json',
            'enduser.admin.invalid_content_type',
            'enduser.admin.payload_too_large',
            'enduser.admin.rate_limit_exceeded',
            'enduser.admin.deleted_success',
            'enduser.admin.email_too_long',
            'enduser.admin.valid_email_required',
            'enduser.admin.password_min_length',
            'enduser.admin.invalid_status',
            'enduser.admin.invalid_username',
            'enduser.admin.email_already_registered',
            'enduser.admin.email_already_taken',
            'enduser.admin.invalid_email',
            'enduser.schema.invalid_json',
            'enduser.schema.invalid_content_type',
            'enduser.schema.payload_too_large',
            'enduser.schema.rate_limit_exceeded',
            'enduser.schema.deleted_success',
            'enduser.schema.fields_must_be_array',
            'enduser.schema.name_and_type_required',
            'enduser.schema.name_too_long',
            'enduser.schema.field_not_found',
            'enduser.schema.system_field_immutable',
            'enduser.schema.system_field_cannot_delete',
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
