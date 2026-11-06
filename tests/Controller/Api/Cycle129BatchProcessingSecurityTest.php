<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Field;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\Role;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use App\Message\BatchImportMessage;
use App\MessageHandler\BatchImportMessageHandler;
use App\Repository\CollectionRepository;
use App\Repository\ProjectRepository;
use App\Service\ImportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

class Cycle129BatchProcessingSecurityTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Project $project;
    private Project $otherProject;
    private Collection $collection;
    private Collection $otherCollection;
    private User $adminUser;
    private User $memberUser;
    private Field $titleField;
    private Field $priceField;
    private Field $activeField;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get(EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(4));

        // 1. Create Admin User
        $this->adminUser = new User();
        $this->adminUser->email = "c129_admin_{$suffix}@example.com";
        $this->adminUser->password = 'hashed_pass_123';
        $this->adminUser->roles = ['ROLE_SUPER_ADMIN'];
        $this->em->persist($this->adminUser);

        // 2. Create Project
        $this->project = new Project();
        $this->project->name = "Batch Project {$suffix}";
        $this->project->defaultLocale = 'en';
        $this->project->locales = ['en', 'fr'];
        $this->em->persist($this->project);

        // 3. Create Other Project for tenant isolation tests
        $this->otherProject = new Project();
        $this->otherProject->name = "Other Project {$suffix}";
        $this->otherProject->defaultLocale = 'en';
        $this->otherProject->locales = ['en'];
        $this->em->persist($this->otherProject);

        // 4. Create ProjectMember for Admin
        $member = new ProjectMember();
        $member->project = $this->project;
        $member->user = $this->adminUser;
        $member->email = $this->adminUser->email;
        $member->status = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $this->em->persist($member);

        // 5. Create Collection on main project
        $this->collection = new Collection();
        $this->collection->project = $this->project;
        $this->collection->name = 'Products';
        $this->collection->slug = 'products';
        $this->em->persist($this->collection);

        // 6. Create Fields on Collection
        $this->titleField = new Field();
        $this->titleField->collection = $this->collection;
        $this->titleField->name = 'Title';
        $this->titleField->slug = 'title';
        $this->titleField->type = 'text';
        $this->em->persist($this->titleField);

        $this->priceField = new Field();
        $this->priceField->collection = $this->collection;
        $this->priceField->name = 'Price';
        $this->priceField->slug = 'price';
        $this->priceField->type = 'number';
        $this->em->persist($this->priceField);

        $this->activeField = new Field();
        $this->activeField->collection = $this->collection;
        $this->activeField->name = 'Active';
        $this->activeField->slug = 'active';
        $this->activeField->type = 'boolean';
        $this->em->persist($this->activeField);

        $this->collection->fields->add($this->titleField);
        $this->collection->fields->add($this->priceField);
        $this->collection->fields->add($this->activeField);

        // 7. Create Other Collection on other project
        $this->otherCollection = new Collection();
        $this->otherCollection->project = $this->otherProject;
        $this->otherCollection->name = 'Articles';
        $this->otherCollection->slug = 'articles';
        $this->em->persist($this->otherCollection);

        $this->em->flush();
    }

    public function testUnauthenticatedAccessDenied(): void
    {
        $this->client->request('POST', sprintf('/api/projects/%s/batch/products/import', $this->project->uuid->toString()), [], [], [
            'CONTENT_TYPE' => 'text/csv',
        ], "title,price\nItem 1,10.5");

        $this->assertContains($this->client->getResponse()->getStatusCode(), [401, 403]);
    }

    public function testProjectNotFoundReturns404(): void
    {
        $this->client->loginUser($this->adminUser);
        $randomUuid = Uuid::v4()->toString();

        $this->client->request('POST', sprintf('/api/projects/%s/batch/products/import', $randomUuid), [], [], [
            'CONTENT_TYPE' => 'text/csv',
        ], "title,price\nItem 1,10.5");

        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testCollectionBelongingToOtherProjectReturns404TenantMismatch(): void
    {
        $this->client->loginUser($this->adminUser);

        // Attempting to import into 'articles' (belongs to otherProject) using this->project UUID
        $this->client->request('POST', sprintf('/api/projects/%s/batch/articles/import', $this->project->uuid->toString()), [], [], [
            'CONTENT_TYPE' => 'text/csv',
        ], "title,price\nItem 1,10.5");

        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('COLLECTION_NOT_FOUND', $data['code']);
    }

    public function testInvalidSlugFormatReturns422(): void
    {
        $this->client->loginUser($this->adminUser);

        $this->client->request('POST', sprintf('/api/projects/%s/batch/invalid--slug$$/import', $this->project->uuid->toString()), [], [], [
            'CONTENT_TYPE' => 'text/csv',
        ], "title\nItem");

        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('INVALID_SLUG_FORMAT', $data['code']);
    }

    public function testSynchronousCsvImportWithFormulaSanitization(): void
    {
        $this->client->loginUser($this->adminUser);

        $csvPayload = "title,price,active\n=SUM(A1:A10),49.99,1\nSafe Phone,199.00,0";

        $this->client->request('POST', sprintf('/api/projects/%s/batch/products/import', $this->project->uuid->toString()), [], [], [
            'CONTENT_TYPE' => 'text/csv',
        ], $csvPayload);

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $this->assertTrue($response->headers->has('X-Content-Type-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertSame('completed', $data['data']['status']);
        $this->assertSame(2, $data['data']['created']);
        $this->assertEmpty($data['data']['errors']);

        // Verify entries in database and formula sanitization
        $this->em->clear();
        $entries = $this->em->getRepository(ContentEntry::class)->findBy(['collection' => $this->collection]);
        $this->assertCount(2, $entries);

        $formulaEntry = null;
        foreach ($entries as $e) {
            foreach ($e->fieldValues as $fv) {
                if ($fv->field->slug === 'title' && str_contains((string) $fv->textValue, 'SUM')) {
                    $formulaEntry = $fv;
                }
            }
        }
        $this->assertNotNull($formulaEntry);
        // Formula injection prefix '=' escaped to "'=SUM(A1:A10)"
        $this->assertSame("'=SUM(A1:A10)", $formulaEntry->textValue);
    }

    public function testSynchronousJsonlImport(): void
    {
        $this->client->loginUser($this->adminUser);

        $jsonlPayload = "{\"title\": \"Smart Watch\", \"price\": \"89.50\", \"active\": true}\n" .
                        "{\"title\": \"Wireless Mouse\", \"price\": \"29.99\", \"active\": false}\n";

        $this->client->request('POST', sprintf('/api/projects/%s/batch/products/import', $this->project->uuid->toString()), [], [], [
            'CONTENT_TYPE' => 'application/x-ndjson',
        ], $jsonlPayload);

        $response = $this->client->getResponse();
        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame(2, $data['data']['created']);
        $this->assertEmpty($data['data']['errors']);
    }

    public function testJsonlImportHandlesMalformedLineGracefully(): void
    {
        $this->client->loginUser($this->adminUser);

        $jsonlPayload = "{\"title\": \"Valid Product\", \"price\": \"10.00\"}\n" .
                        "{MALFORMED_JSON_LINE\n" .
                        "{\"title\": \"Second Valid Product\", \"price\": \"20.00\"}\n";

        $this->client->request('POST', sprintf('/api/projects/%s/batch/products/import', $this->project->uuid->toString()), [], [], [
            'CONTENT_TYPE' => 'application/x-ndjson',
        ], $jsonlPayload);

        $this->assertSame(201, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame(2, $data['data']['created']);
        $this->assertCount(1, $data['data']['errors']);
        $this->assertSame(2, $data['data']['errors'][0]['row']);
    }

    public function testAsynchronousBatchImportAndStatusQuery(): void
    {
        $this->client->loginUser($this->adminUser);

        $csvPayload = "title,price\nAsync Item 1,15.00\nAsync Item 2,25.00";

        // Call with ?async=true
        $this->client->request('POST', sprintf('/api/projects/%s/batch/products/import?async=true', $this->project->uuid->toString()), [], [], [
            'CONTENT_TYPE' => 'text/csv',
        ], $csvPayload);

        $response = $this->client->getResponse();
        $this->assertSame(202, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertArrayHasKey('batch_id', $data['data']);
        $this->assertSame('pending', $data['data']['status']);

        $batchId = $data['data']['batch_id'];

        // Query status endpoint
        $this->client->request('GET', sprintf('/api/projects/%s/batch/%s/status', $this->project->uuid->toString(), $batchId));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $statusData = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame($batchId, $statusData['data']['batch_id']);
        $this->assertSame($this->project->uuid->toString(), $statusData['data']['project_uuid']);

        // Multi-tenant isolation test: otherProject cannot view this batch status!
        $this->client->request('GET', sprintf('/api/projects/%s/batch/%s/status', $this->otherProject->uuid->toString(), $batchId));
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
        $err = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('BATCH_NOT_FOUND', $err['code']);
    }

    public function testDecompressionBombRatioCheckOnZipArchive(): void
    {
        $this->client->loginUser($this->adminUser);

        // Create a ZIP with an artificially high compression ratio (sparse repetitive text)
        $zipPath = sys_get_temp_dir() . '/test_bomb_' . uniqid() . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        // 2MB of repeated zeros compresses down to few hundred bytes (> 100:1 ratio)
        $bombContent = str_repeat("0", 2 * 1024 * 1024);
        $zip->addFromString('data.csv', "title,price\n" . $bombContent);
        $zip->close();

        $uploadedFile = new UploadedFile($zipPath, 'test_bomb.zip', 'application/zip', null, true);

        $this->client->request('POST', sprintf('/api/projects/%s/batch/products/import', $this->project->uuid->toString()), [], [
            'file' => $uploadedFile,
        ]);

        @unlink($zipPath);

        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('IMPORT_ERROR', $data['code']);
        $this->assertTrue(
            str_contains($data['error'], 'ratio') ||
            str_contains($data['error'], 'bomb') ||
            str_contains($data['error'], 'exceeds limit')
        );
    }

    public function testZipSlipDirectoryTraversalRejection(): void
    {
        $this->client->loginUser($this->adminUser);

        $zipPath = sys_get_temp_dir() . '/test_slip_' . uniqid() . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('../../malicious.csv', "title,price\nItem,10");
        $zip->close();

        $uploadedFile = new UploadedFile($zipPath, 'test_slip.zip', 'application/zip', null, true);

        $this->client->request('POST', sprintf('/api/projects/%s/batch/products/import', $this->project->uuid->toString()), [], [
            'file' => $uploadedFile,
        ]);

        @unlink($zipPath);

        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('IMPORT_ERROR', $data['code']);
    }

    public function testDangerousFileExtensionInZipRejected(): void
    {
        $this->client->loginUser($this->adminUser);

        $zipPath = sys_get_temp_dir() . '/test_danger_' . uniqid() . '.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('exploit.php', '<?php echo "pwned";');
        $zip->close();

        $uploadedFile = new UploadedFile($zipPath, 'test_danger.zip', 'application/zip', null, true);

        $this->client->request('POST', sprintf('/api/projects/%s/batch/products/import', $this->project->uuid->toString()), [], [
            'file' => $uploadedFile,
        ]);

        @unlink($zipPath);

        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testBatchExportStreamingCsvAndJsonl(): void
    {
        $this->client->loginUser($this->adminUser);

        // Pre-create an entry
        $entry = new ContentEntry();
        $entry->project = $this->project;
        $entry->collection = $this->collection;
        $entry->slug = 'test-export-item';
        $entry->locale = 'en';
        $entry->status = 'published';
        $this->em->persist($entry);
        $this->em->flush();

        // 1. Export CSV
        ob_start();
        $this->client->request('GET', sprintf('/api/projects/%s/batch/products/export?format=csv', $this->project->uuid->toString()));
        $csvOutput = ob_get_clean();

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertTrue($response->headers->has('Content-Disposition'));

        // 2. Export JSONL
        ob_start();
        $this->client->request('GET', sprintf('/api/projects/%s/batch/products/export?format=jsonl', $this->project->uuid->toString()));
        $jsonlOutput = ob_get_clean();

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/x-ndjson', (string) $response->headers->get('Content-Type'));

        // 3. Invalid export format returns 422
        $this->client->request('GET', sprintf('/api/projects/%s/batch/products/export?format=xml', $this->project->uuid->toString()));
        $this->assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testBatchImportMessageConstructorValidation(): void
    {
        $validUuid = Uuid::v4()->toString();

        // 1. Invalid batchId
        $this->expectException(\InvalidArgumentException::class);
        new BatchImportMessage('', $validUuid, 'products', '/path/to/file.csv');
    }

    public function testBatchImportMessageInvalidUuidThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BatchImportMessage('batch-1', 'invalid-uuid', 'products', '/path/to/file.csv');
    }

    public function testBatchImportMessagePathTraversalThrows(): void
    {
        $validUuid = Uuid::v4()->toString();
        $this->expectException(\InvalidArgumentException::class);
        new BatchImportMessage('batch-1', $validUuid, 'products', '/path/../to/file.csv');
    }

    public function testBatchImportMessageHandlerExpiresStaleMessage(): void
    {
        $projectRepo = $this->createStub(ProjectRepository::class);
        $collRepo = $this->createStub(CollectionRepository::class);
        $importService = $this->client->getContainer()->get(ImportService::class);

        $handler = new BatchImportMessageHandler($projectRepo, $collRepo, $importService);

        $batchId = 'stale-test-' . bin2hex(random_bytes(3));
        $validUuid = Uuid::v4()->toString();

        // Message dispatched 4000 seconds ago (TTL is 3600)
        $message = new BatchImportMessage(
            batchId: $batchId,
            projectUuid: $validUuid,
            collectionSlug: 'products',
            filePath: sys_get_temp_dir() . '/dummy.csv',
            format: 'csv',
            dispatchedAt: time() - 4000,
            ttlSeconds: 3600,
        );

        $handler($message);

        $status = $importService->getBatchStatus($batchId, $validUuid);
        $this->assertNotNull($status);
        $this->assertSame('failed', $status['status']);
        $this->assertSame('BATCH_EXPIRED', $status['error_code']);
    }

    public function testStrictI18nParityAcrossAllLanguages(): void
    {
        $counts = [];
        $keys = [];
        foreach (['en', 'fr', 'es', 'ar'] as $lang) {
            $dict = require __DIR__ . "/../../../translations/messages.{$lang}.php";
            $counts[$lang] = count($dict);
            $keys[$lang] = array_keys($dict);
        }

        $this->assertSame($counts['en'], $counts['fr'], 'FR key count must match EN');
        $this->assertSame($counts['en'], $counts['es'], 'ES key count must match EN');
        $this->assertSame($counts['en'], $counts['ar'], 'AR key count must match EN');

        $this->assertEmpty(array_diff($keys['en'], $keys['fr']), 'FR must have all EN keys');
        $this->assertEmpty(array_diff($keys['en'], $keys['es']), 'ES must have all EN keys');
        $this->assertEmpty(array_diff($keys['en'], $keys['ar']), 'AR must have all EN keys');
    }
}
