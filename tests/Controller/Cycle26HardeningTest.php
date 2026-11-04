<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Command\PurgeExpiredTokensCommand;
use App\Controller\AdminApi\ImportController;
use App\Controller\Api\ProjectExportImportController;
use App\Controller\AuditController;
use App\Controller\DirectUploadController;
use App\Controller\NotificationController;
use App\Dto\ImportOptions;
use App\Entity\ApiToken;
use App\Entity\AssetUploadIntent;
use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Notification;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\ProjectStorageProfile;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use App\Repository\ApiTokenRepository;
use App\Repository\AssetUploadIntentRepository;
use App\Repository\AuditLogRepository;
use App\Repository\CollectionRepository;
use App\Repository\EndUserFieldRepository;
use App\Repository\FieldRepository;
use App\Repository\NotificationRepository;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\PersonalAccessTokenRepository;
use App\Repository\ProjectAuthAuthorizationCodeRepository;
use App\Repository\ProjectAuthRefreshTokenRepository;
use App\Repository\ProjectAuthSessionRepository;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Repository\ProjectStorageProfileRepository;
use App\Repository\ShareRepository;
use App\Service\ApiTokenChecker;
use App\Service\DirectUploadService;
use App\Service\EndUserSchemaSeeder;
use App\Service\ExportImport\Import\StructureImportHandler;
use App\Service\ExportImport\ProjectExporter;
use App\Service\ExportImport\ProjectImporter;
use App\Service\MediaSerializer;
use App\Service\StorageDriverFactory;
use Aws\S3\S3Client;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Uid\Uuid;

/**
 * Cycle 26 — Security Hardening Tests
 *
 * Covers:
 *  1. AdminApi/ImportController: payload size defense, strict column mapping validation, cell length capping, stream chunking.
 *  2. ProjectExportImportController & StructureImportHandler: archive size capping (50MB), manifest defense, strict column and field type validation.
 *  3. AuditController & NotificationController: strict tenant isolation (cross-project token blocking), bounded pagination, project notification partitioning.
 *  4. DirectUploadController: orphaned upload intent auto-expiration, cleanup endpoint, S3 multipart cancellation, and cron purge integration.
 */
class Cycle26HardeningTest extends TestCase
{
    private function createContainerWithUser(?User $user, array $parameters = []): Container
    {
        $container = new Container();
        $container->set('parameter_bag', new ParameterBag($parameters));

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        if ($user !== null) {
            $token = $this->createMock(TokenInterface::class);
            $token->method('getUser')->willReturn($user);
            $tokenStorage->method('getToken')->willReturn($token);
        } else {
            $tokenStorage->method('getToken')->willReturn(null);
        }
        $container->set('security.token_storage', $tokenStorage);

        $authChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturn(true);
        $container->set('security.authorization_checker', $authChecker);

        return $container;
    }

    // ─── 1. AdminApi/ImportController Hardening ──────────────────────────────

