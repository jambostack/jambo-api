<?php

namespace App\Tests\Controller;

use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\ProjectTemplate;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use App\Service\TusServer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class Cycle38HardeningTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $admin;
    private User $regularUser;
    private Project $project;
    private string $projectUuid;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(4));

        $this->admin = new User();
        $this->admin->email = 'admin-c38-' . $suffix . '@example.com';
        $this->admin->password = 'hashed';
        $this->admin->roles = ['ROLE_SUPER_ADMIN'];
        $this->em->persist($this->admin);

        $this->regularUser = new User();
        $this->regularUser->email = 'user-c38-' . $suffix . '@example.com';
        $this->regularUser->password = 'hashed';
        $this->regularUser->roles = ['ROLE_USER'];
        $this->em->persist($this->regularUser);

        $this->project = new Project();
        $this->project->name = 'Cycle 38 Project ' . $suffix;
        $this->project->defaultLocale = 'en';
        $this->project->locales = ['en', 'fr'];
        $this->em->persist($this->project);

        $member = new ProjectMember();
        $member->project = $this->project;
        $member->user = $this->admin;
        $member->email = $this->admin->email;
        $member->status = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $this->em->persist($member);

        $this->em->flush();
        $this->projectUuid = $this->project->uuid->toString();
    }

    // ─── TUS Upload Tests ──────────────────────────────────────────────────

    public function testTusOptionsReturnsCapabilities(): void
    {
        $this->client->request('OPTIONS', '/api/projects/' . $this->projectUuid . '/files/tus');
        $res = $this->client->getResponse();

        $this->assertSame(204, $res->getStatusCode());
        $this->assertSame('1.0.0', $res->headers->get('Tus-Resumable'));
        $this->assertSame('1.0.0', $res->headers->get('Tus-Version'));
        $this->assertSame((string) (100 * 1024 * 1024), $res->headers->get('Tus-Max-Size'));
        $this->assertTrue($res->headers->has('Tus-Extension'));
    }

    public function testTusCreateValidationAndMaxLimit(): void
    {
        $this->client->loginUser($this->admin);

        // Missing Tus-Resumable
        $this->client->request('POST', '/api/projects/' . $this->projectUuid . '/files/tus', [], [], [
            'HTTP_UPLOAD_LENGTH' => '1024',
        ]);
        $this->assertSame(412, $this->client->getResponse()->getStatusCode());

        // Missing Upload-Length
        $this->client->request('POST', '/api/projects/' . $this->projectUuid . '/files/tus', [], [], [
            'HTTP_TUS_RESUMABLE' => '1.0.0',
        ]);
        $this->assertSame(400, $this->client->getResponse()->getStatusCode());

        // Invalid negative Upload-Length
        $this->client->request('POST', '/api/projects/' . $this->projectUuid . '/files/tus', [], [], [
            'HTTP_TUS_RESUMABLE' => '1.0.0',
            'HTTP_UPLOAD_LENGTH' => '-50',
        ]);
        $this->assertSame(400, $this->client->getResponse()->getStatusCode());

        // Upload-Length exceeds 100MB
        $this->client->request('POST', '/api/projects/' . $this->projectUuid . '/files/tus', [], [], [
            'HTTP_TUS_RESUMABLE' => '1.0.0',
            'HTTP_UPLOAD_LENGTH' => (string) (101 * 1024 * 1024),
        ]);
        $this->assertSame(413, $this->client->getResponse()->getStatusCode());

        // Forbidden file extension in metadata
        $badMeta = 'filename ' . base64_encode('malicious.php');
        $this->client->request('POST', '/api/projects/' . $this->projectUuid . '/files/tus', [], [], [
            'HTTP_TUS_RESUMABLE'  => '1.0.0',
            'HTTP_UPLOAD_LENGTH'  => '500',
            'HTTP_UPLOAD_METADATA' => $badMeta,
        ]);
        $this->assertSame(400, $this->client->getResponse()->getStatusCode());

        // Valid creation
        $validMeta = 'filename ' . base64_encode('document.pdf');
        $this->client->request('POST', '/api/projects/' . $this->projectUuid . '/files/tus', [], [], [
            'HTTP_TUS_RESUMABLE'  => '1.0.0',
            'HTTP_UPLOAD_LENGTH'  => '2048',
            'HTTP_UPLOAD_METADATA' => $validMeta,
        ]);
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $this->assertTrue($this->client->getResponse()->headers->has('Location'));
    }

    public function testTusHeadAndCancelLifecycle(): void
    {
        $this->client->loginUser($this->admin);

        $tusServer = self::getContainer()->get(TusServer::class);
        $uploadId = $tusServer->create($this->projectUuid, 500, ['filename' => 'sample.txt']);

        // HEAD existing upload
        $this->client->request('HEAD', '/api/projects/' . $this->projectUuid . '/files/tus/' . $uploadId);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertSame('0', $this->client->getResponse()->headers->get('Upload-Offset'));
        $this->assertSame('500', $this->client->getResponse()->headers->get('Upload-Length'));

        // DELETE (cancel) upload
        $this->client->request('DELETE', '/api/projects/' . $this->projectUuid . '/files/tus/' . $uploadId);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // HEAD after cancel returns 404
        $this->client->request('HEAD', '/api/projects/' . $this->projectUuid . '/files/tus/' . $uploadId);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    // ─── Collection Template Tests ─────────────────────────────────────────

    public function testCollectionTemplateCrudAndValidation(): void
    {
        // 1. Unauthenticated index is allowed
        $this->client->request('GET', '/api/collection-templates');
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        // 2. Unauthenticated create rejected
        $this->client->request('POST', '/api/collection-templates', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Blog Posts',
        ]));
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());

        // 3. Regular user create rejected (requires ROLE_ADMIN)
        $this->client->loginUser($this->regularUser);
        $this->client->request('POST', '/api/collection-templates', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Blog Posts',
        ]));
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        // 4. Admin create with invalid name
        $this->client->loginUser($this->admin);
        $this->client->request('POST', '/api/collection-templates', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => '',
        ]));
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());

        // 5. Admin create with valid fields
        $this->client->request('POST', '/api/collection-templates', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'name' => 'Blog Template',
            'description' => 'A template for blog articles',
            'fields' => [
                ['name' => 'Title', 'slug' => 'title', 'type' => 'text', 'is_required' => true],
                ['name' => 'Body', 'slug' => 'body', 'type' => 'richtext', 'is_required' => false],
            ],
        ]));
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $created = json_decode((string) $this->client->getResponse()->getContent(), true);
        $templateId = $created['id'];
        $this->assertSame('Blog Template', $created['name']);
        $this->assertCount(2, $created['fields']);

        // 6. Show template
        $this->client->request('GET', '/api/collection-templates/' . $templateId);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $showData = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('Blog Template', $showData['name']);

        // 7. Regular user cannot delete
        $this->client->loginUser($this->regularUser);
        $this->client->request('DELETE', '/api/collection-templates/' . $templateId);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());

        // 8. Super admin deletes template
        $this->client->loginUser($this->admin);
        $this->client->request('DELETE', '/api/collection-templates/' . $templateId);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // 9. Show deleted returns 404
        $this->client->request('GET', '/api/collection-templates/' . $templateId);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testSaveFromCollection(): void
    {
        $this->client->loginUser($this->admin);

        // Create a collection in the project
        $collection = new Collection();
        $collection->name = 'Products';
        $collection->slug = 'products';
        $collection->project = $this->project;
        $this->em->persist($collection);

        $field = new Field();
        $field->name = 'Price';
        $field->slug = 'price';
        $field->type = 'number';
        $field->collection = $collection;
        $collection->fields->add($field);
        $this->em->persist($field);

        $this->em->flush();
        $this->em->clear();

        // Save as template
        $this->client->request(
            'POST',
            '/api/collection-templates/from-collection/' . $this->projectUuid . '/products',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Product Catalog Template', 'description' => 'Reusable catalog'])
        );
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('Product Catalog Template', $data['name']);
        $this->assertCount(1, $data['fields']);
    }

    // ─── Project Template Tests ───────────────────────────────────────────

    public function testProjectTemplateLifecycleAndApply(): void
    {
        $this->client->loginUser($this->admin);

        // 1. Export project to template
        $this->client->request(
            'POST',
            '/api/project-templates/from-project/' . $this->projectUuid,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'SaaS Starter Template', 'description' => 'Standard project structure'])
        );
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $exportData = json_decode((string) $this->client->getResponse()->getContent(), true);
        $templateId = $exportData['id'];

        // 2. Show template
        $this->client->request('GET', '/api/project-templates/' . $templateId);
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $showData = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('SaaS Starter Template', $showData['name']);
        $this->assertArrayHasKey('structure', $showData);

        // 3. Apply template to create a new project
        $this->client->request(
            'POST',
            '/api/project-templates/' . $templateId . '/apply',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Brand New SaaS App'])
        );
        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $applied = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('Brand New SaaS App', $applied['name']);
        $this->assertNotEmpty($applied['uuid']);

        // 4. Delete project template
        $this->client->request('DELETE', '/api/project-templates/' . $templateId);
        $this->assertSame(204, $this->client->getResponse()->getStatusCode());

        // 5. Subsequent show returns 404
        $this->client->request('GET', '/api/project-templates/' . $templateId);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    // ─── Translation Completeness Tests ───────────────────────────────────

    public function testI18nKeysCompleteness(): void
    {
        $locales = ['en', 'fr', 'es', 'ar'];
        $keysToCheck = [
            'tus.project_not_found',
            'tus.auth_required',
            'tus.access_denied',
            'tus.permission_denied',
            'tus.missing_upload_length',
            'tus.invalid_upload_length',
            'tus.upload_length_exceeds_max',
            'tus.invalid_content_type',
            'tus.upload_not_found',
            'tus.missing_upload_offset',
            'tus.invalid_upload_offset',
            'tus.upload_already_finalized',
            'tus.upload_finalizing_in_progress',
            'tus.upload_incomplete',
            'tus.upload_file_missing',
            'tus.upload_size_mismatch',
            'tus.upload_busy',
            'tus.invalid_metadata',
            'template.not_found',
            'template.auth_required',
            'template.invalid_json',
            'template.name_required',
            'template.name_too_long',
            'template.description_too_long',
            'template.fields_must_be_array',
            'template.max_fields_exceeded',
            'template.project_not_found',
            'template.collection_not_found',
            'template.deleted_successfully',
        ];

        foreach ($locales as $locale) {
            $path = __DIR__ . '/../../translations/messages.' . $locale . '.php';
            $this->assertFileExists($path);
            $translations = require $path;
            $this->assertIsArray($translations);

            foreach ($keysToCheck as $key) {
                $this->assertArrayHasKey($key, $translations, sprintf('Missing key "%s" in locale "%s"', $key, $locale));
                $this->assertNotEmpty($translations[$key], sprintf('Empty key "%s" in locale "%s"', $key, $locale));
            }
        }
    }
}