    public function testImportCsvRejectsOversizedPayloadWith413(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $collectionRepo = $this->createMock(CollectionRepository::class);
        $fieldRepo = $this->createMock(FieldRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $slugger = new AsciiSlugger();

        $controller = new ImportController($projectRepo, $collectionRepo, $fieldRepo, $em, $slugger);
        $admin = new User();
        $admin->email = 'admin@jambo.local';
        $admin->roles = ['ROLE_SUPER_ADMIN'];
        $controller->setContainer($this->createContainerWithUser($admin));

        $pat = new \App\Entity\PersonalAccessToken();
        $pat->scopes = ['*'];

        // Request with Content-Length header exceeding 10MB
        $request = Request::create(
            '/admin-api/projects/proj-123/collections/articles/import-csv',
            'POST',
            [],
            [],
            [],
            ['HTTP_CONTENT_LENGTH' => (string) (ImportController::MAX_PAYLOAD_BYTES + 1024)],
            json_encode(['data' => []])
        );
        $request->attributes->set('_pat', $pat);

        $response = $controller->importCsv('proj-123', 'articles', $request);
        $this->assertSame(413, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('Payload too large', $data['error']);
    }

    public function testImportCsvRejectsUnknownTargetFieldsInMapping(): void
    {
        $project = new Project();
        $project->uuid = Uuid::v4();
        $project->defaultLocale = 'en';

        $collection = new Collection();
        $collection->slug = 'posts';
        $collection->project = $project;

        $titleField = new Field();
        $titleField->slug = 'title';
        $titleField->type = 'text';

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $collectionRepo = $this->createMock(CollectionRepository::class);
        $collectionRepo->method('findOneBy')->willReturn($collection);

        $fieldRepo = $this->createMock(FieldRepository::class);
        $fieldRepo->method('findBy')->willReturn([$titleField]);

        $em = $this->createMock(EntityManagerInterface::class);
        $slugger = new AsciiSlugger();

        $controller = new ImportController($projectRepo, $collectionRepo, $fieldRepo, $em, $slugger);
        $admin = new User();
        $admin->email = 'admin@jambo.local';
        $admin->roles = ['ROLE_SUPER_ADMIN'];
        $controller->setContainer($this->createContainerWithUser($admin));

        // Mapping references non-existent target field "unknown_field"
        $payload = [
            'data' => [
                ['csv_title' => 'Article 1', 'csv_danger' => 'malicious payload'],
            ],
            'field_mapping' => [
                'csv_title' => 'title',
                'csv_danger' => 'unknown_field',
            ],
        ];

        $pat = new \App\Entity\PersonalAccessToken();
        $pat->scopes = ['*'];

        $request = Request::create(
            '/admin-api/projects/' . $project->uuid->toRfc4122() . '/collections/posts/import-csv',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload)
        );
        $request->attributes->set('_pat', $pat);

        $response = $controller->importCsv($project->uuid->toRfc4122(), 'posts', $request);
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('n\'existe pas dans la collection', $data['error']);
    }

    public function testImportCsvRejectsExcessiveMappedColumns(): void
    {
        $project = new Project();
        $project->uuid = Uuid::v4();
        $project->defaultLocale = 'en';

        $collection = new Collection();
        $collection->slug = 'products';
        $collection->project = $project;

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);
        $collectionRepo = $this->createMock(CollectionRepository::class);
        $collectionRepo->method('findOneBy')->willReturn($collection);
        $fieldRepo = $this->createMock(FieldRepository::class);
        $fieldRepo->method('findBy')->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $slugger = new AsciiSlugger();

        $controller = new ImportController($projectRepo, $collectionRepo, $fieldRepo, $em, $slugger);
        $admin = new User();
        $admin->email = 'admin@jambo.local';
        $admin->roles = ['ROLE_SUPER_ADMIN'];
        $controller->setContainer($this->createContainerWithUser($admin));

        // 101 mapped columns (exceeding MAX_COLUMNS=100)
        $mapping = [];
        for ($i = 0; $i <= 100; $i++) {
            $mapping["col_$i"] = "field_$i";
        }

        $payload = [
            'data' => [['col_0' => 'val']],
            'field_mapping' => $mapping,
        ];

        $pat = new \App\Entity\PersonalAccessToken();
        $pat->scopes = ['*'];

        $request = Request::create(
            '/admin-api/projects/' . $project->uuid->toRfc4122() . '/collections/products/import-csv',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload)
        );
        $request->attributes->set('_pat', $pat);

        $response = $controller->importCsv($project->uuid->toRfc4122(), 'products', $request);
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('dépasse la limite autorisée', $data['error']);
    }

    // ─── 2. ProjectExportImportController & StructureImportHandler Hardening ──

    public function testProjectImportRejectsOversizedZipFile(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $exporter = $this->createMock(ProjectExporter::class);
        $importer = $this->createMock(ProjectImporter::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $seeder = $this->createMock(EndUserSchemaSeeder::class);

        $controller = new ProjectExportImportController($projectRepo, $exporter, $importer, $em, $seeder);
        $user = new User();
        $user->email = 'test@jambo.local';
        $controller->setContainer($this->createContainerWithUser($user));

        // Create temporary fake file exceeding 50MB
        $fakeFile = $this->createMock(UploadedFile::class);
        $fakeFile->method('isValid')->willReturn(true);
        $fakeFile->method('getSize')->willReturn(ProjectExportImportController::MAX_ARCHIVE_BYTES + 1024);
        $fakeFile->method('getClientOriginalExtension')->willReturn('zip');
        $fakeFile->method('getClientMimeType')->willReturn('application/zip');

        $request = new Request([], ['new_project_name' => 'Big Project'], [], [], ['file' => $fakeFile]);

        $response = $controller->importNew($request);
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('maximum allowed size of 50MB', $data['error']);
    }

    public function testProjectImportRejectsNonZipFiles(): void
    {
        $projectRepo = $this->createMock(ProjectRepository::class);
        $exporter = $this->createMock(ProjectExporter::class);
        $importer = $this->createMock(ProjectImporter::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $seeder = $this->createMock(EndUserSchemaSeeder::class);

        $controller = new ProjectExportImportController($projectRepo, $exporter, $importer, $em, $seeder);
        $user = new User();
        $controller->setContainer($this->createContainerWithUser($user));

        $fakeFile = $this->createMock(UploadedFile::class);
        $fakeFile->method('isValid')->willReturn(true);
        $fakeFile->method('getSize')->willReturn(1024);
        $fakeFile->method('getClientOriginalExtension')->willReturn('exe');
        $fakeFile->method('getClientMimeType')->willReturn('application/x-msdownload');

        $request = new Request([], ['new_project_name' => 'Bad Project'], [], [], ['file' => $fakeFile]);

        $response = $controller->importNew($request);
        $this->assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('Only ZIP archives are permitted', $data['error']);
    }

    public function testStructureImportHandlerRejectsInvalidFieldTypes(): void
    {
        $endUserFieldRepo = $this->createMock(EndUserFieldRepository::class);
        $handler = new StructureImportHandler($endUserFieldRepo);

        $tempDir = sys_get_temp_dir() . '/test_struct_' . bin2hex(random_bytes(4));
        mkdir($tempDir, 0700, true);

        $badStructure = [
            'collections' => [
                [
                    'name'   => 'Articles',
                    'slug'   => 'articles',
                    'fields' => [
                        [
                            'name' => 'Script Field',
                            'slug' => 'bad_field',
                            'type' => 'malicious_exec_type', // Invalid type
                        ],
                    ],
                ],
            ],
        ];
        file_put_contents($tempDir . '/structure.json', json_encode($badStructure));

        $project = new Project();
        $options = new ImportOptions();
        $uuidMap = [];

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Invalid field type "malicious_exec_type"');
            $handler->import($project, $tempDir, $options, $uuidMap);
        } finally {
            @unlink($tempDir . '/structure.json');
            @rmdir($tempDir);
        }
    }

    public function testStructureImportHandlerRejectsMalformedSlugs(): void
    {
        $endUserFieldRepo = $this->createMock(EndUserFieldRepository::class);
        $handler = new StructureImportHandler($endUserFieldRepo);

        $tempDir = sys_get_temp_dir() . '/test_struct_slug_' . bin2hex(random_bytes(4));
        mkdir($tempDir, 0700, true);

        $badStructure = [
            'collections' => [
                [
                    'name'   => 'Collection With Space',
                    'slug'   => 'bad slug with spaces & symbols!',
                    'fields' => [],
                ],
            ],
        ];
        file_put_contents($tempDir . '/structure.json', json_encode($badStructure));

        $project = new Project();
        $options = new ImportOptions();
        $uuidMap = [];

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Invalid collection slug format');
            $handler->import($project, $tempDir, $options, $uuidMap);
        } finally {
            @unlink($tempDir . '/structure.json');
            @rmdir($tempDir);
        }
    }

    // ─── 3. AuditController & NotificationController Tenant Hardening ───────

    public function testAuditControllerRejectsCrossProjectTokenAccessWith403(): void
    {
        $projectA = new Project();
        $projectA->uuid = Uuid::fromString('00000000-0000-0000-0000-000000000001');

        $projectB = new Project();
        $projectB->uuid = Uuid::fromString('00000000-0000-0000-0000-000000000002');

        $em = $this->createMock(EntityManagerInterface::class);
        $projectRepo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $projectRepo->method('findOneBy')->willReturn($projectA);
        $em->method('getRepository')->willReturn($projectRepo);

        $auditRepo = $this->createMock(AuditLogRepository::class);
        $tokenChecker = $this->createMock(ApiTokenChecker::class);

        $controller = new AuditController($em, $auditRepo, $tokenChecker);
        $controller->setContainer($this->createContainerWithUser(null));

        // Request has _project attribute belonging to Project B, but queries Project A
        $request = Request::create('/api/projects/' . $projectA->uuid->toRfc4122() . '/audit-logs');
        $request->attributes->set('_project', $projectB);

        $response = $controller->list($projectA->uuid->toRfc4122(), $request);
        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('belongs to another project', $data['error']);
    }

    public function testAuditControllerRejectsTokenMissingReadAbilityWith403(): void
    {
        $project = new Project();
        $project->uuid = Uuid::fromString('00000000-0000-0000-0000-000000000003');

        $em = $this->createMock(EntityManagerInterface::class);
        $projectRepo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);
        $em->method('getRepository')->willReturn($projectRepo);

        $auditRepo = $this->createMock(AuditLogRepository::class);

        // Token with create-only ability, missing read
        $token = new ApiToken();
        $token->project = $project;
        $token->abilities = ['create'];

        $tokenChecker = $this->createMock(ApiTokenChecker::class);
        $tokenChecker->method('resolve')->willReturn($token);

        $controller = new AuditController($em, $auditRepo, $tokenChecker);
        $controller->setContainer($this->createContainerWithUser(null));

        $request = Request::create('/api/projects/' . $project->uuid->toRfc4122() . '/audit-logs');
        $response = $controller->list($project->uuid->toRfc4122(), $request);

        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('read ability', $data['error']);
    }

    public function testAuditControllerBoundsPaginationLimits(): void
    {
        $project = new Project();
        $project->uuid = Uuid::fromString('00000000-0000-0000-0000-000000000004');

        $em = $this->createMock(EntityManagerInterface::class);
        $projectRepo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);
        $em->method('getRepository')->willReturn($projectRepo);

        $auditRepo = $this->createMock(AuditLogRepository::class);
        $auditRepo->expects($this->once())
            ->method('findByProject')
            ->with(
                $project,
                $this->equalTo(AuditController::MAX_PAGE_LIMIT), // Bounded from 99999 to 100
                $this->equalTo(AuditController::MAX_OFFSET)      // Bounded from 999999 to 10000
            )
            ->willReturn([]);

        $auditRepo->method('countByProjectToday')->willReturn(0);

        $admin = new User();
        $admin->email = 'admin@jambo.local';
        $admin->roles = ['ROLE_SUPER_ADMIN'];

        $controller = new AuditController($em, $auditRepo, null);
        $controller->setContainer($this->createContainerWithUser($admin));

        $request = Request::create('/api/projects/' . $project->uuid->toRfc4122() . '/audit-logs?limit=99999&offset=999999');
        $response = $controller->list($project->uuid->toRfc4122(), $request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testNotificationControllerRejectsUnauthorizedProjectFilterWith403(): void
    {
        $user = new User();
        $user->roles = ['ROLE_USER'];

        $unauthorizedProject = new Project();
        $unauthorizedProject->uuid = Uuid::fromString('00000000-0000-0000-0000-000000000005');

        $notifRepo = $this->createMock(NotificationRepository::class);
        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($unauthorizedProject);

        // Member repo returns null (user is not an active member of this project)
        $memberRepo = $this->createMock(ProjectMemberRepository::class);
        $memberRepo->method('findActiveByUserAndProject')->willReturn(null);

        $controller = new NotificationController($notifRepo, null, $projectRepo, $memberRepo);
        $controller->setContainer($this->createContainerWithUser($user));

        $request = Request::create('/api/notifications?project=' . $unauthorizedProject->uuid->toRfc4122());
        $response = $controller->index($request);

        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('Forbidden', $data['error']);
    }

    public function testNotificationControllerFiltersByProjectForActiveMember(): void
    {
        $user = new User();
        $user->roles = ['ROLE_USER'];

        $project = new Project();
        $project->uuid = Uuid::fromString('00000000-0000-0000-0000-000000000006');

        $member = new ProjectMember();
        $member->project = $project;
        $member->user = $user;
        $member->status = ProjectMemberStatus::Active;

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);
        $memberRepo = $this->createMock(ProjectMemberRepository::class);
        $memberRepo->method('findActiveByUserAndProject')->willReturn($member);

        $notif = new Notification();
        $notif->title = 'Test Project Notification';
        $notif->link = '/projects/' . $project->uuid->toRfc4122() . '/entries/1';
        $notif->recipient = $user;

        $notifRepo = $this->createMock(NotificationRepository::class);
        $notifRepo->expects($this->once())
            ->method('findByRecipientAndProjectPaginated')
            ->with($user, $project, 1, 20, false)
            ->willReturn([$notif]);
        $notifRepo->method('countUnreadByUserAndProject')->willReturn(1);

        $controller = new NotificationController($notifRepo, null, $projectRepo, $memberRepo);
        $controller->setContainer($this->createContainerWithUser($user));

        $request = Request::create('/api/notifications?project=' . $project->uuid->toRfc4122());
        $response = $controller->index($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertCount(1, $data['data']);
        $this->assertSame('Test Project Notification', $data['data'][0]['title']);
        $this->assertSame(1, $data['meta']['unread_count']);
    }

    // ─── 4. DirectUploadController & Orphaned Intent Expiration / Purge ─────

    public function testDirectUploadCleanupOrphansEndpoint(): void
    {
        $project = new Project();
        $project->uuid = Uuid::fromString('00000000-0000-0000-0000-000000000007');

        $projectRepo = $this->createMock(ProjectRepository::class);
        $projectRepo->method('findOneBy')->willReturn($project);

        $intentRepo = $this->createMock(AssetUploadIntentRepository::class);
        $directUploadService = $this->createMock(DirectUploadService::class);
        $directUploadService->expects($this->once())
            ->method('cleanupExpiredIntents')
            ->with($project, 50)
            ->willReturn(3);

        $mediaSerializer = $this->createMock(MediaSerializer::class);

        $controller = new DirectUploadController(
            $projectRepo,
            $intentRepo,
            $directUploadService,
            $mediaSerializer,
            null
        );

        $admin = new User();
        $admin->email = 'admin@jambo.local';
        $admin->roles = ['ROLE_SUPER_ADMIN'];
        $controller->setContainer($this->createContainerWithUser($admin));

        $request = Request::create('/api/projects/' . $project->uuid->toRfc4122() . '/media/direct-upload/cleanup-orphans', 'POST');
        $response = $controller->cleanupOrphans($project->uuid->toRfc4122(), $request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        $this->assertSame('success', $data['status']);
        $this->assertSame(3, $data['cleaned_count']);
    }

    public function testDirectUploadServiceCleanupExpiredIntentsAbortsMultipartAndMarksExpired(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $storageDriverFactory = $this->createMock(StorageDriverFactory::class);
        $storageProfileRepo = $this->createMock(ProjectStorageProfileRepository::class);
        $intentRepo = $this->createMock(AssetUploadIntentRepository::class);

        $profile = new ProjectStorageProfile();
        $profile->driver = 's3';
        $profile->s3Bucket = 'test-bucket';

        $s3Client = $this->createMock(S3Client::class);
        $s3Client->expects($this->once())
            ->method('__call')
            ->with('abortMultipartUpload', $this->callback(function (array $args) {
                return $args[0]['Bucket'] === 'test-bucket'
                    && $args[0]['Key'] === 'orphaned/key.mp4'
                    && $args[0]['UploadId'] === 's3-upload-id-999';
            }))
            ->willReturn(null);

        $storageDriverFactory->method('getS3Client')->willReturn($s3Client);

        $expiredIntent = new AssetUploadIntent();
        $expiredIntent->mode = AssetUploadIntent::MODE_MULTIPART;
        $expiredIntent->status = AssetUploadIntent::STATUS_PENDING;
        $expiredIntent->storageKey = 'orphaned/key.mp4';
        $expiredIntent->s3MultipartUploadId = 's3-upload-id-999';
        $expiredIntent->storageProfile = $profile;
        $expiredIntent->expiresAt = (new \DateTimeImmutable())->modify('-1 hour');

        $intentRepo->method('findExpiredPending')->willReturn([$expiredIntent]);
        $em->expects($this->once())->method('flush');

        $service = new DirectUploadService(
            $em,
            $storageDriverFactory,
            $storageProfileRepo,
            null,
            $intentRepo
        );

        $count = $service->cleanupExpiredIntents();
        $this->assertSame(1, $count);
        $this->assertSame(AssetUploadIntent::STATUS_EXPIRED, $expiredIntent->status);
    }

    public function testPurgeExpiredTokensCommandCleansUploadIntents(): void
    {
        $resetRepo = $this->createMock(PasswordResetTokenRepository::class);
        $patRepo = $this->createMock(PersonalAccessTokenRepository::class);
        $apiRepo = $this->createMock(ApiTokenRepository::class);
        $refreshRepo = $this->createMock(ProjectAuthRefreshTokenRepository::class);
        $codeRepo = $this->createMock(ProjectAuthAuthorizationCodeRepository::class);
        $sessionRepo = $this->createMock(ProjectAuthSessionRepository::class);
        $shareRepo = $this->createMock(ShareRepository::class);

        $intentRepo = $this->createMock(AssetUploadIntentRepository::class);
        $intentRepo->expects($this->once())->method('deleteOldIntents')->willReturn(8);

        $directUploadService = $this->createMock(DirectUploadService::class);
        $directUploadService->expects($this->once())->method('cleanupExpiredIntents')->willReturn(4);

        $command = new PurgeExpiredTokensCommand(
            $resetRepo,
            $patRepo,
            $apiRepo,
            $refreshRepo,
            $codeRepo,
            $sessionRepo,
            $shareRepo,
            $intentRepo,
            $directUploadService
        );

        $tester = new CommandTester($command);
        $status = $tester->execute([]);

        $this->assertSame(0, $status);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Expired upload intents cleaned up: 4', $output);
        $this->assertStringContainsString('Old upload intents purged (> 30d): 8', $output);
    }
}
